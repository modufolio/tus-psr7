<?php

namespace Modufolio\Tus\Tus;

use Modufolio\Tus\Exception\TusException;

class FilesystemStorage implements StorageInterface
{
    private static string $containerSuffix = '.cachecontainer';
    private string $uploadDir;
    private string $lockSuffix = '.lock';
    private int $tmpTtl = 86400; // 1 day for stale tmp files

    public function __construct(string $uploadDir, ?string $containerSuffix = null)
    {
        if ($containerSuffix !== null) {
            self::$containerSuffix = $containerSuffix;
        }
        $this->setUploadDir($uploadDir);
    }

    public function setUploadDir(string $uploadDir): bool
    {
        $normalized = $this->normalizePath($uploadDir);
        if (!is_dir($normalized)) {
            if (!@mkdir($normalized, 0755, true)) {
                throw new TusException("Invalid upload directory and cannot create: {$uploadDir}");
            }
        }
        if (!is_writable($normalized)) {
            throw new TusException("Upload directory not writable: {$uploadDir}");
        }
        $this->uploadDir = $normalized;
        return true;
    }

    public function getUploadDir(): string
    {
        return $this->uploadDir;
    }

    public function exists(string $filename): bool
    {
        $this->assertSafeFilename($filename);
        return file_exists($this->uploadDir . $filename);
    }

    public function create(string $filename): void
    {
        $this->assertSafeFilename($filename);
        $path = $this->uploadDir . $filename;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // create the file under exclusive lock to avoid races with other writers
        $this->lock($path, LOCK_EX, function ($fh) {
            // open in 'c' mode creates file if missing
            // nothing to write; presence of handle ensures file exists
        }, 'c');
    }

    /**
     * Append either a string or a stream to the file under exclusive lock.
     * $data can be: string | resource | Psr\Http\Message\StreamInterface
     */
    public function append(string $filename, $data): void
    {
        $this->assertSafeFilename($filename);
        $path = $this->uploadDir . $filename;
        if (!file_exists($path)) {
            throw new TusException("File does not exist: {$filename}");
        }

        $this->lock($path, LOCK_EX, function ($fh) use ($data, $filename, $path) {
            // ensure pointer at end for append
            if (fseek($fh, 0, SEEK_END) === -1) {
                throw new TusException("Failed to seek to end for append: {$filename}");
            }

            if (is_string($data)) {
                $written = fwrite($fh, $data);
                if ($written === false) {
                    throw new TusException("Failed to append to file: {$filename}");
                }
            } elseif (is_resource($data)) {
                // stream_copy_to_stream will preserve memory usage
                $copied = stream_copy_to_stream($data, $fh);
                if ($copied === 0 && !feof($data)) {
                    throw new TusException("Failed to append stream to file: {$filename}");
                }
            } elseif ($data instanceof \Psr\Http\Message\StreamInterface) {
                // read from PSR-7 stream in chunks to avoid memory spikes
                while (!$data->eof()) {
                    $chunk = $data->read(8192);
                    if ($chunk === '') {
                        break;
                    }
                    $written = fwrite($fh, $chunk);
                    if ($written === false) {
                        throw new TusException("Failed to append PSR stream to file: {$filename}");
                    }
                }
            } else {
                throw new TusException("Unsupported data type for append on {$filename}");
            }

            fflush($fh);
        }, 'c');

        clearstatcache(true, $path);
    }

    public function getSize(string $filename): int
    {
        $this->assertSafeFilename($filename);
        $filePath = $this->uploadDir . $filename;
        if (!file_exists($filePath)) {
            return 0;
        }
        clearstatcache(true, $filePath);
        return (int)filesize($filePath);
    }

    public function delete(string $filename): void
    {
        $this->assertSafeFilename($filename);
        $filePath = $this->uploadDir . $filename;
        if (!file_exists($filePath)) {
            return;
        }

        // Acquire exclusive lock then unlink
        $this->lock($filePath, LOCK_EX, function ($fh) use ($filePath, $filename) {
            // fclose/unlock happens in finally of lock()
            if (!@unlink($filePath)) {
                throw new TusException("Failed to delete file: {$filename}");
            }
        }, 'c');
    }

