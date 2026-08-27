<?php

declare(strict_types=1);

namespace Modufolio\Tus\Tests\Unit\Tus;

use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Tus\TusServer;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

abstract class TusTestCase extends TestCase
{
    /** Real on-disk dir — needed for flock, mime_content_type, cross-device rename. */
    protected string $tmpDir;

    protected vfsStreamDirectory $vfsRoot;
    protected vfsStreamDirectory $vfsUploads;
    protected string $vfsUploadDir;

    protected TusServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vfsRoot = vfsStream::setup('root', null, ['uploads' => []]);
        $uploads = $this->vfsRoot->getChild('uploads');
        self::assertInstanceOf(vfsStreamDirectory::class, $uploads);
        $this->vfsUploads = $uploads;
        $this->vfsUploadDir = vfsStream::url('root/uploads/');

        $this->tmpDir = sys_get_temp_dir() . '/tus_tests_' . uniqid();
        mkdir($this->tmpDir, 0o755, true);

        $this->server = new TusServer($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
        parent::tearDown();
    }

    protected function makeRequest(
        string $method = 'OPTIONS',
        string $path = '/tus/test.txt',
        array $headers = [],
        string $body = '',
    ): ServerRequestInterface {
        return new ServerRequest($method, 'http://localhost' . $path, $headers, $body ?: null);
    }

    /**
     * Build a TUS-spec Upload-Metadata header value from a key→value map.
     * Each value is base64-encoded per the spec.
     */
    protected function makeMetadataHeader(array $map): string
    {
        $parts = [];
        foreach ($map as $key => $value) {
            $parts[] = $key . ' ' . base64_encode($value);
        }

        return implode(',', $parts);
    }

    protected function generateContent(int $bytes, string $char = 'A'): string
    {
        return str_repeat($char, $bytes);
    }

    /** Writes to the real temp dir in 64 KB chunks — safe for large sizes. */
    protected function generateFile(int $bytes, string $filename = 'generated.bin', string $char = 'A'): string
    {
        $path = $this->tmpDir . '/' . $filename;
        $fp = fopen($path, 'wb');
        $written = 0;
        while ($written < $bytes) {
            $toWrite = min(65536, $bytes - $written);
            fwrite($fp, str_repeat($char, $toWrite));
            $written += $toWrite;
        }
        fclose($fp);

        return $path;
    }

    protected function generateVfsFile(int $bytes, string $filename = 'generated.bin', string $char = 'A'): string
    {
        $path = $this->vfsUploadDir . $filename;
        file_put_contents($path, str_repeat($char, $bytes));

        return $path;
    }

    protected function loadFixture(string $fixtureName, string $destName): void
    {
        $source = __DIR__ . '/fixtures/' . $fixtureName;
        if (!file_exists($source)) {
            throw new \RuntimeException("Fixture {$fixtureName} not found.");
        }
        copy($source, $this->tmpDir . '/' . $destName);
    }

    protected function loadFixtureIntoVfs(string $fixtureName, string $destName): void
    {
        $source = __DIR__ . '/fixtures/' . $fixtureName;
        if (!file_exists($source)) {
            throw new \RuntimeException("Fixture {$fixtureName} not found.");
        }
        file_put_contents($this->vfsUploadDir . $destName, file_get_contents($source));
    }

    protected function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($dir);
    }
}
