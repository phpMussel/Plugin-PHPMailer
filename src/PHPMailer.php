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
 * This file: PHPMailer-phpMussel linker (last modified: 2020.06.14).
 */

namespace phpMussel\PHPMailer;

class PHPMailer
{
    /**
     * @var \phpMussel\Core\Loader The instantiated loader object.
     */
    private $Loader;

    /**
     * @var string The path to the core asset files.
     */
    private $AssetsPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;

    /**
     * @var string The path to the core L10N files.
     */
    private $L10NPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'l10n' . DIRECTORY_SEPARATOR;

    /**
     * Construct the linker instance.
     *
     * @param \phpMussel\Core\Loader $Loader The instantiated loader object, passed by reference.
     */
    public function __construct(\phpMussel\Core\Loader &$Loader)
    {
        /** Link the loader to this instance. */
        $this->Loader = &$Loader;

        /** Load configuration defaults and perform fallbacks. */
        if (
            is_readable($this->AssetsPath . 'config.yml') &&
            $Configuration = $this->Loader->readFile($this->AssetsPath . 'config.yml')
        ) {
            $Defaults = [];
            $this->Loader->YAML->process($Configuration, $Defaults);
            if (isset($Defaults)) {
                $this->Loader->fallback($Defaults);
            }
        }

        /** Load L10N data. */
        $this->Loader->loadL10N($this->Loader->L10NPath);

        /**
         * Writes to the PHPMailer event log.
         *
         * @param string $Data What to write.
         * @return bool True on success; False on failure.
         */
        $this->Loader->Events->addHandler('writeToPHPMailerEventLog', function (string $Data): bool {
            /** Guard. */
            if (!$this->Loader->Configuration['phpmailer']['event_log']) {
                return false;
            }

            /** Applies formatting for dynamic log filenames. */
            $EventLog = $this->Loader->timeFormat($this->Loader->Time, $this->Loader->Configuration['phpmailer']['event_log']);

            $WriteMode = (!file_exists($this->AssetsPath . $EventLog) || (
                $this->Loader->Configuration['core']['truncate'] > 0 &&
                filesize($this->AssetsPath . $EventLog) >= $this->readBytes($this->Loader->Configuration['core']['truncate'])
            )) ? 'w' : 'a';

            /** Build the path to the log and write it. */
            if ($phpMussel['BuildLogPath']($EventLog)) {
                $Handle = fopen($this->AssetsPath . $EventLog, $WriteMode);
                fwrite($Handle, $Data);
                fclose($Handle);
                if ($WriteMode === 'w') {
                    $phpMussel['LogRotation']($this->Loader->Configuration['phpmailer']['event_log']);
                }
                return true;
            }

            return false;
        });
    }
}
