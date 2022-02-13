<?php
/**
 * This file is a part of the phpMussel\PHPMailer package.
 * Homepage: https://phpmussel.github.io/
 *
 * PHPMUSSEL COPYRIGHT 2013 AND BEYOND BY THE PHPMUSSEL TEAM.
 *
 * License: GNU/GPLv2
 * @see LICENSE.txt
 *
 * This file: PHPMailer-phpMussel linker (last modified: 2022.02.13).
 */

namespace phpMussel\PHPMailer;

class Linker
{
    /**
     * @var \phpMussel\Core\Loader The instantiated loader object.
     */
    private $Loader;

    /**
     * @var string The path to the linker asset files.
     */
    private $AssetsPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;

    /**
     * @var string The path to the linker L10N files.
     */
    private $L10NPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'l10n' . DIRECTORY_SEPARATOR;

    /**
     * Construct the linker instance.
     *
     * @param \phpMussel\Core\Loader $Loader The instantiated loader object, passed by reference.
     * @return void
     */
    public function __construct(\phpMussel\Core\Loader &$Loader)
    {
        /** Link the loader to this instance. */
        $this->Loader = &$Loader;

        /** Load PHPMailer-phpMussel linker configuration defaults and perform fallbacks. */
        if (
            is_readable($this->AssetsPath . 'config.yml') &&
            $Configuration = $this->Loader->readFile($this->AssetsPath . 'config.yml')
        ) {
            $Defaults = [];
            $this->Loader->YAML->process($Configuration, $Defaults);
            $this->Loader->fallback($Defaults);
            $this->Loader->ConfigurationDefaults = array_merge_recursive($this->Loader->ConfigurationDefaults, $Defaults);
        }

        /** Register log paths. */
        $this->Loader->InstanceCache['LogPaths'][] = $this->Loader->Configuration['phpmailer']['event_log'];

        /** Load L10N data. */
        $this->Loader->loadL10N($this->L10NPath);

        /** Flag for whether to enable two-factor authentication. */
        $this->Loader->InstanceCache['enable_two_factor'] = &$this->Loader->Configuration['phpmailer']['enable_two_factor'];

        /** Address for where to send blocked upload notifications (if so desired). */
        $this->Loader->InstanceCache['enable_notifications'] = &$this->Loader->Configuration['phpmailer']['enable_notifications'];

        /**
         * Writes to the PHPMailer event log.
         *
         * @param string $Data What to write.
         * @return bool True on success; False on failure.
         */
        $this->Loader->Events->addHandler('writeToPHPMailerEventLog', function (string $Data): bool {
            /** Guard. */
            if (
                $this->Loader->Configuration['phpmailer']['event_log'] === '' ||
                !($EventLog = $this->Loader->buildPath($this->Loader->Configuration['phpmailer']['event_log']))
            ) {
                return false;
            }

            $Truncate = $this->Loader->readBytes($this->Loader->Configuration['core']['truncate']);
            $WriteMode = (!file_exists($EventLog) || ($Truncate > 0 && filesize($EventLog) >= $Truncate)) ? 'wb' : 'ab';
            $Handle = fopen($EventLog, $WriteMode);
            fwrite($Handle, $Data);
            fclose($Handle);
            $this->Loader->logRotation($this->Loader->Configuration['phpmailer']['event_log']);
            return true;
        });
    }

    /**
     * Sends an email (invoked by the events orchestrator).
     *
     * @param string $NotUsed Must be defined due to the structure of the
     *      events system.
     * @param array $Data Contains variables necessary for sending an email.
     * @return bool True on success; False on failure.
     */
    public function __invoke(string $NotUsed, array $Data): bool
    {
        /** Guard. */
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            throw new \Exception($this->Loader->L10N->getString('state_failed_missing'));
        }

        /**
         * @var array $Recipients An array of recipients to send to.
         */
        $Recipients = $Data[0];

        /**
         * @var string $Subject The subject line of the email.
         */
        $Subject = $Data[1];

        /**
         * @var string $Body The HTML content of the email.
         */
        $Body = $Data[2];

        /**
         * @var string $AltBody The alternative plain-text content of the email.
         */
        $AltBody = $Data[3];

        /**
         * @var array $Attachments An optional array of attachments.
         */
        $Attachments = $Data[4];

        /** Prepare event logging. */
        $EventLogData = sprintf(
            '%s - %s - ',
            $this->Loader->Configuration['legal']['pseudonymise_ip_addresses'] ? $this->Loader->pseudonymiseIP($this->Loader->IPAddr) : $this->Loader->IPAddr,
            $this->Loader->timeFormat($this->Loader->Time, $this->Loader->Configuration['core']['time_format'])
        );

        /** Operation success state. */
        $State = false;