    /**
     * Create or update the container atomically.
     * Uses the file lock (if file exists) so that file + container are synchronized.
     */
    public function containerCreate(string $filename, \stdClass $cache): void
    {
        $this->assertSafeFilename($filename);
        $infoFile = $this->uploadDir . $filename . self::$containerSuffix;
        $pathToLock = file_exists($this->uploadDir . $filename) ? $this->uploadDir . $filename : $infoFile;

        // Merge with existing container atomically if present
        $existing = $this->containerFetch($filename);
        if ($existing) {
            $cache = (object)array_merge((array)$existing, (array)$cache);
        }

        $tmp = $infoFile . '.tmp';
        $json = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->lock($pathToLock, LOCK_EX, function ($fh) use ($tmp, $infoFile, $json, $filename) {
            if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $infoFile)) {
                @unlink($tmp);
                throw new TusException("Failed to create container for: {$filename}");
            }
        }, file_exists($this->uploadDir . $filename) ? 'c' : 'c'); // 'c' ensures file exists for locking
    }

    public function containerExists(string $filename): bool
    {
        $this->assertSafeFilename($filename);
        return file_exists($this->uploadDir . $filename . self::$containerSuffix);
    }

    public function containerFetch(string $filename): ?\stdClass
    {
        $this->assertSafeFilename($filename);
        $infoFile = $this->uploadDir . $filename . self::$containerSuffix;
        if (!file_exists($infoFile)) {
            return null;
        }

        $contents = $this->sharedGet($infoFile);
        if ($contents === '') {
            throw new TusException("Failed to read container for: {$filename}");
        }

        $data = json_decode($contents, false);
        if ($data === null) {
            // corrupted container — delete and surface null so server can treat as missing
            @unlink($infoFile);
            return null;
        }

        if (isset($data->expires_at) && @strtotime($data->expires_at) < time()) {
            // stale container - remove both file and container
            try {
                $this->delete($filename);
            } catch (\Throwable $e) {
                // ignore deletion failure here; container will be removed below
            }
            $this->containerDelete($filename);
            return null;
        }

        return $data;
    }

    public function containerDelete(string $filename): void
    {
        $this->assertSafeFilename($filename);
        $infoFile = $this->uploadDir . $filename . self::$containerSuffix;
        if (!file_exists($infoFile)) {
            return;
        }
        // Prefer to lock the container file itself for deletion
        $this->lock($infoFile, LOCK_EX, function ($fh) use ($infoFile, $filename) {
            if (!@unlink($infoFile)) {
                throw new TusException("Failed to delete container for: {$filename}");
            }
        }, 'c');
    }

    public function completeAndFetch(string $filename, string $destinationDirectory, bool $removeAfter = true): bool
    {
        $this->assertSafeFilename($filename);
        $filePath = $this->uploadDir . $filename;
        $destinationDirectory = $this->normalizePath($destinationDirectory);
        if (!file_exists($filePath)) {
            throw new TusException("File does not exist: {$filename}");
        }
        if ($destinationDirectory === $this->uploadDir) {
            $this->containerDelete($filename);
            return true;
        }
        if (!is_dir($destinationDirectory)) {
            if (!@mkdir($destinationDirectory, 0755, true)) {
                throw new TusException("Failed to create destination directory: {$destinationDirectory}");
            }
        }

        $destination = $destinationDirectory . $filename;
        // Acquire shared lock while moving or copying to ensure content consistency
        $this->lock($filePath, LOCK_SH, function ($fh) use ($filePath, $destination, $removeAfter, $filename) {
            if ($removeAfter) {
                if (!@rename($filePath, $destination)) {
                    throw new TusException("Failed to move file to: {$destination}");
                }
            } else {
                if (!@copy($filePath, $destination)) {
                    throw new TusException("Failed to copy file to: {$destination}");
                }
            }
        }, 'r');

        if ($removeAfter) {
            // containerDelete locked on container itself
            $this->containerDelete($filename);
        }
        return true;
    }

    public function completeAndStream(string $filename, bool $removeAfter = true)
    {
        $this->assertSafeFilename($filename);
        $filePath = $this->uploadDir . $filename;
        if (!file_exists($filePath)) {
            throw new TusException("File does not exist: {$filename}");
        }

        // Return an in-memory temp stream with the file contents while holding a shared lock.
        $temp = fopen('php://temp', 'r+');
        $this->lock($filePath, LOCK_SH, function ($fh) use ($temp, $filePath, $filename) {
            // copy to temp
            if (stream_copy_to_stream($fh, $temp) === 0 && filesize($filePath) > 0) {
                throw new TusException("Failed to stream file: {$filename}");
            }
            rewind($temp);
        }, 'r');

        if ($removeAfter) {
            // delete the real file and container under exclusive lock
            $this->delete($filename);
            $this->containerDelete($filename);
        }

        return $temp;
    }

    public function complete(string $filename): bool
    {
        $this->assertSafeFilename($filename);
        if (!file_exists($this->uploadDir . $filename)) {
            throw new TusException("File does not exist: {$filename}");
        }
        $this->containerDelete($filename);
        return true;
    }

    public function supportsCrossCheck(): bool
    {
        return true;
    }

    public function getCrossCheckAlgorithms(): array
    {
        return ['md5', 'sha1', 'sha256', 'sha512'];
    }

    /**
     * Cross-check using the locked handle to avoid races.
     *
     * Note: we accept $checksum as base64 encoded string.
     */
    public function crossCheck(string $filename, string $algorithm, string $checksum): bool
    {
        $this->assertSafeFilename($filename);
        $filePath = $this->uploadDir . $filename;
        if (!file_exists($filePath)) {
            return false;
        }

        return (bool)$this->lock($filePath, LOCK_SH, function ($fh) use ($algorithm, $checksum, $filename) {
            // compute raw binary digest via hash_update_stream then base64-encode
            $ctx = @hash_init($algorithm);
            if ($ctx === false) {
                throw new TusException("Unsupported hash algorithm: {$algorithm}");
            }
            // rewind the handle for hashing
            if (ftell($fh) !== 0) {
                rewind($fh);
            }
            // hash_update_stream expects a stream resource
            if (@hash_update_stream($ctx, $fh) === false) {
                throw new TusException("Failed to compute checksum for: {$filename}");
            }
            $calculated = base64_encode(hash_final($ctx, true));
            return hash_equals($calculated, $checksum);
        }, 'r');
    }

    /**
     * Normalize directory path to trailing slash and forward slashes.
     */
    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['\\', '//'], '/', $path), '/') . '/';
    }

    /**
     * Central locking helper: opens file (creating if needed with mode), acquires flock, executes callback,
     * then releases lock and closes file. Returns callback result.
     *
     * $mode must be a valid fopen mode like 'r', 'c', 'rb', etc.
     * $path can be a non-existent path; if mode contains 'c' or 'w' a file will be created.
     */
    private function lock(string $path, int $type, callable $callback, string $mode = 'r')
    {
        // ensure parent directory exists for lock target
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fh = @fopen($path, $mode);
        if ($fh === false) {
            throw new TusException("Cannot open file for locking: {$path}");
        }

        try {
            if (!flock($fh, $type)) {
                throw new TusException("Failed to obtain lock on: {$path}");
            }
            // perform callback with handle; if callback returns, propagate result
            $result = $callback($fh);
            return $result;
        } finally {
            // release and close
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Shared read helper using LOCK_SH
     */
    private function sharedGet(string $path): string
    {
        return (string)$this->lock($path, LOCK_SH, function ($fh) {
            $stat = fstat($fh);
            $size = $stat ? ($stat['size'] ?? 0) : 0;
            if ($size === 0) {
                return '';
            }
            // rewind just in case
            if (ftell($fh) !== 0) {
                rewind($fh);
            }
            $contents = stream_get_contents($fh, $size);
            return $contents === false ? '' : $contents;
        }, 'r');
    }

    /**
     * Remove stale .tmp files for containers and partial tmp files.
     * Call this from a cron or probabilistically from incoming requests.
     */
    public function cleanupTmpFiles(): void
    {
        $glob = $this->uploadDir . '*.{tmp,' . ltrim(self::$containerSuffix, '.') . '.tmp}';
        foreach (glob($this->uploadDir . '*', GLOB_NOSORT) as $f) {
            // handle .tmp files directly in upload dir
            if (is_file($f) && preg_match('/\.tmp$/', $f) && filemtime($f) < time() - $this->tmpTtl) {
                @unlink($f);
                continue;
            }
            // also remove stale tmp container files
            if (is_file($f) && preg_match('/' . preg_quote(self::$containerSuffix, '/') . '\.tmp$/', $f) && filemtime($f) < time() - $this->tmpTtl) {
                @unlink($f);
                continue;
            }
        }
        // optionally clean empty dirs
        foreach (glob($this->uploadDir . '*', GLOB_ONLYDIR) as $d) {
            if (is_dir($d) && count(glob("$d/*")) === 0) {
                @rmdir($d);
            }
        }
    }

    /**
     * Ensure filename is a safe basename and not a path traversal or control-chars payload.
     */
    private function assertSafeFilename(string $filename): void
    {
        // must be a basename (no slash), no NUL, no ".."
        if (basename($filename) !== $filename) {
            throw new TusException("Unsafe filename (contains path): {$filename}");
        }
        if (str_contains($filename, '..')) {
            throw new TusException("Unsafe filename (path traversal): {$filename}");
        }
        // reject control chars
        if (preg_match('/[\\x00-\\x1F\\x7F]/', $filename)) {
            throw new TusException("Unsafe filename (control characters): {$filename}");
        }
        // reasonable length limits
        if (strlen($filename) > 255 || strlen($filename) === 0) {
            throw new TusException("Invalid filename length: {$filename}");
        }
    }
}
