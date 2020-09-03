/** create time : 2020-09-03 20:37:16 */
<?php

/**
 * Class Worker
 * @see https://blog.csdn.net/weixin_42075590/article/details/80740968
 */
class Deamon
{


    public static $log_file = '';

    //将master进程id保存到这个文件中
    public static $pid_file = '';

    //保存worker进程的状态
    public static $status_file = '';

    //记录当前进程的状态
    public static $status = 0;

    //运行中
    const STATUS_RUNNING = 1;
    //停止
    const STATUS_SHUTDOWN = 2;


    //是否使用守护进程模式启动
    public static $deamonize = false;

    public static $master_pid = 0;

    public static $stdoutFile = '/dev/null';

    public static $workers = [];


    private static $workerStock = [];

    public static  function addWorker($title,$callback)
    {
        static::$workerStock[] = ['title'=>$title,'callback'=>$callback];
    }

    //worker实例
    /** @var Deamon */
    public static $instance = null;

    //worker数量
    public $count = 2;

    //worker启动时的回调方法
    public $onWorkerStart = null;

    public function __construct()
    {
        static::$instance = $this;
    }




    public static function runAll()
    {
        static::checkEnv();
        //static::init();
        static::parseCommand();
        static::deamonize();
        static::saveMasterPid();
        //static::installSignal();
        static::resetStd();
        static::log('master start');
        static::forkWorkers();
        static::monitorWorkers();

    }

    public static function log($message)
    {
        $message = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        echo $message;
    }

    public static function setProcessTitle($title)
    {
        //设置进程名
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }

    public static function checkEnv()
    {

        if (php_sapi_name() != 'cli') {
            exit('请使用命令行模式运行!');
        }
        if (!function_exists('posix_kill')) {
            exit('请先安装posix扩展' . "\n");
        }
        if (!function_exists('pcntl_fork')) {
            exit('请先安装pcntl扩展' . "\n");
        }
    }


    public static function processAlive($pid)
    {
        //向master进程发送0信号，0信号比较特殊，进程不会响应，但是可以用来检测进程是否存活
        return $pid && posix_kill($pid, 0);
    }
    public static function parseCommand()
    {
        global $argv;

        if (!isset($argv[1]) || !in_array($argv[1], ['start', 'stop', 'status'])) {
            exit('usage: php your.php start | stop | status !' . PHP_EOL);
        }

        $command1 = $argv[1]; //start , stop , status
        $command2 = isset($argv[2]) ? $argv[2] : null; // -d

        //检测master是否正在运行
        $master_id = @file_get_contents(static::$pid_file);
        $master_alive = $master_id && self::processAlive($master_id);

        switch ($command1) {
            case 'start':

                if($master_alive && posix_getpid() != $master_id) {
                    exit('worker is already running !' . PHP_EOL);
                }else{
                    @unlink(static::$pid_file);//进程无效时清除文件
                }

                if ($command2 == '-d') {
                    static::$deamonize = true;
                }

                break;
            case 'stop':

                if(!$master_alive){
                    exit('process not run!' . PHP_EOL);
                }else{

                    //停止进程
                    $master_id && posix_kill($master_id, SIGINT);
                    //只要还没杀死master，就一直杀
                    while ($master_id && self::processAlive($master_id)) {
                        usleep(300000);
                    }
                    exit('process stopped!'.PHP_EOL);
                }


                break;
            case 'status':

                if(!$master_alive){
                    exit('process not run!' . PHP_EOL);
                }else{
                    exit('process running!' . PHP_EOL);
                }

                break;
            default:
                exit('usage: php your.php start | stop | status !' . PHP_EOL);
                break;
        }

    }

    public static function deamonize()
    {

        if (static::$deamonize == false) {
            return;
        }

        umask(0);

        $pid = pcntl_fork();

        if ($pid > 0) {
            exit(0);
        } elseif ($pid == 0) {
            if (-1 === posix_setsid()) {
                throw new Exception("setsid fail");
            }
            static::setProcessTitle('php myworker: master');
        } else {
            throw new Exception("fork fail");
        }
    }

    public static function saveMasterPid()
    {
        static::$master_pid = posix_getpid();
        if (false === @file_put_contents(static::$pid_file, static::$master_pid)) {
            throw new Exception('fail to save master pid ');
        }
    }


    public static function installSignal()
    {
        pcntl_signal(SIGINT, array(__CLASS__, 'signalHandler'), false);// -2 ctrl+c
        #pcntl_signal(SIGUSR2, array(__CLASS__, 'signalHandler'), false);
        //SIG_IGN表示忽略该信号，不做任何处理。SIGPIPE默认会使进程退出
        pcntl_signal(SIGPIPE, SIG_IGN, false);// -9
    }

