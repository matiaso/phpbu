<?php

namespace phpbu\App\Backup\Sync;

use phpbu\App\Backup\Collector;
use phpbu\App\Backup\Path;
use phpbu\App\Backup\Target;
use phpbu\App\Configuration;
use phpbu\App\Result;
use phpbu\App\Util;
use phpseclib;

/**
 * Sftp sync
 *
 * @package    phpbu
 * @subpackage Backup
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://phpbu.de/
 * @since      Class available since Release 1.0.0
 */
class Sftp extends Xtp
{
    /**
     * @var phpseclib\Net\SFTP
     */
    protected $sftp;

    /**
     * Remote path where to put the backup
     *
     * @var Path
     */
    protected $remotePath;

    /**
     * Remote port of sftp server
     *
     * @var string
     */
    protected $port;

    /**
     * @var int
     */
    protected $time;

    /**
     * @var string
     */
    protected $privateKey;

    /**
     * (non-PHPDoc)
     *
     * @param array $config
     * @throws \phpbu\App\Backup\Sync\Exception
     * @throws \phpbu\App\Exception
     * @see    \phpbu\App\Backup\Sync::setup()
     */
    public function setup(array $config)
    {
        // make sure either password or private key is configured
        if (!Util\Arr::isSetAndNotEmptyString($config, 'password')
            && !Util\Arr::isSetAndNotEmptyString($config, 'key')) {
            throw new Exception('\'password\' or \'key\' must be presented');
        }
        parent::setup($config);

        $this->time = time();
        $privateKey = Util\Arr::getValue($config, 'key', '');
        if (!empty($privateKey)) {
            // get absolute private key path
            $privateKey = Util\Path::toAbsolutePath($privateKey, Configuration::getWorkingDirectory());
            if (!file_exists($privateKey)) {
                throw new Exception("Private key not found at specified path");
            }
        }
        $this->privateKey = $privateKey;
        $this->remotePath = new Path($config['path'], $this->time);
        $this->port       = Util\Arr::getValue($config, 'port', '22');

        $this->setUpCleanable($config);
    }

    /**
     * Check for required loaded libraries or extensions.
     *
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    protected function checkRequirements()
    {
        if (!class_exists('\\phpseclib\\Net\\SFTP')) {
            throw new Exception('phpseclib not installed - use composer to install "phpseclib/phpseclib" version 2.x');
        }
    }

    /**
     * Return implemented (*)TP protocol name.
     *
     * @return string
     */
    protected function getProtocolName()
    {
        return 'SFTP';
    }

    /**
     * (non-PHPDoc)
     *
     * @param Target $target * @param  \phpbu\App\Result        $result
     * @throws \phpbu\App\Backup\Sync\Exception
     * @see    \phpbu\App\Backup\Sync::sync()
     */
    public function sync(Target $target, Result $result)
    {
        $remoteFilename = $target->getFilename();
        $localFile      = $target->getPathname();
        $maxRetries     = $target->getMaxRetries();
        $retryDelay     = $target->getRetryDelay();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $sftp = $this->createClient();
                $this->validateRemotePath();

                foreach ($this->getRemoteDirectoryList() as $dir) {
                    if (!$sftp->is_dir($dir)) {
                        $result->debug(sprintf('creating remote dir \'%s\'', $dir));
                        $sftp->mkdir($dir);
                    }
                    $result->debug(sprintf('change to remote dir \'%s\'', $dir));
                    $sftp->chdir($dir);
                }

                $result->debug(sprintf('Preparing to upload file \'%s\' as \'%s\'', $localFile, $remoteFilename));

                // Use the RESUME flag to enable resuming
                $uploadResult = $sftp->put(
                    $remoteFilename,
                    $localFile,
                    phpseclib\Net\SFTP::SOURCE_LOCAL_FILE | phpseclib\Net\SFTP::RESUME,
                    -1,
                    -1,
                    function ($sent) use ($result, $localFile) {
                        $totalSize  = filesize($localFile);
                        $percentage = round(($sent / $totalSize) * 100, 2);
                        $result->debug(sprintf('Uploaded %d of %d bytes (%.2f%%)', $sent, $totalSize, $percentage));
                    }
                );

                if (!$uploadResult) {
                    throw new Exception(
                        sprintf('Error uploading file: %s - %s', $localFile, $sftp->getLastSFTPError())
                    );
                }

                $result->debug(sprintf('File uploaded successfully on attempt %d', $attempt));

                // run remote cleanup
                $this->cleanup($target, $result);

                // Exit the retry loop if successful
                return;
            } catch (Exception $e) {
                $result->debug(sprintf('Upload failed on attempt %d: %s', $attempt, $e->getMessage()));

                if ($attempt < $maxRetries) {
                    $result->debug(sprintf('Retrying in %d seconds...', $retryDelay));
                    sleep($retryDelay);
                } else {
                    // If we've exhausted all retries, rethrow the exception
                    throw new Exception(
                        sprintf('Failed to upload after %d attempts: %s', $maxRetries, $e->getMessage())
                    );
                }
            }
        }
    }

    /**
     * Create a sftp handle.
     *
     * @return \phpseclib\Net\SFTP
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    protected function createClient(): phpseclib\Net\SFTP
    {
        if (!$this->sftp) {
            // silence phpseclib errors
            $old        = error_reporting(0);
            $this->sftp = new phpseclib\Net\SFTP($this->host, $this->port);
            $auth       = $this->getAuth();

            if (!$this->sftp->login($this->user, $auth)) {
                error_reporting($old);
                throw new Exception(
                    sprintf(
                        'authentication failed for %s@%s%s',
                        $this->user,
                        $this->host,
                        empty($this->password) ? '' : ' with password ****'
                    )
                );
            }
            // restore old error reporting
            error_reporting($old);
        }

        return $this->sftp;
    }

    /**
     * If a relative path is configured, determine absolute path and update local remote.
     *
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    protected function validateRemotePath()
    {
        if (!Util\Path::isAbsolutePath($this->remotePath->getPath())) {
            $sftp             = $this->createClient();
            $this->remotePath = new Path($sftp->realpath('.') . '/' . $this->remotePath->getPathRaw(), $this->time);
        }
    }

    /**
     * Return a phpseclib authentication thingy.
     *
     * @return \phpseclib\Crypt\RSA|string
     */
    private function getAuth()
    {
        $auth = $this->password;
        // if private key should be used
        if ($this->privateKey) {
            $auth = new phpseclib\Crypt\RSA();
            $auth->loadKey(file_get_contents($this->privateKey));
            if ($this->password) {
                $auth->setPassword($this->password);
            }
        }

        return $auth;
    }

    /**
     * Return list of remote directories to travers.
     *
     * @return array
     */
    private function getRemoteDirectoryList(): array
    {
        return Util\Path::getDirectoryListFromAbsolutePath($this->remotePath->getPath());
    }

    /**
     * Creates collector for SFTP
     *
     * @param \phpbu\App\Backup\Target $target
     * @return \phpbu\App\Backup\Collector
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    protected function createCollector(Target $target): Collector
    {
        return new Collector\Sftp($target, $this->remotePath, $this->createClient());
    }
}
