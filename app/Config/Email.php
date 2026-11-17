<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

class Email extends BaseConfig
{
    public string $fromEmail  = '';
    public string $fromName   = '';
    public string $recipients = '';

    /**
     * The "user agent"
     */
    public string $userAgent = 'CodeIgniter';

    /**
     * The mail sending protocol: mail, sendmail, smtp
     */
    public string $protocol = 'mail';

    /**
     * The server path to Sendmail.
     */
    public string $mailPath = '/usr/sbin/sendmail';

    /**
     * SMTP Server Hostname
     */
    public string $SMTPHost = '';

    /**
     * Which SMTP authentication method to use: login, plain
     */
    public string $SMTPAuthMethod = 'login';

    /**
     * SMTP Username
     */
    public string $SMTPUser = '';

    /**
     * SMTP Password
     */
    public string $SMTPPass = '';

    /**
     * SMTP Port
     */
    public int $SMTPPort = 25;

    /**
     * SMTP Timeout (in seconds)
     */
    public int $SMTPTimeout = 5;

    /**
     * Enable persistent SMTP connections
     */
    public bool $SMTPKeepAlive = false;

    /**
     * SMTP Encryption.
     *
     * @var string '', 'tls' or 'ssl'. 'tls' will issue a STARTTLS command
     *             to the server. 'ssl' means implicit SSL. Connection on port
     *             465 should set this to ''.
     */
    public string $SMTPCrypto = 'tls';

    /**
     * Enable word-wrap
     */
    public bool $wordWrap = true;

    /**
     * Character count to wrap at
     */
    public int $wrapChars = 76;

    /**
     * Type of mail, either 'text' or 'html'
     */
    public string $mailType = 'text';

    /**
     * Character set (utf-8, iso-8859-1, etc.)
     */
    public string $charset = 'UTF-8';

    /**
     * Whether to validate the email address
     */
    public bool $validate = false;

    /**
     * Email Priority. 1 = highest. 5 = lowest. 3 = normal
     */
    public int $priority = 3;

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $CRLF = "\r\n";

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $newline = "\r\n";

    /**
     * Enable BCC Batch Mode.
     */
    public bool $BCCBatchMode = false;

    /**
     * Number of emails in each BCC batch
     */
    public int $BCCBatchSize = 200;

    /**
     * Enable notify message from server
     */
    public bool $DSN = false;

    public function __construct()
    {
        parent::__construct();
        $this->protocol = (string) env('email.protocol', 'smtp');
        $this->fromEmail = (string) env('email.fromEmail', '');
        $this->fromName = (string) env('email.fromName', 'TechReserve');
        $this->SMTPHost = (string) env('email.SMTPHost', '');
        $this->SMTPUser = (string) env('email.SMTPUser', '');
        $this->SMTPPass = (string) env('email.SMTPPass', '');
        $this->SMTPAuthMethod = (string) env('email.SMTPAuthMethod', 'login');
        $this->SMTPCrypto = (string) env('email.SMTPCrypto', 'tls');
        $this->SMTPPort = $this->integerSetting('email.SMTPPort', 587, 1, 65535);
        $this->SMTPTimeout = $this->integerSetting('email.SMTPTimeout', 10, 1, 120);
        $this->mailType = 'html';
        $this->validate = true;

        if (!in_array($this->SMTPCrypto, ['', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException('email.SMTPCrypto must be empty, tls, or ssl.');
        }
        if (!in_array(strtolower($this->SMTPAuthMethod), ['login', 'plain'], true)) {
            throw new InvalidArgumentException('email.SMTPAuthMethod must be login or plain.');
        }
    }

    private function integerSetting(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var(env($name, $default), FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($name . ' is outside its supported range.');
        }

        return $value;
    }
}
