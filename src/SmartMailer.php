<?php

namespace PhpWebsite;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Liquid\Template;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use SmartWebsite\GraphMailer;
use Spyc;

class SmartMailer
{
    private string $type, $host, $port, $user, $password, $fromName, $fromAddress, $BCC;
    private string $htmlTemplate;

    public function __construct()
    {
        $this->type = 'normal';
        $this->host = '';
        $this->port = '';
        $this->user = '';
        $this->password = '';
        $this->fromName = '';
        $this->fromAddress = '';
        $this->BCC = '';
    }

    public static function initialise($EMAIL_TYPE, $EMAIL_HOST, $EMAIL_PORT, $EMAIL_USER, $EMAIL_PASS, $EMAIL_FROM_NAME, $EMAIL_FROM_EMAIL, $EMAIL_BCC)
    {
        $instance = new self();
        $instance->type = $EMAIL_TYPE;
        $instance->host = $EMAIL_HOST;
        $instance->port = $EMAIL_PORT;
        $instance->user = $EMAIL_USER;
        $instance->password = $EMAIL_PASS;
        $instance->fromName = $EMAIL_FROM_NAME;
        $instance->fromAddress = $EMAIL_FROM_EMAIL;
        $instance->BCC = $EMAIL_BCC;
        return $instance;
    }

    public function setHtmlTemplate($file)
    {
        if (file_exists($file)) {
            $this->htmlTemplate = file_get_contents($file);
        } else {
            $this->htmlTemplate = '<html><body>{{ body }}</body></html>';
        }
    }

    public function mail($to, $title, $message, $headers = array(), $html = true, $cc = '', $attach = '')
    {

        if ($html || $this->type == 'o365graph') {
            $environment = new Environment(array());
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new AttributesExtension());
            $converter = new MarkdownConverter($environment);

            $content = array('body' => $converter->convert($message));

            $template = new Template();
            $template->parse($this->htmlTemplate);
            $message = $template->render($content);

            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        }

        if ($this->type == 'smtp') {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPSecure = $this->emailPort == 587 ? 'tls' : 'ssl';
            $mail->SMTPAuth = true;
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->Username = $this->user;
            $mail->Password = $this->password;
            $mail->setFrom($this->fromAddress, $this->fromName);
            $tos = explode(',', $to);
            foreach ($tos as $to) {
                $mail->addAddress($to);
            }
            $ccs = explode(',', $cc);
            foreach ($ccs as $cc) {
                $mail->addCC($cc);
            }
            $mail->addBCC($this->BCC);
            $mail->Subject = $title;
            $mail->Body = $message;
            foreach ($headers as $key => $val) {
                $mail->addCustomHeader($key, $val);
            }
            return $mail->send();
        } else if ($this->type == 'o365graph') {
            $gm = new graphMailer($this->host, $this->user, $this->password);
            $gm->getToken();
            $tos = SmartMailer::validateEmails($to);
            $ccs = SmartMailer::validateEmails($cc);
            $mailArgs =  array(
                'subject' => $title,
                // 'replyTo' => array('name' => $this->fromName, 'address' => $this->fromAddress),
                'toRecipients' => array(
                    array('address' => $tos[0]),
                ),
                // 'ccRecipients' => array(
                //     array('name' => 'Neil', 'address' => 'address@email.com'),
                //     array('name' => 'Someone', 'address' => 'address2@email.com')
                // ),
                'importance' => 'normal',
                // 'conversationId' => '',   //optional, use if replying to an existing email to keep them chained properly in outlook
                'body' => $message,
                // 'images' => array(
                //     array('Name' => 'blah.jpg', 'ContentType' => 'image/jpeg', 'Content' => 'results of file_get_contents(blah.jpg)', 'ContentID' => 'cid:blah')
                // ),   //array of arrays so you can have multiple images. These are inline images. Everything else in attachments.
                // 'attachments' => array(
                //     array('Name' => 'blah.pdf', 'ContentType' => 'application/pdf', 'Content' => 'results of file_get_contents(blah.pdf)')
                // )
            );
            if (count($tos) > 1) {
                $mailArgs['ccRecipients'] = array();
                for ($i = 1; $i < count($tos); $i++) {
                    $mailArgs['ccRecipients'][] = array('address' => $tos[$i]);
                }
            }
            if (count($ccs) > 0) {
                if (!isset($mailArgs['ccRecipients'])) $mailArgs['ccRecipients'] = array();
                for ($i = 0; $i < count($ccs); $i++) {
                    $mailArgs['ccRecipients'][] = array('address' => $ccs[$i]);
                }
            }
            if ($this->BCC != '') {
                $mailArgs['bccRecipients'][] = array('address' => $this->BCC);
            }
            if ($attach != '') {
                $mailArgs['attachments'] = array();
                foreach (explode(',', $attach) as $file) {
                    $info = pathinfo($file);
                    $name = $info['basename'];
                    $type = 'application/pdf';
                    $ext = $info['extension'];
                    if ($ext == 'jpg') {
                        $type = 'image/jpeg';
                    }
                    if ($ext == 'png') {
                        $type = 'image/png';
                    }
                    if ($content = file_get_contents($file)) {
                        $mailArgs['attachments'][] = array(
                            'Name' => $name,
                            'ContentType' => $type,
                            'Content' => $content,
                        );
                    }
                }
            }

            return $gm->sendMail($this->fromAddress, $mailArgs);
        } else {
            $h = '';
            foreach ($headers as $key => $val) {
                $h .=  $key . ': ' . $val . "\r\n";
            }
            return mail($to, $title, $message, $h);
        }
    }

    public static function validateEmails($list)
    {
        $brol = explode(',', $list);
        $returnList = array();
        foreach ($brol as $m) {
            $m = trim(strtolower($m));
            if (filter_var($m, FILTER_VALIDATE_EMAIL)) {
                $returnList[] = $m;
            }
        }
        return $returnList;
    }
}
