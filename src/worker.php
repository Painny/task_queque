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

    //是否忙碌
    private $isWorking;

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
        $this->isWorking=false;
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
        //退出
        pcntl_signal(SIGTERM,array($this,"stop"));
        //重新加载配置文件
        pcntl_signal(SIGUSR2,array($this,"reloadConfig"));
    }

    //监听信号
    private function listen()
    {
        while (true){
            pcntl_signal_dispatch();
            //堵塞等待信号(系统调用会堵塞，信号会中断系统调用)
            sleep(100);
            pcntl_signal_dispatch();
        }
    }

    //退出
    private function stop()
    {
        $file="/var/www/html/task_queque/log/worklog.log";
        file_put_contents($file,"子进程{$this->pid}接收到stop信号".PHP_EOL,FILE_APPEND);
        //判断当前是否在执行任务
        while ($this->isWorking){
            sleep(1);
        }
        file_put_contents($file,"子进程{$this->pid}退出".PHP_EOL,FILE_APPEND);
        exit(0);
    }

    //重新加载配置文件
    private function reloadConfig()
    {
        global $CFG;

        $file=__DIR__."/config.php";
        $CFG=require $file;
    }

    //获取、执行任务
    private function doTask()
    {
        //检测redis是否断线
        if($this->redis->ping()!=="+PONG"){
            $this->connectRedis();
        }

        //从所有任务类型列表中获取可以执行的任务类型
        $taskType=$this->redis->rPop(config("task","list"));
        //无任务
        if(!$taskType){
            return;
        }

        //无效任务类型
        if(!in_array($taskType,config("task","type"))){
            return;
        }

        //从该类型的任务列表中取除具体任务数据
        $taskData=$this->redis->rPop($taskType);
        if(!$taskData){
            return;
        }

        /*
         * 生成学生付款码任务数据：
         *  {
         *      "flag":0|1|2,                               生成文件包含内容，0二维码，1文本，2两者
         *      "code_list_key":"pay_code_list_xxx"         存付款码列表的redis key
         *      "email":"xxx@xxx.com"                       要发送的邮箱地址
         *      "file":"xxx.zip"                            将要生成文件的名字
         *  }
         */
        $taskData=array("type"=>$taskType,"data"=>$taskData);

        //更新当前状态
        $this->isWorking=true;

        //执行任务
        $task=new Task($this->redis,$taskData);
        $task->execute();
        $this->isWorking=false;
    }


}