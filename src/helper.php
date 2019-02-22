<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/20
 * Time: 18:06
 */

//获取配置参数
function config($block,$key)
{
    global $CFG;
    if(!isset($CFG[$block][$key])){
        return null;
    }
    return $CFG[$block][$key];
}

//日志记录
function logging($type,Exception $exception)
{
    $pattern=array("/\[time\]/","/\[type\]/","/\[code\]/","/\[line\]/","/\[msg\]/");
    $replacement=array(date("Y/m/d H:i:s"),$type,$exception->getCode(),$exception->getLine(),$exception->getMessage());

    $logFile=config("log","path").DIRECTORY_SEPARATOR.config("log","file");
    $fmt=config("log","fmt");
    $logContent=preg_replace($pattern,$replacement,$fmt).PHP_EOL;

    $f=fopen($logFile,"a");
    fwrite($f,$logContent);
    fclose($f);
}

//获取当前系统
function getSystem()
{
    return DIRECTORY_SEPARATOR === "\\"?"Windows":"Linux";
}

//当前是否时命令行模式(cli)
function isCli()
{
    return php_sapi_name() === "cli"?true:false;
}