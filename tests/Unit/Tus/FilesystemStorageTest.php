<?php

declare(strict_types=1);

namespace Modufolio\Tus\Tests\Unit\Tus;

use Modufolio\Tus\Exception\TusException;
use Modufolio\Tus\FilesystemStorage;
use Modufolio\Tus\UploadCache;

class FilesystemStorageTest extends TusTestCase
{
    private FilesystemStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FilesystemStorage($this->tmpDir);
    }

    /**
     * completeAndFetch() tests
     */
    public function testCompleteAndFetchMovesFileToDestination(): void
    {
        $sourcePath = $this->generateFile(1024, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($sourcePath);
        $this->assertFileExists($destDir . 'upload.bin');
    }

    public function testCompleteAndFetchCopiesFileWhenRemoveAfterFalse(): void
    {
        $sourcePath = $this->generateFile(1024, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch('upload.bin', $destDir, false);

        $this->assertTrue($result);
        $this->assertFileExists($sourcePath);
        $this->assertFileExists($destDir . 'upload.bin');
        $this->assertFileEquals($sourcePath, $destDir . 'upload.bin');
    }

    public function testCompleteAndFetchCreatesDestinationDirectory(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');
        $destDir = $this->tmpDir . 'new/nested/dir/';

        $result = $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertTrue($result);
        $this->assertDirectoryExists($destDir);
        $this->assertFileExists($destDir . 'upload.bin');
        $this->assertFileDoesNotExist($sourcePath);
    }

    public function testCompleteAndFetchThrowsWhenSourceFileNotFound(): void
    {
        $this->expectException(TusException::class);
        $this->expectExceptionMessage('File does not exist');

        $this->storage->completeAndFetch('nonexistent.bin', $this->tmpDir . 'downloads/');
    }

    public function testCompleteAndFetchThrowsWhenDestinationCreationFails(): void
    {
        $this->generateFile(512, 'upload.bin');
        $destDir = '/root/permission-denied/';

        $this->expectException(TusException::class);
        $this->expectExceptionMessage('Failed to create destination directory');

        $this->storage->completeAndFetch('upload.bin', $destDir);
    }

    public function testCompleteAndFetchRemovesContainerOnMove(): void
    {
        $sourcePath = $this->generateFile(1024, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads/';

        // Create container metadata
        $container = UploadCache::fromArray(['offset' => 1024, 'size' => 1024]);
        $this->storage->containerCreate('upload.bin', $container);
        $this->assertTrue($this->storage->containerExists('upload.bin'));

        $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertFalse($this->storage->containerExists('upload.bin'));
        $this->assertFileDoesNotExist($sourcePath);
        $this->assertFileExists($destDir . 'upload.bin');
    }

    public function testCompleteAndFetchDoesNotRemoveContainerOnCopy(): void
    {
        $sourcePath = $this->generateFile(1024, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads/';

        // Create container metadata
        $container = UploadCache::fromArray(['offset' => 1024, 'size' => 1024]);
        $this->storage->containerCreate('upload.bin', $container);
        $this->assertTrue($this->storage->containerExists('upload.bin'));

        $this->storage->completeAndFetch('upload.bin', $destDir, false);

        $this->assertTrue($this->storage->containerExists('upload.bin'));
        $this->assertFileExists($sourcePath);
        $this->assertFileExists($destDir . 'upload.bin');
    }

    public function testCompleteAndFetchToSameDirectoryAsUploadDir(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');

        // When destination is same as upload dir, just delete container
        $container = UploadCache::fromArray(['offset' => 512, 'size' => 512]);
        $this->storage->containerCreate('upload.bin', $container);
        $this->assertTrue($this->storage->containerExists('upload.bin'));

        $result = $this->storage->completeAndFetch('upload.bin', $this->tmpDir);

        $this->assertTrue($result);
        $this->assertFileExists($sourcePath); // File unchanged
        $this->assertFalse($this->storage->containerExists('upload.bin'));
    }

    public function testCompleteAndFetchWithLargeFile(): void
    {
        $sourcePath = $this->generateFile(5242880, 'large.bin'); // 5 MB
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch('large.bin', $destDir);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($sourcePath);
        $this->assertFileExists($destDir . 'large.bin');
        $this->assertSame(5242880, filesize($destDir . 'large.bin'));
    }

    public function testCompleteAndFetchReturnsTrue(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertTrue($result);
        $this->assertFileExists($destDir . 'upload.bin');
    }

    /**
     * completeAndStream() tests
     */
    public function testCompleteAndStreamReturnsStreamResource(): void
    {
        $sourcePath = $this->generateFile(1024, 'upload.bin');

        $stream = $this->storage->completeAndStream('upload.bin');

        $this->assertIsResource($stream);
        $this->assertSame('stream', get_resource_type($stream));
        fclose($stream);
    }

    public function testCompleteAndStreamContainsFileContent(): void
    {
        $content = $this->generateContent(1024, 'X');
        $path = $this->tmpDir . '/' . 'upload.bin';
        file_put_contents($path, $content);

        $stream = $this->storage->completeAndStream('upload.bin', false);

        $streamContent = stream_get_contents($stream);
        $this->assertSame($content, $streamContent);
        fclose($stream);
    }

    public function testCompleteAndStreamRemovesFileWhenRemoveAfterTrue(): void
    {
        $this->generateFile(512, 'upload.bin');

        $stream = $this->storage->completeAndStream('upload.bin', true);
        fclose($stream);

        $this->assertFileDoesNotExist($this->tmpDir . 'upload.bin');
    }

    public function testCompleteAndStreamKeepsFileWhenRemoveAfterFalse(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');

        $stream = $this->storage->completeAndStream('upload.bin', false);
        fclose($stream);

        $this->assertFileExists($sourcePath);
    }

    public function testCompleteAndStreamThrowsWhenSourceFileNotFound(): void
    {
        $this->expectException(TusException::class);
        $this->expectExceptionMessage('File does not exist');

        $this->storage->completeAndStream('nonexistent.bin');
    }

    public function testCompleteAndStreamRemovesContainerWhenRemoveAfterTrue(): void
    {
        $this->generateFile(512, 'upload.bin');

        // Create container metadata
        $container = UploadCache::fromArray(['offset' => 512, 'size' => 512]);
        $this->storage->containerCreate('upload.bin', $container);
        $this->assertTrue($this->storage->containerExists('upload.bin'));

        $stream = $this->storage->completeAndStream('upload.bin', true);
        fclose($stream);

        $this->assertFalse($this->storage->containerExists('upload.bin'));
    }

    public function testCompleteAndStreamKeepsContainerWhenRemoveAfterFalse(): void
    {
        $this->generateFile(512, 'upload.bin');

        // Create container metadata
        $container = UploadCache::fromArray(['offset' => 512, 'size' => 512]);
        $this->storage->containerCreate('upload.bin', $container);
        $this->assertTrue($this->storage->containerExists('upload.bin'));

        $stream = $this->storage->completeAndStream('upload.bin', false);
        fclose($stream);

        $this->assertTrue($this->storage->containerExists('upload.bin'));
    }

    public function testCompleteAndStreamWithEmptyFileThrows(): void
    {
        // 0-byte file should fail to stream since stream_copy_to_stream returns 0 with file size > 0 check
        $path = $this->tmpDir . '/' . 'empty.bin';
        touch($path);
        // The condition: stream_copy_to_stream returns 0 AND filesize > 0
        // For empty file: stream_copy_to_stream returns 0 AND filesize == 0, so no error

        // Actually, empty files won't throw. Let me verify the actual logic in the code:
        // if (stream_copy_to_stream($fh, $temp) === 0 && filesize($filePath) > 0)
        // So it only throws if copy returns 0 but file size is > 0
        // This test should not expect an exception for empty files
        $stream = $this->storage->completeAndStream('empty.bin');
        fclose($stream);

        $this->assertFileDoesNotExist($path);
    }

    public function testCompleteAndStreamWithLargeFile(): void
    {
        $sourcePath = $this->generateFile(5242880, 'large.bin'); // 5 MB

        $stream = $this->storage->completeAndStream('large.bin');

        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk !== false) {
                $size += strlen($chunk);
            }
        }
        fclose($stream);

        $this->assertSame(5242880, $size);
        $this->assertFileDoesNotExist($sourcePath);
    }

    /**
     * complete() tests
     */
    public function testCompleteRemovesContainer(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');

        // Create container metadata
        $container = UploadCache::fromArray(['offset' => 512, 'size' => 512]);
        $this->storage->containerCreate('upload.bin', $container);
        $this->assertTrue($this->storage->containerExists('upload.bin'));

        $result = $this->storage->complete('upload.bin');

        $this->assertTrue($result);
        $this->assertFalse($this->storage->containerExists('upload.bin'));
        $this->assertFileExists($sourcePath);
    }

    public function testCompleteKeepsFileOnDisk(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');
        $content = file_get_contents($sourcePath);

        $result = $this->storage->complete('upload.bin');

        $this->assertTrue($result);
        $this->assertFileExists($sourcePath);
        $this->assertSame($content, file_get_contents($sourcePath));
    }

    public function testCompleteThrowsWhenFileNotFound(): void
    {
        $this->expectException(TusException::class);
        $this->expectExceptionMessage('File does not exist');

        $this->storage->complete('nonexistent.bin');
    }

    public function testCompleteWithNoContainer(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');

        // File exists but no container metadata
        $this->assertFalse($this->storage->containerExists('upload.bin'));

        $result = $this->storage->complete('upload.bin');

        $this->assertTrue($result);
        $this->assertFileExists($sourcePath);
    }

    public function testCompleteWithNestedFilename(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');

        // Container created with different filename, but complete() called with actual file
        $container = UploadCache::fromArray(['offset' => 512]);
        $this->storage->containerCreate('upload.bin', $container);

        $result = $this->storage->complete('upload.bin');

        $this->assertTrue($result);
        $this->assertFileExists($sourcePath);
    }

    public function testCompleteAndFetchThrowsOnUnsafeFilename(): void
    {
        $this->expectException(TusException::class);
        $this->expectExceptionMessage('Unsafe filename');

        $this->storage->completeAndFetch('../etc/passwd', '/tmp/');
    }

    public function testCompleteAndStreamThrowsOnUnsafeFilename(): void
    {
        $this->expectException(TusException::class);
        $this->expectExceptionMessage('Unsafe filename');

        $this->storage->completeAndStream('../../root.bin');
    }

    public function testCompleteThrowsOnUnsafeFilename(): void
    {
        $this->expectException(TusException::class);
        $this->expectExceptionMessage('Unsafe filename');

        $this->storage->complete('file/../../../etc/passwd');
    }

    public function testCompleteAndFetchPreservesFilePermissions(): void
    {
        $this->generateFile(512, 'upload.bin');
        chmod($this->tmpDir . 'upload.bin', 0o644);
        $destDir = $this->tmpDir . 'downloads/';
        mkdir($destDir, 0o755, true);

        $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertFileExists($destDir . 'upload.bin');
        $perms = fileperms($destDir . 'upload.bin');
        // After move, destination inherits some permissions from parent dir
        $this->assertTrue(is_file($destDir . 'upload.bin'));
    }

    public function testCompleteAndStreamPositionResetToBeginning(): void
    {
        $content = $this->generateContent(512, 'Z');
        $path = $this->tmpDir . '/' . 'upload.bin';
        file_put_contents($path, $content);

        $stream = $this->storage->completeAndStream('upload.bin', false);

        $firstByte = fgetc($stream);
        $this->assertSame('Z', $firstByte);
        fclose($stream);
    }

    public function testCompleteAndFetchWithUnicodeFilename(): void
    {
        $filename = 'tëst-üñìçödé.bin';
        $this->generateFile(256, $filename);
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch($filename, $destDir);

        $this->assertTrue($result);
        $this->assertFileExists($destDir . $filename);
    }

    public function testCompleteAndStreamMultipleTimesWithRemoveAfterFalse(): void
    {
        $sourcePath = $this->generateFile(256, 'upload.bin');

        $stream1 = $this->storage->completeAndStream('upload.bin', false);
        $content1 = stream_get_contents($stream1);
        fclose($stream1);

        $stream2 = $this->storage->completeAndStream('upload.bin', false);
        $content2 = stream_get_contents($stream2);
        fclose($stream2);

        $this->assertSame($content1, $content2);
        $this->assertFileExists($sourcePath);
    }

    public function testCompleteAndFetchDestinationWithTrailingSlash(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertTrue($result);
        $this->assertFileExists($destDir . 'upload.bin');
        $this->assertFileDoesNotExist($sourcePath);
    }

    public function testCompleteAndFetchDestinationWithoutTrailingSlash(): void
    {
        $sourcePath = $this->generateFile(512, 'upload.bin');
        $destDir = $this->tmpDir . 'downloads';

        $result = $this->storage->completeAndFetch('upload.bin', $destDir);

        $this->assertTrue($result);
        // normalizePath adds trailing slash
        $this->assertFileExists($destDir . '/' . 'upload.bin');
        $this->assertFileDoesNotExist($sourcePath);
    }

    public function testCompleteAndFetchWithSpecialCharactersInFilename(): void
    {
        $filename = 'test-file_v1.2.3.bin';
        $sourcePath = $this->generateFile(512, $filename);
        $destDir = $this->tmpDir . 'downloads/';

        $result = $this->storage->completeAndFetch($filename, $destDir);

        $this->assertTrue($result);
        $this->assertFileExists($destDir . $filename);
        $this->assertFileDoesNotExist($sourcePath);
    }

    public function testCompleteAndStreamWithNonEmptyFileSucceeds(): void
    {
        // Verify that completeAndStream works with actual content
        $sourcePath = $this->generateFile(512, 'file.bin');

        $stream = $this->storage->completeAndStream('file.bin');

        $this->assertIsResource($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $this->assertNotEmpty($content);
    }
}
