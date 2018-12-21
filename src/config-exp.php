<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/20
 * Time: 17:57
 */

return array(

    "redis" =>  array(
        "host"  =>  "127.0.0.1",
        "port"  =>  "6379",
        "passwd"=>  "",
        "db"    =>  4
    ),

    "mysql" =>  array(
        "host"  =>  "127.0.0.1",
        "port"  =>  "3306",
        "db"    =>  "db",
        "user"  =>  "root",
        "passwd"=>  "root"
    ),

    'mail'  =>  array(
        "driver"    =>  "smtp",
        "host"      =>  "smtp.exmail.qq.com",
        "port"      =>  465,
        "username"  =>  "service@ervice.com",
        "passwd"    =>  "",
        "encryption"=>  "ssl"
    ),

    "log"   =>  array(
        "path"  =>  realpath(dirname(__DIR__)).DIRECTORY_SEPARATOR."log",
        "file"  =>  "worklog.log",
        "fmt"   =>  "[time] [type] [msg] [code]:in [line] line"
    ),

    "task"  =>  array(
        //任务列表redis的key
        "list"  =>   "task_list",
        //任务类型
        "type"  =>  array(
            //生成学生付款码
            "make_pay_code",

            //生成教师授权码
            "make_teacher_code"
        )
    )
);