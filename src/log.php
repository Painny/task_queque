<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2019/1/2
 * Time: 16:33
 */

class Log{
    private $path;
    private $file;
    private $format;
    private $maxSize;  //单位字节

    private $level=array(
        "info",
        "notice",
        "debug",
        "error"
    );

    public function __construct()
    {
        $this->path=config("log","path");
        $this->file=config("log","file");
        $this->format=config("log","fmt");
        $this->maxSize=config("log","max_size")*1024*1024;

        //判断目录是否存在
        if(!file_exists($this->path)){
            mkdir($this->path);
        }
        //判断文件是否达到最大
        if($this->check()){
            $this->backup();
        }

    }

    //检测日志文件是否需要备份并新建(达到最大)
    private function check()
    {
        $fullFileName=$this->path.DIRECTORY_SEPARATOR.$this->file;

        if(file_exists($fullFileName) && filesize($fullFileName) >= $this->maxSize){
            return true;
        }
        return false;
    }

    //备份当前日志文件，新建日志文件
    private function backup()
    {
        $fullFileName=$this->path.DIRECTORY_SEPARATOR.$this->file;
        $backupName=$this->path.DIRECTORY_SEPARATOR.date("Ymd").".log.bak";

        rename($fullFileName,$backupName);
        $f=fopen($fullFileName,"w+");
        fclose($f);
    }

    //记录日志信息
    private function log($msg,$level="info")
    {
        if(!in_array($level,$this->level)){
            return false;
        }

        $pattern=array("/time/","/type/","/code/","/line/","/msg/");
        if($msg instanceof Exception || $msg instanceof Error){
            $replacement=array(
                date("Y/m/d H:i:s"),
                $level,
                $msg->getCode(),
                $msg->getLine(),
                $msg->getMessage()
            );
        }else if(is_array($msg)){
            $replacement=array(
                date("Y/m/d H:i:s"),
                $level,
                isset($msg["code"])?$msg["code"]:"-- code",
                isset($msg["line"])?$msg["line"]:"-- line",
                isset($msg["msg"])?$msg["msg"]:"--"
            );
        }else{
            $replacement=array(
                date("Y/m/d H:i:s"),
                $level,
                "-- code",
                "-- line",
                $msg
            );
        }

        $logContent=preg_replace($pattern,$replacement,$this->format).PHP_EOL;

        $fullFileName=$this->path.DIRECTORY_SEPARATOR.$this->file;
        $f=fopen($fullFileName,"a");
        fwrite($f,$logContent);
        fclose($f);
        return true;
    }

    public function __call($name, $arguments)
    {
        if(!in_array($name,$this->level)){
            throw new Exception("调用了不存在的日志方法:{$name}");
        }
        return $this->log($arguments[0],$name);
    }


}