<?php
/**
 * PHPMailer SMTP Class
 * 精简版SMTP实现
 */

namespace PHPMailer\PHPMailer;

class SMTP
{
    const VERSION = '6.8.0';
    const CRLF = "\r\n";
    const DEFAULT_PORT = 25;
    const MAX_LINE_LENGTH = 998;

    public $do_debug = 0;
    public $Debugoutput = 'echo';
    public $Timeout = 300;

    protected $smtp_conn;
    protected $error = '';
    protected $helo_rply = null;
    protected $server_caps = null;

    public function connect($host, $port = self::DEFAULT_PORT, $timeout = 30)
    {
        $this->error = [];
        $this->server_caps = null;
        $this->helo_rply = null;
        $errno = 0;
        $errstr = '';
        $socket_context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $this->smtp_conn = @stream_socket_client(
            $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $socket_context
        );
        if (empty($this->smtp_conn)) {
            $this->error = ['error' => 'Failed to connect to server', 'errno' => $errno, 'errstr' => $errstr];
            $this->edebug('SMTP ERROR: ' . $errstr . ' (' . $errno . ')');
            return false;
        }
        $this->edebug('Connection opened');
        $announce = $this->get_lines();
        $this->edebug('SERVER -> CLIENT: ' . $announce);
        return true;
    }

    public function startTLS()
    {
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) {
            return false;
        }
        if (!stream_socket_enable_crypto($this->smtp_conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return false;
        }
        return true;
    }

    public function authenticate($username, $password)
    {
        if (!$this->sendCommand('AUTH', 'AUTH LOGIN', 334)) {
            return false;
        }
        if (!$this->sendCommand('AUTH user', base64_encode($username), 334)) {
            return false;
        }
        if (!$this->sendCommand('AUTH password', base64_encode($password), 235)) {
            return false;
        }
        return true;
    }

    public function hello($host = '')
    {
        if ($this->sendCommand('HELO', 'HELO ' . $host, 250)) {
            return true;
        }
        return $this->sendCommand('EHLO', 'EHLO ' . $host, 250);
    }

    public function mail($from)
    {
        $useVerp = false;
        return $this->sendCommand('MAIL FROM', 'MAIL FROM:<' . $from . '>' . ($useVerp ? ' XVERP' : ''), 250);
    }

    public function recipient($to)
    {
        return $this->sendCommand('RCPT TO', 'RCPT TO:<' . $to . '>' , [250, 251]);
    }

    public function data($msg_data)
    {
        if (!$this->sendCommand('DATA', 'DATA', 354)) {
            return false;
        }
        $msg_data = str_replace("\r\n", "\n", $msg_data);
        $msg_data = str_replace("\r", "\n", $msg_data);
        $lines = explode("\n", $msg_data);
        $field = substr($lines[0], 0, 4);
        $in_headers = $field !== 'To: ' && $field !== 'From:' && $field !== 'Subject:';
        foreach ($lines as $line) {
            $lines_out = [];
            if ($in_headers && $line === '') {
                $in_headers = false;
            }
            while (isset($line[self::MAX_LINE_LENGTH])) {
                $pos = strrpos(substr($line, 0, self::MAX_LINE_LENGTH), ' ');
                if (!$pos) {
                    $pos = self::MAX_LINE_LENGTH - 1;
                    $lines_out[] = substr($line, 0, $pos);
                    $line = substr($line, $pos);
                } else {
                    $lines_out[] = substr($line, 0, $pos);
                    $line = substr($line, $pos + 1);
                }
                if ($in_headers) {
                    $line = "\t" . $line;
                }
            }
            $lines_out[] = $line;
            foreach ($lines_out as $line_out) {
                if (!empty($line_out) && $line_out[0] === '.') {
                    $line_out = '.' . $line_out;
                }
                $this->smtp_conn->fwrite($line_out . self::CRLF);
            }
        }
        $this->sendCommand('DATA END', self::CRLF . '.', 250);
        return true;
    }

    public function reset()
    {
        return $this->sendCommand('RSET', 'RSET', 250);
    }

    public function quit()
    {
        $this->sendCommand('QUIT', 'QUIT', 221);
        $this->close();
    }

    public function close()
    {
        $this->error = [];
        $this->server_caps = null;
        $this->helo_rply = null;
        if (is_resource($this->smtp_conn)) {
            fclose($this->smtp_conn);
            $this->smtp_conn = null;
        }
    }

    protected function sendCommand($command, $commandstring, $expect)
    {
        $this->edebug('CLIENT -> SERVER: ' . $commandstring);
        if (!$this->smtp_conn->fwrite($commandstring . self::CRLF)) {
            $this->error = ['error' => 'Failed to send command ' . $command];
            return false;
        }
        $this->last_reply = $this->get_lines();
        $this->edebug('SERVER -> CLIENT: ' . $this->last_reply);
        $code = (int)substr($this->last_reply, 0, 3);
        if (is_array($expect)) {
            return in_array($code, $expect, true);
        }
        return $code === $expect;
    }

    protected function get_lines()
    {
        if (!is_resource($this->smtp_conn)) {
            return '';
        }
        $data = '';
        stream_set_timeout($this->smtp_conn, $this->Timeout);
        while ($str = fgets($this->smtp_conn, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') {
                break;
            }
            if (substr($str, 3, 2) === '-') {
                continue;
            }
            break;
        }
        return $data;
    }

    protected function edebug($str)
    {
        if ($this->do_debug <= 0) {
            return;
        }
        if ($this->Debugoutput === 'echo') {
            echo $str . "\n";
        } elseif ($this->Debugoutput === 'html') {
            echo htmlentities($str, ENT_QUOTES, 'UTF-8') . "<br>\n";
        } elseif ($this->Debugoutput === 'error_log') {
            error_log($str);
        }
    }
}