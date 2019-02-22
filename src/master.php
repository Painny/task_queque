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

    //可接受命令列表
    private $command=[
        "start",
        "stop",
        "restart",
        "status",
        "testTask",
        "help"
    ];

    //守护进程pid文件
    private $pidFile="/run/task_queque.pid";

    //命令提示信息
    private $commandTips="Usage: php yourfile <command> \n".
                         "Commands:\n".
                         "  start:start the main process to work,add -d flag in daemonize mode run\n".
                         "  stop:stop all the workers processes and then stop main process\n".
                         "  restart:stop all old processes and then start new main processes to work\n".
                         "  status:return the system status\n".
                         "  testTask:add the test task data to debug\n".
                         "  help:get the help info\n";


    public function __construct($name="task_queque",$max_child_num=3,$task_check_time=10)
    {
        $this->name=$name;
        $this->max_child_num=$max_child_num;

        $this->task_check_time=$task_check_time;
        $this->log=new Log();

        //开始解析执行命令
        $this->parseCommand();
    }

    private function run($daemonize=false){
        //检查运行环境
        $this->checkRunEnv();

        //设置进程名
        cli_set_process_title($this->name." main process.pid file is {$this->pidFile}");

        if($daemonize){
            //以守护进程运行
            $this->daemonize();
        }

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
            $this->waitChild();
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

    //接收命令
    private function parseCommand()
    {
        global $argc;
        global $argv;

        //判断是否有命令
        if($argc <=1){
            exit($this->commandTips);
        }

        //是否合法命令
        if(!in_array($argv[1],$this->command)){
            $this->log->error("错误的命令：".$argv[1]);
            exit($this->commandTips);
        }

        switch ($argv[1]){
            case "start":
                $deamonize=false;

                if(isset($argv[2]) && $argv[2] == "-d"){
                    //判断是否已经在以守护进程模式运行
                    if(file_exists($this->pidFile)){
                        $this->log->info("the system is already run in deamonize");
                        exit("the system is already run in deamonize,pid file is ".$this->pidFile);
                    }
                    $deamonize=true;
                }

                $this->run($deamonize);
                break;
        }

    }

    //检查运行环境
    private function checkRunEnv()
    {
        if(getSystem() !== "Linux"){
            exit("error:please run in Linux system");
        }

        if(isCli() === false){
            exit("error:please run in cli mode");
        }

        if(!function_exists("pcntl_fork")){
            exit("error:please install pcntl php module");
        }

        if(!function_exists("posix_getpid")){
            exit("error:please install posix php module");
        }
        return;
    }

    //以守护进程方式运行
    private function daemonize()
    {
        $pid=pcntl_fork();
        if($pid == -1){
            $this->log->error("fork fail");
            exit("can not fork process");
        }else if($pid != 0){
            //父进程退出
            exit(0);
        }

        //在子进程再次fork，保证守护进程完全脱离终端控制
        $pid=pcntl_fork();
        if($pid == -1){
            $this->log->error("fork fail");
            exit("can not fork process");
        }else if($pid != 0){
            //父进程退出
            exit(0);
        }

        $this->resetProcess();

        //保存进程id
        $this->savePid();
    }

    //重置进程资源(会话、掩码等)
    private function resetProcess()
    {
        //重置掩码
        umask(0);

        //创建为新的会话组长
        if(posix_setsid() == -1){
            $this->log->error("posix_setsid fail");
            exit("can not make the current process a session leader");
        }

        //关闭继承的文件资源
        fclose(STDERR);
        fclose(STDOUT);
        fclose(STDIN);
    }

    //保存守护进程pid
    private function savePid()
    {
        if(!file_put_contents($this->pidFile,posix_getpid())){
            $this->log->error("save pid file fail");
            exit("save pid file fail");
        }
        //更改文件权限
        chmod($this->pidFile,0644);
    }

    //获取守护进程pid
    private function getPid()
    {
        if(!file_exists($this->pidFile)){
            $this->log->error("pid file is not exists");
            exit("pid file is not exists");
        }
        return intval(file_get_contents($this->pidFile));
    }

}