    /*
     * Deamon模式 所有输入输出都写进黑洞
     */
    public static function resetStd()
    {
        if (static::$deamonize == false) {
            return;
        }
        if(static::$stdoutFile != '/dev/null'){
            if(!is_dir(dirname(self::$stdoutFile))){
                exit("std out dir ".dirname(self::$stdoutFile)." not exists".PHP_EOL);
            }
            /*if(!is_file(self::$stdoutFile)){
                touch(self::$stdoutFile);
            }*/
        }
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile, "a");
            $STDERR = fopen(self::$stdoutFile, "a");
        } else {
            throw new Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }

    public static function forkWorkers()
    {
        if(count(static::$workerStock)==0){
            echo "没有可生成的worker 进程结束".PHP_EOL;
            die();
        }
        foreach(static::$workerStock as $mate){
            self::forkOneWorker($mate);
        }


    }

    public static function forkOneWorker($mate)
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            static::$workers[$pid] = $mate;
        } elseif ($pid == 0) {
            static::log('create process : '.$mate['title']);
            static::setProcessTitle('php myworker '.$mate['title']);
            //运行
            call_user_func($mate['callback']);
        } else {
            throw new Exception('fork one worker fail');
        }
    }

    public static function monitorWorkers()
    {
        //设置当前状态为运行中
        static::$status = static::STATUS_RUNNING;

        self::installSignal();

        while (1) {
            pcntl_signal_dispatch();
            $status = 0;
            //阻塞，等待子进程退出
            $pid = pcntl_wait($status, WUNTRACED);

            self::log("worker[ $pid ] exit with signal:" . pcntl_wstopsig($status));

            pcntl_signal_dispatch();
            //child exit
            if ($pid > 0) {
                //意外退出时才重新fork，如果是我们想让worker退出，status = STATUS_SHUTDOWN
                //TODO return
                if (static::$status != static::STATUS_SHUTDOWN) {
                    $mate = static::$workers[$pid];
                    unset(static::$workers[$pid]);
                    static::forkOneWorker($mate);
                }
            }
        }
    }

    public static function signalHandler($signal)
    {
        switch ($signal) {
            case SIGINT: // Stop.
                static::stopAll();
                break;
        }

    }


    public static function stopAll()
    {

        $pid = posix_getpid();

        if ($pid == static::$master_pid) { //master进程
            //将当前状态设为停止，否则子进程一退出master重新fork
            static::$status = static::STATUS_SHUTDOWN;
            //通知子进程退出
            foreach (static::$workers as $pid => $v) {
                posix_kill($pid, SIGINT);
            }
            //删除pid文件
            @unlink(static::$pid_file);
            exit(0);
        } else { //worker进程
            static::log('worker[' . $pid . '] stop');
            exit(0);
        }

    }




}
?>
<?php


class SimpleHttpServer
{
    private $config = [];

    private $server_sock = null;

    public $accept;

    public $access_log = null;

    public function __construct()
    {
        $this->config['request_max_length'] = 1024*1024*1024*5;//5MB
        $this->config['ip'] = '127.0.0.1';
        $this->config['port'] = '8080';
    }

    public function log($str)
    {
        echo date('Y-m-d H:i:s ').$str.PHP_EOL;
    }

    public function listen($ip,$port)
    {
        $this->config['ip'] = $ip;
        $this->config['port'] = $port;
    }

    public function run()
    {
        $this->server_sock = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
        socket_set_option($this->server_sock,SOL_SOCKET,SO_REUSEADDR,true);
        socket_bind($this->server_sock,$this->config['ip'],$this->config['port']);
        socket_listen($this->server_sock);


        //进入主循环
        while(true){
            $client_sock = socket_accept($this->server_sock);
            $client_ip='';
            $client_port='';
            $client_opt = socket_getpeername($client_sock,$client_ip,$client_port);

            $read_length = 0;
            $block_length = 2048;
            $header_end = false;
            $http_request = '';
            $http_header = '';
            $header_end_pos = 0;
            $http_end  = false;
            $header = [];
            $content_length = 0;
            $total_length = $this->config['request_max_length'];
            $uri = '';
            while($read_length<min($total_length,$this->config['request_max_length'])){

                $res = socket_read($client_sock,$block_length);
                #echo 'read length : '.strlen($res).PHP_EOL;

                if(empty($res)){
                    $http_end = true;
                }
                $http_request.= $res;
                $read_length += strlen($res);
                //第一次读到CRLF
                if(!$header_end && is_numeric(strpos($res,"\r\n\r\n"))){

                    $header_end = true;
                    $header_end_pos = strpos($res,"\r\n\r\n");
                    $http_header = substr($http_request,0,$header_end_pos);
                    $http_request = substr($http_request,$header_end_pos+4);
                    #echo '[HEAD]'.strlen($http_header).PHP_EOL.$http_header.PHP_EOL;



                    //分析头部
                    $arr1 = explode("\r\n",$http_header);
                    $uri = $arr1[0];
                    foreach($arr1 as $k => $item){
                        $del_pos = strpos($item,':');
                        if(is_numeric($del_pos)){
                            $key = trim(substr($item,0,$del_pos));
                            $value = trim(substr($item,$del_pos+1));
                            $header[$key] = $value;
                            if(strtolower($key)=='content-length'){
                                $content_length = intval($value);
                                $total_length = strlen($http_header)+4+$content_length;
                            }
                        }
                    }

                    # print_r($header);


                }

                //放在这里是因为 header可能超过一次缓存读取（2048）大小
                if($header_end && $content_length==0){
                    $http_end = true;

                }else if($content_length!=0 && strlen($http_request)>=$content_length){
                    $http_end = true;
                }

                if($http_end)
                    break;
            }

            if($this->access_log!=null){
                $msg =  "client {$client_ip}:{$client_port} ".$uri.PHP_EOL;
                file_put_contents($this->access_log,$msg,FILE_APPEND);
            }


            #echo '[BODY]'.strlen($http_request).PHP_EOL.$http_request.PHP_EOL;

            //TODO 业务逻辑
            $param = [$header,$http_request];
            call_user_func($this->accept,$header,$http_request);

            //Resposne

            $t = time();
            //$pid = posix_getpid();
            $html = "<html><body><h1>Hook.{$t}</h1></html>";
            $this->responseHtml($client_sock,$html);

            socket_close($client_sock);


        }



        socket_close($this->server_sock);
    }


