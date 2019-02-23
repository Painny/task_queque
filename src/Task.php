<?php
/**
 * Created by PhpStorm.
 * User: pengyu
 * Date: 2018/12/21
 * Time: 15:49
 */

use Endroid\QrCode\QrCode;
use OSS\OssClient;
use PHPMailer\PHPMailer\PHPMailer;

class Task{

    private $type;
    private $data;
    private $redis;
    private $log;

    public function __construct($redis,$data)
    {
        $this->type=$data["type"];
        $this->redis=$redis;
        $this->data=json_decode($data["data"],true);
        $this->log=new Log();
    }

    public function execute()
    {
        switch ($this->type){
            case 'make_pay_code':
            case 'make_teacher_code':
                $this->makeCode($this->data,$this->type);
                break;
        }
    }

    //生成资源(上传oss后发邮件)
    private function makeCode($data,$type)
    {
        $email=$data["email"];
        $flag=$data["flag"];  //0二维码，1文本，2两者
        $key=$data["code_list_key"];
        $ossFileName=$data["file"];

        //所有的(教师、付款)码
        if($type == "make_pay_code"){
            $allCode=$this->redis->sMembers($key);
        }else{
            $tmp=$this->redis->zRange($key,0,-1,true);
            $allCode=array_map(function ($value){
                return intval($value);
            },$tmp);
            $allCode=array_flip($allCode);
        }
        //取出数据后删除
        $this->redis->del($key);

        if(!$allCode){
            $this->log->error("没有授权码数据");
            return;
        }
        $zip=new \ZipArchive();
        $zipfile=time().rand(0,9).".zip";

        $zipRes=$zip->open($zipfile, \ZipArchive::CREATE);
        if(!$zipRes){
            $this->log->error("zip打开文件错误:".$zipRes);
            return;
        }

        //添加文本文件
        if($flag == 1 || $flag == 2){
            $txt="";
            $numberTxt="";
            foreach ($allCode as $index => $code){
                $txt.=$code.PHP_EOL;
                if($type == "make_teacher_code"){
                    $numberTxt.=$data["agent"].$index.PHP_EOL;
                }
            }
            $zip->addFromString("code.txt",$txt);
            //如果时教师授权码，短码也写入文本
            if($type == "make_teacher_code"){
                $zip->addFromString("codeNumber.txt",$numberTxt);
            }
        }

        //添加二维码
        $picArr=array();
        if($flag == 0 || $flag == 2){
            $qr=new QrCode();
            foreach ($allCode as $key => $code){
                $qr->setText($code);
                $qr->setSize(300);
                $filename="code-".$key.".png";
                $zip->addFromString($filename,$qr->writeString());
                //图片不超过10张，记录下来直接发送邮件
                if(count($allCode) <= 10){
                    $qr->writeFile($filename);
                    $picArr[]=$filename;
                }
            }
        }
        $zip->close(); //关闭处理的zip文件

        //保存文件到oss
        $oss=new OssClient(
            config("oss","access_key_id"),
            config("oss","access_key_secret"),
            config("oss","endpoint")
        );

        $res=$oss->uploadFile(config("oss","bucket"),$ossFileName,$zipfile);
        if(!isset($res["info"]["url"])){
            $this->log->error("上传oss出错");
            return;
        }

        $attchments=$picArr;
        $attchments[]=$zipfile;

        //发送邮件
        if($type == "make_pay_code"){
            $type="学生付款码";
        }else if($type == "make_teacher_code"){
            $type="教师授权码";
        }

        $this->sendEmail($email,"{$type}文件","您申请的{$type}已成功生成",$attchments);
        //删除文件
        unlink($zipfile);
        if(count($picArr)){
            foreach ($picArr as $pic){
                unlink($pic);
            }
        }
    }

    //发邮件
    private function sendEmail($addr,$subject,$msg,$attchments=[])
    {
        $mail=new PHPMailer();
        $mail->SMTPDebug = 0;  // Enable verbose debug output
        // Set mailer to use SMTP
        if(config("mail","driver") == "smtp"){
            $mail->isSMTP();
        }
        $mail->Host = config("mail","host");        // Specify main and backup SMTP servers
        $mail->Port = config("mail","port");        // TCP port to connect to
        $mail->SMTPAuth = true;                               // Enable SMTP authentication
        $mail->Username = config("mail","username");// SMTP username
        $mail->Password = config("mail","passwd");  // SMTP password
        $mail->SMTPSecure = config("mail","encryption");            // Enable TLS encryption, `ssl` also accepted

        //Recipients
        $mail->setFrom(config("mail","username"), "寰视乾坤");
        $mail->addAddress($addr);

        //Content
        $mail->isHTML(false);                             // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $msg;

        //添加附件
        foreach ($attchments as $item){
            $mail->addAttachment($item);
        }

        if(!$mail->send()){
            $this->log->info("发送邮件失败:".$mail->ErrorInfo);
        }
    }


}