<?php
/**
 * PHPMailer - PHP email creation and transport class.
 * 精简版，仅包含QQ邮箱SMTP发送所需的核心功能
 * 完整版请从 https://github.com/PHPMailer/PHPMailer 获取
 */

namespace PHPMailer\PHPMailer;

class PHPMailer
{
    const VERSION = '6.8.0';
    const ENCODING_7BIT = '7bit';
    const ENCODING_8BIT = '8bit';
    const ENCODING_BASE64 = 'base64';
    const ENCODING_BINARY = 'binary';
    const ENCODING_QUOTED_PRINTABLE = 'quoted-printable';

    public $Priority = 3;
    public $CharSet = 'utf-8';
    public $ContentType = 'text/plain';
    public $Encoding = self::ENCODING_8BIT;
    public $ErrorInfo = '';
    public $From = '';
    public $FromName = '';
    public $Sender = '';
    public $Subject = '';
    public $Body = '';
    public $AltBody = '';
    public $WordWrap = 0;
    public $Mailer = 'smtp';
    public $SMTPDebug = 0;
    public $Debugoutput = 'echo';
    public $SMTPAuth = true;
    public $SMTPSecure = 'ssl';
    public $SMTPAutoTLS = true;
    public $Port = 465;
    public $Host = '';
    public $Username = '';
    public $Password = '';
    public $Timeout = 300;
    public $SMTPKeepAlive = false;

    protected $smtp = null;
    protected $to = [];
    protected $cc = [];
    protected $bcc = [];
    protected $ReplyTo = [];
    protected $attachment = [];
    protected $language = [];
    protected $lastMessageID = '';
    protected $message_type = '';
    protected $boundary = [];
    protected $exceptions = false;

    public function __construct($exceptions = null)
    {
        if (null !== $exceptions) {
            $this->exceptions = (bool)$exceptions;
        }
        $this->boundary = ['_' . md5(rand()), '_' . md5(rand() + 1)];
    }

    public function setFrom($address, $name = '', $auto = true)
    {
        $address = trim($address);
        $name = trim($name);
        if (($pos = strrpos($address, '@')) === false) {
            $this->setError($this->lang('from_failed') . $address);
            return false;
        }
        $this->From = $address;
        $this->FromName = $name;
        if ($auto && empty($this->Sender)) {
            $this->Sender = $address;
        }
        return true;
    }

    public function addAddress($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('to', $address, $name);
    }

    public function addCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('cc', $address, $name);
    }

    public function addBCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('bcc', $address, $name);
    }

    public function addReplyTo($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('Reply-To', $address, $name);
    }

    protected function addOrEnqueueAnAddress($kind, $address, $name)
    {
        $address = trim($address);
        $name = trim($name);
        if ('Reply-To' === $kind) {
            $this->ReplyTo[] = [$address, $name];
        } else {
            $this->{$kind}[] = [$address, $name];
        }
        return true;
    }

    public function isHTML($isHtml = true)
    {
        if ($isHtml) {
            $this->ContentType = 'text/html';
        } else {
            $this->ContentType = 'text/plain';
        }
    }

    public function isSMTP()
    {
        $this->Mailer = 'smtp';
    }

    public function isMail()
    {
        $this->Mailer = 'mail';
    }

    public function send()
    {
        try {
            $this->ErrorInfo = '';
            if (!$this->preSend()) {
                return false;
            }
            return $this->postSend();
        } catch (\Exception $exc) {
            $this->mailHeader = '';
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
    }

    protected function preSend()
    {
        if ('smtp' !== $this->Mailer) {
            return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
        }
        return true;
    }

    protected function postSend()
    {
        switch ($this->Mailer) {
            case 'smtp':
                return $this->smtpSend($this->MIMEHeader, $this->MIMEBody);
            default:
                return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
        }
    }

    protected function smtpSend($header, $body)
    {
        $badRcpt = [];
        if (!$this->smtpConnect()) {
            throw new \Exception($this->lang('connect_host'));
        }
        if (!$this->smtp->mail($this->Sender)) {
            $this->setError($this->lang('from_failed') . $this->Sender);
            throw new \Exception($this->ErrorInfo);
        }
        $addresses = array_merge($this->to, $this->cc, $this->bcc);
        foreach ($addresses as $toaddr) {
            if (!$this->smtp->recipient($toaddr[0])) {
                $badRcpt[] = $toaddr[0];
            }
        }
        if (count($badRcpt) > 0) {
            throw new \Exception($this->lang('recipients_failed') . implode(', ', $badRcpt));
        }
        if (!$this->smtp->data($header . $body)) {
            throw new \Exception($this->lang('data_not_accepted'));
        }
        if ($this->SMTPKeepAlive) {
            $this->smtp->reset();
        } else {
            $this->smtp->quit();
            $this->smtp->close();
        }
        return true;
    }

    protected function mailSend($header, $body)
    {
        $to = '';
        foreach ($this->to as $toaddr) {
            $to .= $toaddr[0] . ', ';
        }
        $to = rtrim($to, ', ');
        if (empty($this->Sender)) {
            $this->Sender = $this->From;
        }
        $result = mail($to, $this->Subject, $body, $header, '-f' . $this->Sender);
        return $result;
    }

    public function smtpConnect()
    {
        if (null === $this->smtp) {
            $this->smtp = new SMTP();
        }
        $this->smtp->Timeout = $this->Timeout;
        $this->smtp->Debugoutput = $this->Debugoutput;
        $this->smtp->do_debug = $this->SMTPDebug;
        $hosts = explode(';', $this->Host);
        $lastexception = null;
        foreach ($hosts as $hostentry) {
            $hostinfo = [];
            if (!preg_match('/^(ssl|tls):\/\/(.+):(\d+)$/', $hostentry, $hostinfo)) {
                $hostinfo[2] = $hostentry;
                $hostinfo[1] = $this->SMTPSecure;
                $hostinfo[3] = $this->Port;
            }
            $prefix = '';
            if ($hostinfo[1] === 'ssl' || $hostinfo[1] === 'tls') {
                $prefix = $hostinfo[1] . '://';
            }
            if ($this->smtp->connect($prefix . $hostinfo[2], (int)$hostinfo[3], $this->Timeout)) {
                if ($this->SMTPAuth) {
                    if (!$this->smtp->hello($_SERVER['SERVER_NAME'] ?? 'localhost')) {
                        throw new \Exception($this->lang('smtp_connect_failed'));
                    }
                    if (!$this->smtp->authenticate($this->Username, $this->Password)) {
                        throw new \Exception($this->lang('authenticate'));
                    }
                }
                return true;
            }
        }
        throw new \Exception($this->lang('connect_host'));
    }

    public function setError($msg)
    {
        $this->ErrorInfo = $msg;
    }

    protected function lang($key)
    {
        $messages = [
            'connect_host' => 'SMTP Error: Could not connect to SMTP host.',
            'from_failed' => 'The following From address failed: ',
            'recipients_failed' => 'SMTP Error: The following recipients failed: ',
            'data_not_accepted' => 'SMTP Error: data not accepted.',
            'authenticate' => 'SMTP Error: Could not authenticate.',
            'smtp_connect_failed' => 'SMTP connect() failed.',
        ];
        return $messages[$key] ?? $key;
    }

    public function getSMTPInstance()
    {
        return $this->smtp;
    }

    public function getToAddresses()
    {
        return $this->to;
    }
}
