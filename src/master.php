<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/21
 * Time: 10:33
 */

class Master{
    //主进程名
    private $name;

    //当前子进程数量
    private $child_num;

    //子进程pid数组
    private $child_pid;

    //最大子进程数量
    private $max_child_num;

    //任务检测间隔时间(秒)
    private $task_check_time;

    //redis连接实例
    private $redis;

    //log日志实例
    private $log;

    public function __construct($name,$max_child_num=3,$task_check_time=10)
    {
        $this->name=$name;
        $this->max_child_num=$max_child_num;

        $this->task_check_time=$task_check_time;
        $this->log=new Log();
    }

    public function run(){
        pcntl_signal(SIGCHLD,SIG_IGN);
        //设置进程名
        cli_set_process_title($this->name);
        //连接redis
        $this->connectRedis();
        //开始任务检测
        while (true){
            $taskData=$this->checkTask();

            if($taskData){
                //检查是否达到最大进程数
                $this->checkChild();
                //新开子进程执行任务
                $worker=new Worker($this->name."_worker",$taskData);
                $this->child_pid[]=$worker->pid;
            }

            //检查是否有子进程退出
            $this->waitChild();

            sleep($this->task_check_time);
        }

    }

    //检测子进程数量
    private function checkChild()
    {
        //达到最大子进程数量
        while($this->child_num >= $this->max_child_num){
            //记录日志
            $this->log->info("当前达到最大子进程数：".$this->child_num);
            //等待重试
            sleep(2);
        }
        return;
    }

    //模拟丢任务
    private function addTask()
    {
        for($i=0;$i<2;$i++){
            $this->redis->lPush(config("task","list"),"make_pay_code");
            $data=array(
                "flag"          =>  $i,
                "code_list_key" =>  "pay_code_list_{$i}",
                "email"         =>  "pengyu@cnhsqk.com",
                "file"          =>  "test/pytest-{$i}.zip"
            );
            $this->redis->lPush("make_pay_code",json_encode($data));
        }

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

    //检测任务
    private function checkTask()
    {
        //检测redis是否断线
        if($this->redis->ping()!=="+PONG"){
            $this->connectRedis();
        }

        //从所有任务类型列表中获取可以执行的任务类型
        $taskType=$this->redis->rPop(config("task","list"));
        //无任务
        if(!$taskType){
            return null;
        }

        //无效任务类型
        if(!in_array($taskType,config("task","type"))){
            return null;
        }

        //从该类型的任务列表中取除具体任务数据
        $taskData=$this->redis->rPop($taskType);
        if(!$taskData){
            return null;
        }

        return array("type"=>$taskType,"data"=>$taskData);
        /*
         * 生成学生付款码任务数据：
         *  {
         *      "flag":0|1|2,                               生成文件包含内容，0二维码，1文本，2两者
         *      "code_list_key":"pay_code_list_xxx"         存付款码列表的redis key
         *      "email":"xxx@xxx.com"                       要发送的邮箱地址
         *      "file":"xxx.zip"                             将要生成文件的名字
         *  }
         */

    }

    //监听处理僵尸子进程
    private function waitChild()
    {
        $status=0;
        $pid=pcntl_wait($status,WNOHANG);

        if($pid <= 0){
            return;
        }

        //从子进程数组中移除
        $childArr=array_flip($this->child_pid);
        unset($childArr[$pid]);
        $this->child_pid=array_keys($childArr);
        //更新子进程数量
        $this->child_num=count($this->child_pid);

    }




}