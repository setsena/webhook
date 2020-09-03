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
//我们就吃吃喝喝 轻轻松松过国庆
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