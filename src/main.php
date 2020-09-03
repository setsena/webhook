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