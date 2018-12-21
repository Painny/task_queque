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

    //空闲子进程数量
    private $free_child_num;

    //子进程pid数组
    private $child_pid;

    //最大子进程数量
    private $max_child_num;

    //最小子进程数量
    private $min_child_num;

    //任务检测间隔时间(秒)
    private $task_check_time;

    public function __construct($name,$max_child_num=3,$min_child_num=1,$task_check_time=5)
    {
        $this->name=$name;
        $this->max_child_num=$max_child_num;
        $this->min_child_num=$min_child_num;
        $this->task_check_time=$task_check_time;
    }

    public function run(){
        //设置进程名
        cli_set_process_title($this->name);

        //模拟任务
        $task=["a","b","c"];

        //开始任务检测
        while (true){

            $tmp=array_pop($task);

            if($tmp){
                $this->doTask($tmp);
            }

            sleep($this->task_check_time);
        }
    }

    private function doTask($task)
    {
        $pid=pcntl_fork();
        //子进程，执行任务
        if($pid == 0){
            cli_set_process_title($this->name."_worker");
            echo $task.PHP_EOL;
            exit();
        }else{  //父进程,记录子进程信息
            $this->child_num++;
            $this->child_pid[]=$pid;
        }
    }

}