<?php

$deamon = file_get_contents("Deamon.php");
$simpleHttpServer = file_get_contents("SimpleHttpServer.php");
$main = file_get_contents("main.php");


$t = date("Y-m-d H:i:s");
$out = "/** create time : {$t} */";


$php_text = $out.PHP_EOL.$deamon.PHP_EOL.$simpleHttpServer.PHP_EOL.$main;

$out_file = "../webhook.php";

if(file_exists($out_file)){
    echo $out_file.' file exists. Delete it'.PHP_EOL;
    unlink($out_file);

}

file_put_contents($out_file,$php_text);
if(file_exists($out_file)){
    echo 'build success !'.PHP_EOL.'Please run "php webhook.php "'.PHP_EOL;
}