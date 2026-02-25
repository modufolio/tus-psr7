<?php

declare(strict_types=1);

namespace Modufolio\Tus\Tests\Unit\Tus;

use Modufolio\Tus\FilesystemStorage;
use Modufolio\Tus\StorageInterface;
use Modufolio\Tus\TusServer;

class TusServerTest extends TusTestCase
{
    public function testConstructorCreatesServer(): void
    {
        $server = new TusServer($this->tmpDir, 10485760, 5242880);

        $this->assertInstanceOf(TusServer::class, $server);
    }

    public function testProtocolVersion(): void
    {
        $this->assertSame('1.0.0', TusServer::PROTOCOL_VERSION);
    }

    public function testSetAndGetStorageBackend(): void
    {
        $storage = new FilesystemStorage($this->tmpDir);

        $this->server->setStorageBackend($storage);

        $this->assertSame($storage, $this->server->getStorageBackend());
    }

    public function testSetAllowedMimeTypes(): void
    {
        $result = $this->server->setAllowedMimeTypes(['image/jpeg', 'image/png']);

        $this->assertSame($this->server, $result);
    }

    public function testCalculateMaxSize(): void
    {
        $maxSize = TusServer::calculateMaxSize();

        $this->assertIsInt($maxSize);
        $this->assertGreaterThan(0, $maxSize);
    }

    public function testToBytes(): void
    {
        $this->assertSame(1024, TusServer::toBytes('1K'));
        $this->assertSame(1048576, TusServer::toBytes('1M'));
        $this->assertSame(1073741824, TusServer::toBytes('1G'));
        $this->assertSame(100, TusServer::toBytes('100'));
        $this->assertSame(2097152, TusServer::toBytes('2m'));
    }

    public function testToBytesWithVariousFormats(): void
    {
        $this->assertSame(512, TusServer::toBytes('512'));
        $this->assertSame(5120, TusServer::toBytes('5k'));
        $this->assertSame(10485760, TusServer::toBytes('10M'));
        $this->assertSame(2147483648, TusServer::toBytes('2g'));
    }

    public function testCleanTmpDir(): void
    {
        $oldFile = $this->tmpDir . '/old.cachecontainer';
        touch($oldFile, time() - 86500); // older than 1 day

        $recentFile = $this->tmpDir . '/recent.cachecontainer';
        touch($recentFile);

        TusServer::cleanTmpDir($this->tmpDir);

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);
    }

    public function testMimeToExtension(): void
    {
        $this->assertSame('jpg', TusServer::mimeToExtension('image/jpeg'));
        $this->assertSame('png', TusServer::mimeToExtension('image/png'));
        $this->assertSame('mp4', TusServer::mimeToExtension('video/mp4'));
        $this->assertNull(TusServer::mimeToExtension('unknown/mime'));
    }

    public function testHandleOptionsRequest(): void
    {
        $request = $this->makeRequest('OPTIONS', '/tus/test.txt');

        $response = $this->server->handleRequest($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertStringContainsString(TusServer::PROTOCOL_VERSION, $response->getHeaderLine('Tus-Resumable'));
    }

    public function testHandleRequestWithUnsupportedMethod(): void
    {
        $request = $this->makeRequest('GET', '/tus/test.txt');

        $response = $this->server->handleRequest($request);

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testHandleRequestWithUnsupportedProtocolVersion(): void
    {
        $request = $this->makeRequest('OPTIONS', '/tus/test', ['Tus-Resumable' => '0.9.0']);

        $response = $this->server->handleRequest($request);

        $this->assertSame(412, $response->getStatusCode());
    }

    public function testCompleteMethodDelegates(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->once())
            ->method('complete')
            ->with('test.txt')
            ->willReturn(true);

        $this->server->setStorageBackend($storage);

        $result = $this->server->complete('test.txt');

        $this->assertTrue($result);
    }

    public function testCompleteAndFetchMethodDelegates(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->once())
            ->method('completeAndFetch')
            ->with('test.txt', '/destination', true)
            ->willReturn(true);

        $this->server->setStorageBackend($storage);

        $result = $this->server->completeAndFetch('test.txt', '/destination', true);

        $this->assertTrue($result);
    }

    public function testCompleteAndStreamMethodDelegates(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->once())
            ->method('completeAndStream')
            ->with('test.txt', false)
            ->willReturn(null);

        $this->server->setStorageBackend($storage);

        $this->server->completeAndStream('test.txt', false);
    }

    public function testToBytesHandlesEdgeCases(): void
    {
        $this->assertSame(0, TusServer::toBytes('0'));
        $this->assertSame(0, TusServer::toBytes('0K'));
        $this->assertSame(0, TusServer::toBytes('0M'));
    }

    public function testConstructorWithDefaultParameters(): void
    {
        $server = new TusServer($this->tmpDir);

        $this->assertInstanceOf(TusServer::class, $server);
        $this->assertInstanceOf(StorageInterface::class, $server->getStorageBackend());
    }

    public function testConstructorWithCustomMaxSize(): void
    {
        $server = new TusServer($this->tmpDir, 50000000);

        $this->assertInstanceOf(TusServer::class, $server);
    }
}
