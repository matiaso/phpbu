<?php
namespace phpbu\App\Backup\Sync;

use phpbu\App\Backup\Target;
use phpbu\App\Result;
use phpseclib;
use phpbu\App\BaseMockery;
use PHPUnit\Framework\TestCase;

/**
 * SftpTest
 *
 * @package    phpbu
 * @subpackage tests
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://www.phpbu.de/
 * @since      Class available since Release 1.1.5
 */
class SftpTest extends TestCase
{
    use BaseMockery;

    /**
     * Tests Sftp::setUp
     */
    public function testSetUpOk()
    {
        $sftp = new Sftp();
        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'password' => 'secret',
            'path'     => '/foo'
        ]);

        $this->assertTrue(true, 'no exception should occur');
    }

    /**
     * Tests Sftp::sync
     */
    public function testSync()
    {
        $target = $this->createTargetMock('foo.txt', 'foo.txt.gz');
        $result = $this->createMock(Result::class);
        $result->expects($this->exactly(5))->method('debug');

        $clientMock = $this->createMock(\phpseclib\Net\SFTP::class);
        $clientMock->expects($this->once())->method('realpath')->willReturn('/backup');
        $clientMock->expects($this->once())->method('mkdir')->with('foo');
        $clientMock->expects($this->exactly(3))->method('chdir');
        $clientMock->expects($this->once())->method('put')->willReturn(true);
        $clientMock->expects($this->exactly(3))
                   ->method('is_dir')
                   ->will($this->onConsecutiveCalls(true, true, false));

        $sftp = $this->createPartialMock(Sftp::class, ['createClient']);
        $sftp->method('createClient')->willReturn($clientMock);

        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'password' => 'secret',
            'path'     => 'foo'
        ]);

        $sftp->sync($target, $result);
    }

    public function testSyncFailWithRetry()
    {
        $this->expectException('phpbu\App\Exception');
        $target = $this->createTargetMock('foo.txt', 'foo.txt.gz', );
        $result = $this->createMock(Result::class);
        $result->expects($this->exactly(8))->method('debug');

        $clientMock = $this->createMock(\phpseclib\Net\SFTP::class);
        $clientMock->expects($this->exactly(2))->method('is_dir')->will($this->onConsecutiveCalls(true, true));
        $clientMock->expects($this->exactly(2))->method('chdir');
        $clientMock->expects($this->exactly(3))
        ->method('put')
            ->will($this->onConsecutiveCalls(false, false, false));

        $sftp = $this->createPartialMock(Sftp::class, ['createClient']);
        $sftp->method('createClient')->willReturn($clientMock);

        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'password' => 'secret',
            'path'     => '/foo'
        ]);

        $sftp->sync($target, $result);
    }

    /**
     * Tests Sftp::sync
     */
    public function testSyncWithRemoteCleanup()
    {
        $target = $this->createTargetMock('foo.txt', 'foo.txt.gz');
        $result = $this->createMock(Result::class);
        $result->expects($this->exactly(3))->method('debug');

        $clientMock = $this->createMock(\phpseclib\Net\SFTP::class);
        $clientMock->expects($this->exactly(2))->method('is_dir')->will($this->onConsecutiveCalls(true, true));
        $clientMock->expects($this->exactly(2))->method('chdir');
        $clientMock->expects($this->once())->method('put')->willReturn(true);
        $clientMock->expects($this->once())->method('_list')->willReturn([]);

        $sftp = $this->createPartialMock(Sftp::class, ['createClient']);
        $sftp->method('createClient')->willReturn($clientMock);

        $sftp->setup([
            'host'           => 'example.com',
            'user'           => 'user.name',
            'password'       => 'secret',
            'path'           => '/foo',
            'cleanup.type'   => 'quantity',
            'cleanup.amount' => 99
        ]);

        $sftp->sync($target, $result);
    }

    /**
     * Tests Sftp::sync
     */
    public function testSyncFail()
    {
        $this->expectException('phpbu\App\Exception');
        $target = $this->createTargetMock('foo.txt', 'foo.txt.gz');
        $result = $this->createMock(Result::class);
        $result->expects($this->exactly(4))->method('debug');

        $clientMock = $this->createMock(\phpseclib\Net\SFTP::class);
        $clientMock->expects($this->exactly(2))->method('is_dir')->will($this->onConsecutiveCalls(true, true));
        $clientMock->expects($this->exactly(2))->method('chdir');
        $clientMock->expects($this->once())->method('put')->willReturn(false);

        $sftp = $this->createPartialMock(Sftp::class, ['createClient']);
        $sftp->method('createClient')->willReturn($clientMock);

        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'password' => 'secret',
            'path'     => '/foo'
        ]);

        $sftp->sync($target, $result);
    }

    /**
     * Tests Sftp::simulate
     */
    public function testSimulate()
    {
        $sftp = new Sftp();
        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'password' => 'secret',
            'path'     => 'foo'
        ]);

        $resultStub = $this->createMock(Result::class);
        $resultStub->expects($this->once())
                   ->method('debug');

        $targetStub = $this->createMock(Target::class);

        $sftp->simulate($targetStub, $resultStub);
    }

    /**
     * Tests Sftp::setUp
     */
    public function testSetUpNoHost()
    {
        $this->expectException('phpbu\App\Backup\Sync\Exception');
        $sftp = new Sftp();
        $sftp->setup([
            'user' => 'user.name',
            'path' => 'foo'
        ]);
    }

    /**
     * Tests Sftp::setUp
     */
    public function testSetUpNoUser()
    {
        $this->expectException('phpbu\App\Backup\Sync\Exception');
        $sftp = new Sftp();
        $sftp->setup([
            'host' => 'example.com',
            'path' => 'foo'
        ]);
    }

    /**
     * Tests Sftp::setUp
     */
    public function testSetUpNoPassword()
    {
        $this->expectException('phpbu\App\Backup\Sync\Exception');
        $sftp = new Sftp();
        $sftp->setup([
            'host' => 'example.com',
            'user' => 'user.name',
            'path' => 'foo'
        ]);
    }

    /**
     * Tests Sftp::setUp
     */
    public function testSetUpPathWithRootSlash()
    {
        $this->expectException('phpbu\App\Backup\Sync\Exception');
        $sftp = new Sftp();
        $sftp->setup([
            'host' => 'example.com',
            'user' => 'user.name',
            'path' => '/foo'
        ]);
    }


    /**
     * Tests Sftp::setUp
     */
    public function testSetUpWithPrivateKeyThatDoesNotExist()
    {
        $this->expectException('phpbu\App\Backup\Sync\Exception');
        $sftp = new Sftp();
        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'key'      => PHPBU_TEST_FILES . '/misc/id_rsa_test_not_exist',
            'password' => '12345',
            'path'     => '/foo'
        ]);
    }

    /**
     * Tests absolute path
     */
    public function testSetUpPathWithAbsolutePath()
    {
        $secLibMock = $this->createPHPSecLibSftpMock();
        $target     = $this->createTargetMock('foo.txt', 'foo.txt.gz');
        $result     = $this->getResultMock(5);

        $sftp = $this->createPartialMock(Sftp::class, ['createClient']);
        $sftp->method('createClient')->willReturn($secLibMock);

        $sftp->setup([
            'host'     => 'example.com',
            'user'     => 'user.name',
            'password' => 'secret',
            'path'     => '/foo',
        ]);

        $sftp->sync($target, $result);
    }

    /**
     * Create a app result mock
     *
     * @return \phpseclib\Net\SFTP
     */
    private function createPHPSecLibSftpMock(): \phpseclib\Net\SFTP
    {
        $secLib = $this->createMock(\phpseclib\Net\SFTP::class);

        // Mock 'is_dir' method for consecutive calls using a callback
        $secLib->method('is_dir')
            ->willReturnCallback(function ($dir) {
                static $callCount = 0;
                $callCount++;
                return $callCount === 1 ? true : false;
            });

        $secLib->method('chdir')->willReturn(true);

        // Use expects and once for mkdir
        $secLib->expects($this->once())
            ->method('mkdir')
            ->with('foo')
            ->willReturn(true);

        // Define behavior for put method
        $secLib->method('put')->willReturn(true);

        return $secLib;
    }

    /**
     * Create a app result mock
     *
     * @param  int $expectedDebugCalls
     * @return \phpbu\App\Result
     */
    private function getResultMock(int $expectedDebugCalls)
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->exactly($expectedDebugCalls))->method('debug');

        return $result;
    }
}
