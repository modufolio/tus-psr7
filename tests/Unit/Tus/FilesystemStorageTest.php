<?php

namespace Modufolio\Tus\Tests\Unit\Tus;

class FilesystemStorageTest extends TusTestCase
{
    public function testFixtureIsLoadedIntoStorage(): void
    {
        $this->loadFixture('sample.txt', 'myfile.txt');

        $filePath = $this->tmpDir . '/myfile.txt';
        $this->assertFileExists($filePath);
        $this->assertSame('Hello TUS Server!', file_get_contents($filePath));
    }

    public function testWriteAndReadFile(): void
    {
        $path = $this->tmpDir . '/test.txt';
        file_put_contents($path, 'abc123');

        $this->assertFileExists($path);
        $this->assertSame('abc123', file_get_contents($path));
    }

    public function testDeleteFile(): void
    {
        $path = $this->tmpDir . '/delete-me.txt';
        file_put_contents($path, 'temp');
        unlink($path);

        $this->assertFileDoesNotExist($path);
    }
}
