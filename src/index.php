<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/21
 * Time: 9:32
 */

$CFG=require_once "config.php";
require_once "../vendor/autoload.php";

$master=new Master("task_queque");
$master->run();