    public function response200($socket)
    {
        $response = "HTTP/1.1 200 OK
Content-Type: text/html; charset=\"utf-8\"
Connection: close
Server: AsusWRT/380.70 UPnP/1.1 MiniUPnPd/2.0

";
        socket_write($socket,$response);
    }

    public function responseHtml($socket,$html)
    {
        $html_len = strlen($html);
        $response = "HTTP/1.1 200 OK
Content-Type: text/html; charset=\"utf-8\"
Content-Length: {$html_len}
Connection: close
Server: AsusWRT/380.70 UPnP/1.1 MiniUPnPd/2.0

".$html;
        socket_write($socket,$response);
    }


    public function set_config($k,$v)
    {
        $this->config[$k] = $v;
    }


}

?>
<?php


$config_path = 'conf.json';

$config = json_decode(file_get_contents($config_path), true);

$master_id = !empty($config['deamon']['master_pid']) ? $config['deamon']['master_pid'] : "master.pid";
$access_log = !empty($config['service']['access_log']) ? $config['service']['access_log'] : 'access.log';
$webhook_log = !empty($config['webhook_log']) ? $config['webhook_log'] : 'webhook.log';

$listen_ip = $config['service']['listen_ip'];
$listen_port = $config['service']['listen_port'];
$repositories = $config['repositories'];


$mk = ftok(__FILE__, 'a');//文件必须存在

$deamon = new Deamon();
$deamon::$pid_file = $master_id;
$deamon::$stdoutFile = $webhook_log;

if (0) {
    $deamon->addWorker('service', function () {
        $pid = posix_getpid();
        while (true) {
            Deamon::log( "2 {$pid} running." );
            sleep(mt_rand(3, 4));
            pcntl_signal_dispatch();
        }
    });

    $deamon->addWorker('worker ', function () {
        $pid = posix_getpid();
        while (true) {
            Deamon::log( "1 {$pid} running." );
            sleep(mt_rand(3, 4));
            pcntl_signal_dispatch();
        }
    });
}

if (1) {
    $deamon->addWorker('HTTP Server', function () use ($mk, $listen_port,$listen_ip,$access_log) {
        $queue = msg_get_queue($mk, 0666);
        $pid = posix_getpid();



        $server = new SimpleHttpServer();
        $server->access_log = $access_log;

        $server->listen($listen_ip, $listen_port);
        $server->accept = function ($header, $raw) use ($queue) {
            #print_r($request);
            $data = json_decode($raw, true);
            if (empty($data))
                return;

            $repos_name = isset($data['repository']['full_name']) ? $data['repository']['full_name'] : '';
            if (!empty($repos_name))
                msg_send($queue, 1, $repos_name);


            //file_put_contents('access.log',$raw.time().PHP_EOL,FILE_APPEND);
        };
        Deamon::log("HTTP Server Listen at {$listen_ip}:{$listen_port}");
        $server->run();

    });


    $deamon->addWorker('Hook Handler ', function () use ($mk, $repositories) {
        $queue = msg_get_queue($mk, 0666);
        $pid = posix_getpid();


        while (true) {
            msg_receive($queue, 1, $type, 1024, $msg);

            Deamon::log('Handler Get Msg : ' . $msg);

            $repos_name = $msg;

            if (isset($repositories[$repos_name])) {
                if (is_dir($repositories[$repos_name])) {
                    $out = shell_exec("git -C {$repositories[$repos_name]} pull");

                    Deamon::log('Handler Git Exec :'.PHP_EOL.$out);
                }else {
                    Deamon::log($repositories[$repos_name].' not exists');
                }
            }

            if ($msg == 'die') {
                echo 'child die' . PHP_EOL;
                exit();
            }
        }

    });


}
Deamon::runAll();
?>