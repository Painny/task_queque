<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/21
 * Time: 9:32
 */

require_once "./vendor/autoload.php";

$CFG= require_once "./src/config.php";

$master=new Master("task_queue");
