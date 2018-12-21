<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/21
 * Time: 9:32
 */

require_once "helper.php";
$CFG=require_once "config.php";

$pid=pcntl_fork();
if($pid == 0){  //子进程
    echo "子：".config("log","file");
    exit();
}else{  //父进程
    echo "父:".config("log","path");
}