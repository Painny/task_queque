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
    public $pid;

    public function __construct($name)
    {
        $this->name=$name;
        $this->init();
    }

    //初始化
    private function init()
    {
        cli_set_process_title($this->name);
        $this->pid=posix_getpid();
        $this->connectRedis();
        //安装信号处理器
        $this->installSignal();
        //开始监听信号
        $this->listen();
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
    }

    //安装信号处理器
    private function installSignal()
    {
        //有任务需要获取并执行
        pcntl_signal(SIGUSR1,array($this,"doTask"));
        //todo 重载配置文件
    }

    //监听信号
    private function listen()
    {
        $status=0;
        while (true){
            pcntl_signal_dispatch();
            //堵塞等待信号
            pcntl_wait($status);
            pcntl_signal_dispatch();
        }
    }

    public function doTask($data)
    {
        $task=new Task($this->redis,$data);
        $task->execute();
    }


}