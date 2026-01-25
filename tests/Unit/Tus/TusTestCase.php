<?php

namespace Modufolio\Tus\Tests\Unit\Tus;


use Modufolio\Tus\Tus\TusServer;
use PHPUnit\Framework\TestCase;

abstract class TusTestCase extends TestCase
{
    protected string $tmpDir;
    protected TusServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fresh temp directory for every test run
        $this->tmpDir = sys_get_temp_dir() . '/tus_tests_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        // Real TusServer using that storage
        $this->server = new TusServer($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Recursively delete the temp dir
        $this->deleteDir($this->tmpDir);
        parent::tearDown();
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

    /**
     * Copy a fixture file into storage
     */
    protected function loadFixture(string $fixtureName, string $destName): void
    {
        $source = __DIR__ . '/fixtures/' . $fixtureName;
        if (!file_exists($source)) {
            throw new \RuntimeException("Fixture {$fixtureName} not found.");
        }
        copy($source, $this->tmpDir . '/' . $destName);
    }
}