        try {
            /** Create a new PHPMailer instance. */
            $Mail = new \PHPMailer\PHPMailer\PHPMailer();

            /** Tell PHPMailer to use SMTP. */
            $Mail->isSMTP();

            /** Tell PHPMailer which language to use. */
            if ($Language = $this->mapLanguage($this->Loader->Configuration['core']['lang'])) {
                $Mail->setLanguage($Language);
            }

            /** Tell PHPMailer to always use UTF-8. */
            $Mail->CharSet = 'utf-8';

            /** Disable debugging. */
            $Mail->SMTPDebug = 0;

            /** Skip authorisation process for some extreme problematic cases. */
            if ($this->Loader->Configuration['phpmailer']['skip_auth_process']) {
                $Mail->SMTPOptions = ['ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]];
            }

            /** Set mail server hostname. */
            $Mail->Host = $this->Loader->Configuration['phpmailer']['host'];

            /** Set the SMTP port. */
            $Mail->Port = $this->Loader->Configuration['phpmailer']['port'];

            /** Set the encryption system to use. */
            if (
                !empty($this->Loader->Configuration['phpmailer']['smtp_secure']) &&
                $this->Loader->Configuration['phpmailer']['smtp_secure'] !== '-'
            ) {
                $Mail->SMTPSecure = $this->Loader->Configuration['phpmailer']['smtp_secure'];
            }

            /** Set whether to use SMTP authentication. */
            $Mail->SMTPAuth = $this->Loader->Configuration['phpmailer']['smtp_auth'];

            /** Set the username to use for SMTP authentication. */
            $Mail->Username = $this->Loader->Configuration['phpmailer']['username'];

            /** Set the password to use for SMTP authentication. */
            $Mail->Password = $this->Loader->Configuration['phpmailer']['password'];

            /** Set the email sender address and name. */
            $Mail->setFrom(
                $this->Loader->Configuration['phpmailer']['set_from_address'],
                $this->Loader->Configuration['phpmailer']['set_from_name']
            );

            /** Set the optional "reply to" address and name. */
            if (
                !empty($this->Loader->Configuration['phpmailer']['add_reply_to_address']) &&
                !empty($this->Loader->Configuration['phpmailer']['add_reply_to_name'])
            ) {
                $Mail->addReplyTo(
                    $this->Loader->Configuration['phpmailer']['add_reply_to_address'],
                    $this->Loader->Configuration['phpmailer']['add_reply_to_name']
                );
            }

            /** Used by logging when send succeeds. */
            $SuccessDetails = '';

            /** Set the recipient address and name. */
            foreach ($Recipients as $Recipient) {
                if (empty($Recipient['Address']) || empty($Recipient['Name'])) {
                    continue;
                }
                $Mail->addAddress($Recipient['Address'], $Recipient['Name']);
                $SuccessDetails .= (($SuccessDetails) ? ', ' : '') . $Recipient['Name'] . ' <' . $Recipient['Address'] . '>';
            }

            /** Set the subject line of the email. */
            $Mail->Subject = $Subject;

            /** Tell PHPMailer that the email is written using HTML. */
            $Mail->isHTML = true;

            /** Set the HTML body of the email. */
            $Mail->Body = $Body;

            /** Set the alternative, plain-text body of the email. */
            $Mail->AltBody = $AltBody;

            /** Process attachments. */
            if (is_array($Attachments)) {
                foreach ($Attachments as $Attachment) {
                    $Mail->addAttachment($Attachment);
                }
            }

            /** Send it! */
            $State = $Mail->send();

            /** Log the results of the send attempt. */
            $EventLogData .= ($State ? sprintf(
                $this->Loader->L10N->getString('state_email_sent'),
                $SuccessDetails
            ) : $this->Loader->L10N->getString('response_error') . ' - ' . $Mail->ErrorInfo) . "\n";
        } catch (\Exception $e) {
            /** An exeption occurred. Log the information. */
            $EventLogData .= $this->Loader->L10N->getString('response_error') . ' - ' . $e->getMessage() . "\n";
        }

        /** Write to the event log. */
        $this->Loader->Events->fireEvent('writeToPHPMailerEventLog', $EventLogData);

        /** Exit. */
        return $State;
    }

    /**
     * Maps phpMussel language specification to PHPMailer language specification.
     *
     * @param string $Language The language to map between phpMussel-PHPMailer.
     * @return string The language to set (or an empty string if the specified
     *      language isn't available at PHPMailer).
     */
    private function mapLanguage(string $Language): string
    {
        if ($Language === 'zh') {
            $Language = 'zh_cn';
        } elseif ($Language === 'zh-TW') {
            $Language = 'zh';
        }
        $Try = sprintf(
            __DIR__ . '%1$s..%1$s..%1$s..%1$sphpmailer%1$sphpmailer%1$slanguage%1$sphpmailer.lang-%2$s.php',
            DIRECTORY_SEPARATOR,
            $Language
        );
        if (is_readable($Try) && is_file($Try)) {
            return $Language;
        }
        return '';
    }
}
