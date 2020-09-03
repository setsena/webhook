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