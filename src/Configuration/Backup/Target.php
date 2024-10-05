<?php
namespace phpbu\App\Configuration\Backup;

use phpbu\App\Exception;

/**
 * Target Configuration
 *
 * @package    phpbu
 * @subpackage App
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://phpbu.de/
 * @since      Class available since Release 2.0.0
 */
class Target
{
    /**
     * Directory.
     *
     * @var string
     */
    public $dirname;

    /**
     * Filename.
     *
     * @var string
     */
    public $filename;

    /**
     * Compression to use.
     *
     * @var string
     */
    public $compression;

    public string $maxRetries;

    public string $retryDelay;

    /**
     * Constructor.
     *
     * @param string $dir
     * @param string $file
     * @param null $compression
     * @param null $maxRetries
     * @param null $retryDelay
     * @throws Exception
     */
    public function __construct(string $dir, string $file, $compression = null, $maxRetries = null, $retryDelay = null)
    {
        // check dirname and filename
        if ($dir == '' || $file == '') {
            throw new Exception('dirname and filename must be set');
        }
        $this->dirname  = $dir;
        $this->filename = $file;

        if (!empty($compression)) {
            $this->compression = $compression;
        }

        if (!empty($maxRetries)) {
            $this->maxRetries = $maxRetries;
        }

        if (!empty($retryDelay)) {
            $this->retryDelay = $retryDelay;
        }
    }
}
