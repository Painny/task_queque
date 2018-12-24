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

    public function __construct($redis,$data)
    {
        $this->type=$data["type"];
        $this->redis=$redis;
        $this->data=json_decode($data["data"],true);
    }

    public function execute()
    {
        switch ($this->type){
            case 'make_pay_code':
                $this->makePayCode($this->data);
                break;
            case 'make_teacher_code':
                //todo
                break;
        }
    }

    //生成付款码资源
    private function makePayCode($data)
    {
        $email=$data["email"];
        $flag=$data["flag"];  //0二维码，1文本，2两者
        $key=$data["code_list_key"];
        $ossFileName=$data["file"];

        //所有的付款码
        $payCode=$this->redis->sMembers($key);

        if(!$payCode){
            //todo 记录日志
            echo "没有授权码数据";
            exit();
        }
        $zip=new \ZipArchive();
        $zipfile=time().rand(0,9).".zip";

        if(!$zip->open($zipfile, \ZipArchive::CREATE)){
            //todo 记录日志
            echo "zip打开文件出错";
            exit();
        }

        //添加文本文件
        if($flag == 1 || $flag == 2){
            $txt="";
            foreach ($payCode as $code){
                $txt.=$code.PHP_EOL;
            }
            $zip->addFromString("code.txt",$txt);
        }

        //添加二维码
        $picArr=array();
        if($flag == 0 || $flag == 2){
            $qr=new QrCode();
            foreach ($payCode as $key => $code){
                $qr->setText($code);
                $qr->setSize(300);
                $filename="payCode".$key.".png";
                $zip->addFromString($filename,$qr->writeString());
                //图片不超过10张，记录下来直接发送邮件
                if(count($payCode) <= 10){
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
        echo "压缩文件".$zipfile;
        $res=$oss->uploadFile(config("oss","bucket"),$ossFileName,$zipfile);
        if(!isset($res["info"]["url"])){
            //todo 记录日志
            echo "上传oss出错";
        }

        $attchments=$picArr;
        $attchments[]=$zipfile;

        //发送邮件
        $this->sendEmail($email,"学生付款码文件","您申请的学生付款码已成功生成",$attchments);
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
            //todo 记录日志
            echo "邮件发送失败:".$mail->ErrorInfo;
        }
    }


}