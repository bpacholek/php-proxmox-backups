<?php

namespace IDCT\ProxmoxBackups;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class ProxmoxBackups
{
    const NOTIF_SUCCESS = 'success';
    const NOTIF_FAILURE = 'failure';
    const NOTIF_START = 'started';
    const NOTIF_STORING = 'storing on FTP';
    const NOTIF_STORING_FAILED = 'failed to store on FTP';
    const BACKUP_COMMAND = 'vzdump %s --storage %s --mode snapshot --compress lzo --remove 1 2>&2';

    /**
     * Loaded config array.
     *
     * @var array
     */
    protected $config;

    /**
     * Creates new instance of the ProxmoxBackups tool.
     *
     * @param string $configFilePath
     * @return ProxmoxBackups
     * @throws RuntimeException
     */
    public function __construct($configFilePath)
    {
        if (empty($configFilePath) || !file_exists($configFilePath) || !\is_readable($configFilePath)) {
            throw new \RuntimeException('Provided file does not exist or is not readable. Provided: `' . $configFilePath . '`.');
        }

        if ($configErrors = $this->loadConfig($configFilePath) !== true) {
            throw new \RuntimeException('Provided config file contains errors. Errors: ' . PHP_EOL . join(PHP_EOL, $configErrors));
        }
    }

    /**
     * Performs backups.
     *
     * @return $this
     */
    public function do()
    {
        foreach ($this->config['machines'] as $machine) {
            $this->notify(self::NOTIF_START, $machine, 'Backup started for ' . $machine['id'] . ' into storage `' . $machine['storage'] . '`.');
            $result = shell_exec(sprintf(self::BACKUP_COMMAND, $machine['id'], $machine['storage']));
            if ($result !== null && strpos($result, 'finished successfully')) {
                //success
                $this->notify(self::NOTIF_STORING, $machine, $result);
                if (isset($this->config['global']['ftp']) && isset($machine['ftp.backlog']) && $this->ftpsave($machine) === false) {
                    $this->notify(self::NOTIF_STORING_FAILED, $machine, $result);
                } else {
                    $this->notify(self::NOTIF_SUCCESS, $machine, $result);
                }
            } else {
                //fail
                $this->notify(self::NOTIF_FAILURE, $machine, $result);
            }
        }

        return $this;
    }

    /**
     * Attempts to load the config file.
     * Returns true on success or an arroy of erros on failure.
     *
     * @param string $configFilePath
     * @return boolean|string[]
     * @todo full validation
     */
    protected function loadConfig($configFilePath)
    {
        $errors = [];
        $configDecoded = json_decode(\file_get_contents($configFilePath), true);
        if (!$configDecoded) {
            $errors[] = "Invalid JSON structure. Could not parse.";

            return $errors;
        }

        $this->config = $configDecoded;

        return true;
    }

    /**
     * Sends notification email.
     *
     * @param string $type NOTIF_*
     * @param string[] $machine
     * @param string $contents
     * @return boolean
     * @todo error handling
     */
    protected function mailNotify($type, $machine, $contents)
    {
        $config = $this->config['global']['smtp'];
        $mail = new PHPMailer(true);
        //Server settings
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = '';
        $mail->Port       = $config['port'];

        //Recipients
        $mail->setFrom($config['from_mail'], $config['from_name']);
        $mail->addAddress($machine['email']);     // Add a recipient

        // Content
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = '[' . $machine['id'] . '] Backup: ' . $type;
        $mail->Body    = nl2br($contents);
        $mail->AltBody = $contents;

        try {
            $mail->send();
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Sends a notification via Telegram Bot to a Telegram Channel.
     *
     * @todo send contents
     * @todo parse response
     * @param string $type NOTIF_*
     * @param string[] $machine
     * @param string $contents
     * @return boolean
     */
    protected function telegramNotify($type, $machine, $contents)
    {
        file_get_contents('https://api.telegram.org/bot' . $machine['telegram']['bot'] . '/sendMessage?chat_id=' . $machine['telegram']['channel'] . '&text=' . urlencode('VM Id: ' . $machine['id'] . ' backup: ' . $type));

        return true;
    }

    /**
     * Gets a list of files with details using RAW request via FTP connection.
     *
     * @param resource $con FTP Connection
     * @param string $path path on the FTP server
     * @todo error handling
     * @todo switch to objects
     * @return array
     */
    protected function ftp_get_filelist($con, $path)
    {
        $files = [];
        $contents = ftp_rawlist($con, $path);
        $a = 0;
        if (count($contents)) {
            foreach ($contents as $line) {
                preg_match("#([drwx\-]+)([\s]+)([0-9]+)([\s]+)([0-9]+)([\s]+)([a-zA-Z0-9\.]+)([\s]+)([0-9]+)([\s]+)([a-zA-Z]+)([\s ]+)([0-9]+)([\s]+)([0-9]+):([0-9]+)([\s]+)([a-zA-Z0-9\.\-\_ ]+)#si", $line, $out);
                if ($out[3] != 1 && ($out[18] == "." || $out[18] == "..")) {
                    // do nothing
                } else {
                    $a++;
                    $files[$a]['rights'] = $out[1];
                    $files[$a]['type'] = $out[3] == 1 ? "file" : "folder";
                    $files[$a]['owner_id'] = $out[5];
                    $files[$a]['owner'] = $out[7];
                    $files[$a]['date'] = strtotime($out[11] . " " . $out[13] . " " . $out[13] . ":" . $out[16] . "");
                    $files[$a]['name'] = $out[18];
                }
            }
        }

        return $files;
    }

    /**
     * Sorts file descriptors by date.
     *
     * @param array $al
     * @param array $bl
     * @return int
     */
    protected function datesort($al, $bl)
    {
        if ($al['date'] == $bl['date']) {
            return 0;
        }

        return ($al['date'] > $bl['date']) ? +1 : -1;
    }

    /**
     * Store file on the FTP server and remove last entry if backlog has been exceeded.
     *
     * @param string[] $machine
     * @todo handle removal of more than last backlog entry.
     * @todo handle failed login.
     * @return boolean
     */
    protected function ftpsave($machine)
    {
        $ftpData = $this->config['global']['ftp'];
        $connId = ftp_connect($ftpData['host']);
        ftp_login($connId, $ftpData['login'], $ftpData['pass']);
        $files = ftp_get_filelist($connId, $ftpData['dir'] . $machine['id']);
        usort($files, [$this, 'datesort']);
        //TODO remove all exceeding the backlog
        if (count($files) === $machine['ftp.backlog']) {
            ftp_delete($connId, $ftpData['dir'] . $machine['id'] . '/' . $files[0]['name']);
        }
        foreach (scandir($machine['storage_path'], 1) as $srcFile) {
            if (strstr('-' . $machine['id'] . '-', $srcFile)) {
                return ftp_put($connId, $ftpData['dir'] . $machine['id'] . '/' . $srcFile, $machine['storage_path'] . $srcFile, FTP_BINARY);
            }
        }

        return false;
    }

    /**
     * Sends notifications.
     *
     * @param string $type NOTIF_*
     * @param string[] $machine
     * @param string $contents
     * @return $this
     */
    protected function notify($type, $machine, $contents)
    {
        if (isset($machine['email']) && isset($this->config['global']['smtp'])) {
            $this->mailNotify($type, $machine, $contents);
        }

        if (isset($machine['telegram'])) {
            $this->telegramNotify($type, $machine, $contents);
        }

        return $this;
    }
}
