<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/21
 * Time: 12:03
 */

class Worker{
    //进程名
    private $name;

    //redis实例
    private $redis;

    //进程pid
    private $pid;

    public function __construct($name,$taskData)
    {
        $pid=pcntl_fork();

        if($pid == 0){
            $this->name=$name;
            $this->init($taskData);
        }
    }

    //初始化
    private function init($taskData)
    {
        cli_set_process_title($this->name."_worker");
        $this->pid=getmypid();
        echo "子进程:".$this->pid."初始化".PHP_EOL;
        $this->connectRedis();
        $this->doTask($taskData);
    }

    //连接redis
    private function connectRedis()
    {
        $redis=new Redis();
        $redis->connect(
            config("redis","host"),
            config("redis","port")
        );
        $redis->auth(config("redis","passwd"));
        $redis->select(config("redis","db"));
        $this->redis=$redis;
        echo "子进程:".$this->pid."连接redis".PHP_EOL;
    }

    public function doTask($data)
    {
        echo "子进程:".$this->pid."执行任务".PHP_EOL;
        var_dump($data);
        $this->redis->close();
        echo "子进程:".$this->pid."退出".PHP_EOL;
        exit();
    }

}