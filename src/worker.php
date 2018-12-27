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

    public function __construct($name,$taskData)
    {
        $pid=pcntl_fork();

        if($pid == 0){
            $this->name=$name;
            sleep(10);exit();
            try{
                $this->init($taskData);
            }catch (Exception $exception){
                echo "exception:".$exception->getMessage().PHP_EOL;
                exit();
            }
        }else{
            $this->pid=$pid;
        }
    }

    //初始化
    private function init($taskData)
    {
        cli_set_process_title($this->name);
        $this->pid=getmypid();
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
    }

    public function doTask($data)
    {

        $task=new Task($this->redis,$data);
        $task->execute();
        $this->redis->close();
        //退出进程
        exit();
    }


}