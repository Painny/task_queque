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

    //子进程数量
    private $child_num;

    //子进程pid数组
    private $child_pid;

    //任务检测间隔时间(秒)
    private $task_check_time;

    //redis连接实例
    private $redis;

    //log日志实例
    private $log;

    //用于轮询子进程的index
    private $index;

    //守护进程pid文件
    private $pidFile="/run/task_queque.pid";

    //可接受命令列表
    private $command=[
        "start",
        "stop",
        "reload",
        "status",
        "testTask",
        "help"
    ];

    //命令提示信息
    private $commandTips="Usage: php yourfile <command> \n".
                         "Commands:\n".
                         "  start:start the main process to work,add -d flag in daemonize mode run\n".
                         "  stop:stop all the workers processes and then stop main process\n".
                         "  reload:reload the config\n".
                         "  status:return the system status info\n".
                         "  testTask:add 3 test task data into system then send the result to the email,use -e flag appoint email\n".
                         "  help:get the help info\n";


    public function __construct($name="task_queque",$child_num=2,$task_check_time=10)
    {
        $this->name=$name;
        $this->child_num=$child_num;
        $this->index=0;

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

        //初始化子进程
        $this->initChild();

        //安装信号处理函数
        $this->installSignal();

        //连接redis
        $this->connectRedis();

        //开始任务检测
        $this->checkTask();

        //开始监听处理信号等
        $this->monitor();

    }

    //模拟丢任务
    private function addTask($email)
    {
        $this->connectRedis();
        for($i=1;$i<=3;$i++){
            $this->redis->lPush(config("task","list"),"make_pay_code");
            $data=array(
                "flag"          =>  $i,
                "code_list_key" =>  "pay_code_list_{$i}",
                "email"         =>  $email,
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
        //定时发送alarm信号，出发任务检测
        pcntl_alarm($this->task_check_time);

        //检测redis是否断线
        if($this->redis->ping()!=="+PONG"){
            $this->connectRedis();
        }

        //检测是否有可用任务
        $hasTask=$this->redis->lLen(config("task","list"));

        if(!$hasTask){
            return;
        }

        //轮询选取一个子进程去执行任务
        $childPid=$this->chooseChild();
        if(!posix_kill($childPid,SIGUSR1)){
            $this->log->error("send do task signal fail");
        }
    }

    //监听处理僵尸子进程
    private function waitChild()
    {
        $status=0;
        $pid=pcntl_wait($status);

        if($pid <= 0){
            return;
        }

        //更新子进程数组
        $childArr=array_flip($this->child_pid);
        unset($childArr[$pid]);
        $this->child_pid=array_keys($childArr);


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
                    if($this->isRunning()){
                        $this->log->info("the system is already run in deamonize");
                        exit("the system is already run in deamonize,pid file is ".$this->pidFile);
                    }
                    $deamonize=true;
                }

                $this->run($deamonize);
                break;
            case "help":
                exit($this->commandTips);
            case "testTask":
                if(!isset($argv[2]) || $argv[2] != "-e"){
                    exit("please use -e flag to appoint a email for result send to");
                }

                if(!isset($argv[3])){
                    exit("please add the correct email follow the -e flag");
                }

                $this->addTask($argv[3]);
                $this->redis->close();
                exit("you add 3 task data into the system,please wait for checking your email to debug");
            case "status":
                $status=$this->status();
                exit($status);
            case "stop":
                $this->stop();
                exit(0);
            case "reload":
                $this->reload();
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

    //判断是否已经以守护进程模式在运行
    private function isRunning()
    {
        if(file_exists($this->pidFile)){
            if(!intval(file_get_contents($this->pidFile))){
                unlink($this->pidFile);
                return false;
            }
            return true;
        }
        return false;
    }

    //运行状态信息
    private function status()
    {
        if(!$this->isRunning()){
            return "system is stoped\n";
        }

        //守护进程pid
        $pid=$this->getPid();

        $info="main process is running,the pid file is {$this->pidFile},pid is {$pid}\n";
        return $info;
    }

    //安装信号
    private function installSignal()
    {
        pcntl_signal(SIGTERM,array($this,"stopAll"));
        pcntl_signal(SIGUSR1,array($this,"reloadConfig"));
        pcntl_signal(SIGALRM,array($this,"checkTask"));
    }

    //发送停止所有进程信号
    private function stop()
    {
        if(!$this->isRunning()){
            exit("system is stoped\n");
        }

        $pid=$this->getPid();
        //向守护进程发送停止信号
        posix_kill($pid,SIGTERM);

        //最多等待10秒，未停止则失败
        for($i=0;$i<20;$i++){
            if(!$this->isRunning()){
                exit("stop success\n");
            }
            sleep(1);
        }
        exit("stop system is fail\n");
    }

    //执行停止所有进程信号
    private function stopAll()
    {
        $masterPid=$this->getPid();
        $currentPid=posix_getpid();

        //对于子进程，不做任何处理，任务完成会自动退出
        if($currentPid != $masterPid){
            return;
        }

        //对于主进程，停止任务检测，等待所有子进程退出后在退出
        while(count($this->child_pid) > 0){
            $this->waitChild();
        }
        //删除pid文件
        unlink($this->pidFile);
        exit(0);
    }

    //发送重载信号
    private function reload()
    {
        if(!$this->isRunning()){
            exit("system is not running");
        }
        $pid=$this->getPid();
        posix_kill($pid,SIGUSR1);
    }

    //执行重载信号
    private function reloadConfig()
    {
        global $CFG;

        $file=__DIR__."/config.php";
        $CFG=require $file;
    }

    //监听处理信号、子进程等(主循环)
    private function monitor()
    {
        while (true){
            //检测是否有信号可捕捉处理
            pcntl_signal_dispatch();

            //监听等待子进程退出
            $this->waitChild();

            //再次检测
            pcntl_signal_dispatch();
        }
    }

    //初始化子进程
    private function initChild()
    {
        for($i=0;$i<$this->child_num;$i++){
            $pid=pcntl_fork();
            if($pid == -1){
                $this->log->error("fork child process fail");
                exit("fork child process fail");
            }else if($pid != 0){
                $this->child_pid[]=$pid;
            }else{
                new Worker($this->name."_worker");
            }
        }
    }

    //轮询选取一个子进程pid
    private function chooseChild()
    {
        //获取当前子进程数量
        $count=count($this->child_pid);

        //获取一个可用child_pid的索引
        $index=$this->index%$count;

        $this->index++;
        return $this->child_pid[$index];
    }

}