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
     * @see    \phpbu\App\Backup\Sync::setup()
     * @param  array $config
     * @throws \phpbu\App\Backup\Sync\Exception
     * @throws \phpbu\App\Exception
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
     * @see    \phpbu\App\Backup\Sync::sync()
     * @param  \phpbu\App\Backup\Target $target
     * @param  \phpbu\App\Result        $result
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    public function sync(Target $target, Result $result)
    {
        $sftp           = $this->createClient();
        $remoteFilename = $target->getFilename();
        $localFile      = $target->getPathname();
        $maxRetries      = $target->getMaxRetries();
        $retryDelay      = $target->getRetryDelay();

        $this->validateRemotePath();

        foreach ($this->getRemoteDirectoryList() as $dir) {
            if (!$sftp->is_dir($dir)) {
                $result->debug(sprintf('creating remote dir \'%s\'', $dir));
                $sftp->mkdir($dir);
            }
            $result->debug(sprintf('change to remote dir \'%s\'', $dir));
            $sftp->chdir($dir);
        }

        $attempt = 0;
        $remoteFileSize = $sftp->filesize($remoteFilename) ?: 0;  // Get the size of the remote file (if exists)
        $localFileSize = filesize($localFile);

        if ($remoteFileSize >= $localFileSize) {
            $result->debug(sprintf('file already fully uploaded: %s', $remoteFilename));
            return;
        }

        $result->debug(sprintf('store file \'%s\' as \'%s\' from position %d', $localFile, $remoteFilename, $remoteFileSize));
        $result->debug(sprintf('last error \'%s\'', $sftp->getLastSFTPError()));

        $handle = fopen($localFile, 'rb');
        if ($handle === false) {
            throw new Exception(sprintf('Error opening local file: %s', $localFile));
        }

        // Move to the correct position in the local file to resume upload
        fseek($handle, $remoteFileSize);

        $success = true;
        while (!feof($handle)) {
            $data = fread($handle, 4096);
            if ($data === false) {
                $success = false;
                break;
            }

            // Write to the remote file starting from the correct position
            if (!$sftp->put($remoteFilename, $data, phpseclib\Net\SFTP::SOURCE_STRING, $remoteFileSize)) {
                $success = false;
                break;
            }
            $remoteFileSize += strlen($data);
        }

        fclose($handle);

        if ($success) {
            // Successfully uploaded the file
            $this->cleanup($target, $result);
            return;
        } else {
            $attempt++;
            $result->debug(sprintf('Error uploading file: %s - %s', $localFile, $sftp->getLastSFTPError()));

            if ($attempt < $maxRetries) {
                $result->debug(sprintf('Retrying upload... attempt %d of %d', $attempt + 1, $maxRetries));
                sleep($retryDelay);
            } else {
                throw new Exception(sprintf('Error uploading file after %d attempts: %s - %s', $maxRetries, $localFile, $sftp->getLastSFTPError()));
            }
        }

        $this->cleanup($target, $result);
    }

    /**
     * Create a sftp handle.
     *
     * @return \phpseclib\Net\SFTP
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    protected function createClient() : phpseclib\Net\SFTP
    {
        if (!$this->sftp) {
            // silence phpseclib errors
            $old  = error_reporting(0);
            $this->sftp = new phpseclib\Net\SFTP($this->host, $this->port);
            $auth = $this->getAuth();

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
    private function getRemoteDirectoryList() : array
    {
        return Util\Path::getDirectoryListFromAbsolutePath($this->remotePath->getPath());
    }

    /**
     * Creates collector for SFTP
     *
     * @param  \phpbu\App\Backup\Target $target
     * @return \phpbu\App\Backup\Collector
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    protected function createCollector(Target $target): Collector
    {
        return new Collector\Sftp($target, $this->remotePath, $this->createClient());
    }
}
