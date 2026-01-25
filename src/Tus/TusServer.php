<?php

namespace Modufolio\Tus\Tus;

use Modufolio\Tus\Exception\TusException;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TusServer
{
    public const PROTOCOL_VERSION = '1.0.0';

    private string $uploadDir;
    private int $maxSize;
    private int $chunkSize;
    private string $apiPath = '/tus';
    private array $supportedHashAlgorithms = ['md5', 'sha1', 'sha256', 'sha512'];
    private bool $extCrossCheck = false;
    private StorageInterface $backend;
    private int $cleanupProbabilityPercent = 5; // run cleanup ~5% of requests
    private bool $validateMimeTypes = false;
    private array $allowedMimeTypes = [];

    public function __construct(string $uploadDir, ?int $maxSize = null, int $chunkSize = 5242880)
    {
        $this->uploadDir = rtrim($uploadDir, '/') . '/';
        $this->maxSize = $maxSize ?? self::calculateMaxSize();
        $this->chunkSize = $chunkSize;
        $this->backend = new FilesystemStorage($uploadDir);
    }

    public function setAllowedMimeTypes(array $mimeTypes): self
    {
        $this->allowedMimeTypes = $mimeTypes;
        $this->validateMimeTypes = !empty($mimeTypes);
        return $this;
    }

    public function setStorageBackend(StorageInterface $backend): self
    {
        $this->backend = $backend;
        return $this;
    }

    public function getStorageBackend(): StorageInterface
    {
        return $this->backend;
    }

    public function completeAndFetch(string $filename, string $destinationDirectory, bool $removeAfter = true): bool
    {
        return $this->backend->completeAndFetch($filename, $destinationDirectory, $removeAfter);
    }

    public function completeAndStream(string $filename, bool $removeAfter = true)
    {
        return $this->backend->completeAndStream($filename, $removeAfter);
    }

    public function complete(string $filename): bool
    {
        return $this->backend->complete($filename);
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        // occasional cleanup to keep disk tidy (probabilistic)
        if (random_int(1, 100) <= $this->cleanupProbabilityPercent) {
            self::cleanTmpDir($this->uploadDir);
        }

        if ($request->getHeaderLine('CrossCheck') === 'true') {
            $this->extCrossCheck = true;
        }

        $tusHeader = $request->getHeaderLine('Tus-Resumable');
        if ($tusHeader !== '' && $tusHeader !== self::PROTOCOL_VERSION) {
            return $this->createResponse(412, [], 'Unsupported TUS protocol version');
        }

        if (!isset($this->backend)) {
            throw new TusException('Storage backend must be set before handling requests');
        }

        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['OPTIONS', 'POST', 'HEAD', 'PATCH', 'DELETE'])) {
            return $this->createResponse(405, ['Allow' => 'OPTIONS, POST, HEAD, PATCH, DELETE'], 'Method not allowed');
        }

        return match ($method) {
            'OPTIONS' => $this->handleOptions(),
            'POST' => $this->handleCreation($request),
            'HEAD' => $this->handleHead($request),
            'PATCH' => $this->handlePatch($request),
            'DELETE' => $this->handleDelete($request),
        };
    }

    private function handleOptions(): ResponseInterface
    {
        $extensions = ['creation', 'checksum', 'concatenation', 'termination', 'creation-with-upload'];
        if ($this->backend->supportsCrossCheck()) {
            $extensions[] = 'crosscheck';
        }
        $headers = [
            'Tus-Resumable' => self::PROTOCOL_VERSION,
            'Tus-Version' => self::PROTOCOL_VERSION,
            'Tus-Extension' => implode(',', $extensions),
            'Tus-Max-Size' => (string)$this->maxSize,
            'Tus-Checksum-Algorithm' => implode(',', $this->supportedHashAlgorithms),
            'Cache-Control' => 'no-store',
        ];
        return $this->createResponse(204, $headers);
    }

    /**
     * Handle creation. Supports Upload-Defer-Length: 1 and creation-with-upload (body).
     */
    /**
     * Handle creation. Supports Upload-Defer-Length: 1 and creation-with-upload (body).
     */
    private function handleCreation(ServerRequestInterface $request): ResponseInterface
    {
        $uploadLength = $request->getHeaderLine('Upload-Length');
        $deferLength = $request->getHeaderLine('Upload-Defer-Length') === '1';
        $uploadMetadata = $this->parseMetadata($request->getHeaderLine('Upload-Metadata'));
        $isPartial = stripos($request->getHeaderLine('Upload-Concat'), 'partial') !== false;
        $concatHeader = $request->getHeaderLine('Upload-Concat'); // may be 'final; url1 url2'
        $crossChecksum = $this->extCrossCheck ? $this->parseChecksum($request->getHeaderLine('Upload-CrossChecksum')) : null;

        if ($uploadLength !== '' && ((int)$uploadLength > $this->maxSize)) {
            return $this->createResponse(413, [], 'File size exceeds maximum limit');
        }

        if (!isset($uploadMetadata['filename']) || !$this->isValidFilename($uploadMetadata['filename'])) {
            return $this->createResponse(400, [], 'Invalid or missing filename in Upload-Metadata');
        }

        $fileName = $this->sanitizeFilename($uploadMetadata['filename']);

        // Check if this is a resume attempt - if file exists with container, return existing location
        if ($this->backend->exists($fileName) || $this->backend->containerExists($fileName)) {
            $cache = $this->backend->containerFetch($fileName);
            if ($cache !== null) {
                // File exists and is resumable - return the existing location
                // Client should use HEAD to get current offset, then PATCH to continue
                $headers = [
                    'Location' => $cache->location ?? ($this->apiPath . '/' . $fileName),
                    'Tus-Resumable' => self::PROTOCOL_VERSION,
                    'Cache-Control' => 'no-store',
                ];
                return $this->createResponse(200, $headers, '');
            }

            // Container is missing but file exists - this shouldn't happen in normal flow
            // Could be a leftover file, so return 409
            return $this->createResponse(409, [], 'File already exists without valid container');
        }

        $cache = new \stdClass();
        $cache->length = $uploadLength === '' ? null : (int)$uploadLength;
        $cache->deferred = $deferLength;
        $cache->metadata = $uploadMetadata;
        $cache->is_partial = $isPartial;
        $cache->partials = [];
        $cache->created_at = gmdate('D, d M Y H:i:s T');
        $cache->expires_at = gmdate('D, d M Y H:i:s T', time() + 86400);
        $cache->location = $this->apiPath . '/' . $fileName;
        if ($crossChecksum) {
            if (!in_array($crossChecksum->algorithm, $this->backend->getCrossCheckAlgorithms(), true)) {
                return $this->createResponse(400, [], 'Invalid or unsupported checksum algorithm');
            }
            $cache->checksum = $crossChecksum;
        }

        // If Upload-Concat final: create container that references partials (we expect client to POST with Upload-Concat: final; <urls>)
        if (stripos($concatHeader, 'final;') === 0) {
            // parse urls (space separated) after 'final;'
            $parts = explode(';', $concatHeader, 2);
            $list = isset($parts[1]) ? preg_split('/\s+/', trim($parts[1])) : [];
            $cache->partials = $list;
            // create container, but we will finalize later when all partials exist
        }

        // persist container atomically
        $this->backend->containerCreate($fileName, $cache);

        // If client includes data in the creation request (creation-with-upload):
        $body = $request->getBody();
        $hasBody = $body && $body->getSize() > 0;
        if ($hasBody) {
            // create real file file and append in one pass (use handlePatch style logic)
            $this->backend->create($fileName);
            $ctx = null;

            if ($this->extCrossCheck && isset($cache->checksum->algorithm)) {
                $algorithm = $cache->checksum->algorithm;
                $ctx = hash_init($algorithm);
            }

            // stream body in chunks and append
            while (!$body->eof()) {
                $chunk = $body->read($this->chunkSize);
                if ($chunk === '') {
                    break;
                }
                if ($ctx !== null) {
                    hash_update($ctx, $chunk);
                }
                $this->backend->append($fileName, $chunk);
                clearstatcache(true, $this->backend->getUploadDir() . $fileName);
                // check not exceeding allowed size if declared
                if ($cache->length !== null && $this->backend->getSize($fileName) > $cache->length) {
                    $this->backend->delete($fileName);
                    $this->backend->containerDelete($fileName);
                    return $this->createResponse(413, [], 'Upload exceeds declared length');
                }
            }

            // verify cross-check if provided
            if ($ctx !== null) {
                $localChecksum = base64_encode(hash_final($ctx, true));
                if ($localChecksum !== ($cache->checksum->value ?? null)) {
                    $this->backend->delete($fileName);
                    $this->backend->containerDelete($fileName);
                    return $this->createResponse(460, [], 'Checksum mismatch on creation upload');
                }
            }
        } else {
            // create placeholder file if not deferred; otherwise client will PATCH later
            if (!$cache->deferred) {
                $this->backend->create($fileName);
            }
        }

        $headers = [
            'Location' => $cache->location,
            'Tus-Resumable' => self::PROTOCOL_VERSION,
            'Cache-Control' => 'no-store',
        ];

        return $this->createResponse(201, $headers, '');
    }

    private function handleHead(ServerRequestInterface $request): ResponseInterface
    {
        $fileName = $this->getFileNameFromRequest($request);
        if (!$this->backend->exists($fileName) && !$this->backend->containerExists($fileName)) {
            return $this->createResponse(404, [], 'Not found');
        }

        $cache = $this->backend->containerFetch($fileName);
        if ($cache === null) {
            return $this->createResponse(404, [], 'Not found');
        }

        $headers = [
            'Tus-Resumable' => self::PROTOCOL_VERSION,
            'Upload-Offset' => (string)$this->backend->getSize($fileName),
            'Upload-Length' => $cache->length !== null ? (string)$cache->length : '',
            'Upload-Metadata' => $this->formatMetadata($cache->metadata ?? []),
            'Cache-Control' => 'no-store',
        ];
        if ($cache->is_partial ?? false) {
            $headers['Upload-Concat'] = 'partial';
        } elseif (!empty($cache->partials ?? [])) {
            $headers['Upload-Concat'] = 'final;' . implode(' ', $cache->partials);
        }

        return $this->createResponse(200, $headers, '');
    }

    private function handlePatch(ServerRequestInterface $request): ResponseInterface
    {
        $fileName = $this->getFileNameFromRequest($request);
        if (!$this->backend->containerExists($fileName)) {
            return $this->createResponse(404, [], 'Not found');
        }

        $cache = $this->backend->containerFetch($fileName);
        if ($cache === null) {
            return $this->createResponse(404, [], 'Not found');
        }

        $offsetHeader = $request->getHeaderLine('Upload-Offset');
        $offset = $offsetHeader === '' ? 0 : (int)$offsetHeader;
        $checksumHeader = $request->getHeaderLine('Upload-Checksum');

        $currentSize = $this->backend->getSize($fileName);
        if ($offset !== $currentSize) {
            return $this->createResponse(409, ['Upload-Offset' => (string)$currentSize], 'Invalid offset');
        }

        $body = $request->getBody();
        if (!$body) {
            return $this->createResponse(400, [], 'No upload data provided');
        }

        // If Upload-Length is declared on container, ensure we don't exceed it while streaming
        $declaredLength = $cache->length;

        // handle checksum header for this PATCH (format "algorithm value")
        $patchChecksum = $this->parseChecksum($checksumHeader);
        if ($patchChecksum && !in_array($patchChecksum->algorithm, $this->backend->getCrossCheckAlgorithms(), true)) {
            return $this->createResponse(400, [], 'Unsupported checksum algorithm');
        }

        // single pass: read from body, optionally update hash, append chunk by chunk
        $ctx = null;
        if ($patchChecksum) {
            $ctx = hash_init($patchChecksum->algorithm);
        }

        // if file doesn't exist yet, create it
        if (!$this->backend->exists($fileName)) {
            $this->backend->create($fileName);
        }

        while (!$body->eof()) {
            $chunk = $body->read(min($this->chunkSize, 65536));
            if ($chunk === '') {
                break;
            }
            if ($ctx !== null) {
                hash_update($ctx, $chunk);
            }
            $this->backend->append($fileName, $chunk);
            // size check
            $nowSize = $this->backend->getSize($fileName);
            if ($nowSize > $this->chunkSize + $offset && strlen($chunk) > $this->chunkSize) {
                // defensive: single chunk exceeded permitted chunk size from client
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                return $this->createResponse(413, [], 'Chunk size exceeds limit');
            }
            if ($declaredLength !== null && $nowSize > $declaredLength) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                return $this->createResponse(413, [], 'Upload exceeds declared length');
            }
        }

        if ($ctx !== null) {
            $localChecksum = base64_encode(hash_final($ctx, true));
            if ($localChecksum !== ($patchChecksum->value ?? null)) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                return $this->createResponse(460, ['Upload-Offset' => (string)$offset], 'Checksum mismatch');
            }
        }

        $newOffset = $this->backend->getSize($fileName);

        // if upload completed and not partial, run final checks and finalize
        if ($declaredLength !== null && $newOffset >= $declaredLength && empty($cache->is_partial)) {
            if ($this->extCrossCheck && isset($cache->checksum->algorithm, $cache->checksum->value)) {
                if (!$this->backend->crossCheck($fileName, $cache->checksum->algorithm, $cache->checksum->value)) {
                    $this->backend->delete($fileName);
                    $this->backend->containerDelete($fileName);
                    return $this->createResponse(410, [], 'Final file checksum mismatch');
                }
            }

            // handle concatenation finalization if set
            if (!empty($cache->partials)) {
                $this->finalizeConcatenation($fileName, $cache);
            } else {
                $this->finalizeUpload($fileName, $cache);
            }
        }

        $headers = [
            'Tus-Resumable' => self::PROTOCOL_VERSION,
            'Upload-Offset' => (string)$newOffset,
            'Cache-Control' => 'no-store',
        ];
        if ($this->extCrossCheck) {
            $headers['Location'] = $cache->location;
        }

        return $this->createResponse(204, $headers, '');
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $fileName = $this->getFileNameFromRequest($request);
        if (!$this->backend->exists($fileName) && !$this->backend->containerExists($fileName)) {
            return $this->createResponse(404, [], 'Not found');
        }

        if ($this->backend->exists($fileName)) {
            $this->backend->delete($fileName);
        }
        if ($this->backend->containerExists($fileName)) {
            $this->backend->containerDelete($fileName);
        }

        return $this->createResponse(204, ['Tus-Resumable' => self::PROTOCOL_VERSION, 'Cache-Control' => 'no-store'], '');
    }

    private function finalizeConcatenation(string $fileName, \stdClass $cache): void
    {
        // $cache->partials contains an array of URLs (as string). Convert to basenames and append them in order.
        $partials = array_map(fn ($u) => basename(parse_url($u, PHP_URL_PATH)), $cache->partials);
        // create target
        $this->backend->create($fileName);
        foreach ($partials as $p) {
            if (!$this->backend->exists($p)) {
                // missing partial -> cleanup and abort
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                throw new TusException("Missing partial during concatenation: $p");
            }
            // stream partial into final file
            $partialPath = $this->backend->getUploadDir() . $p;
            $chunkSize = $this->chunkSize;
            $fh = fopen($partialPath, 'rb');
            if (!$fh) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                throw new TusException("Unable to open partial file: $p");
            }
            while (!feof($fh)) {
                $data = fread($fh, $chunkSize);
                if ($data === false || $data === '') {
                    break;
                }
                $this->backend->append($fileName, $data);
            }
            fclose($fh);
        }

        // after concatenation, perform cross check if applicable
        if ($this->extCrossCheck && isset($cache->checksum->algorithm, $cache->checksum->value)) {
            if (!$this->backend->crossCheck($fileName, $cache->checksum->algorithm, $cache->checksum->value)) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                throw new TusException('Concatenated file checksum mismatch');
            }
        }

        $this->finalizeUpload($fileName, $cache);
    }

    private function finalizeUpload(string $fileName, \stdClass $cache): void
    {
        // if content type validation is desired, do it here using $cache->metadata['filename'] as original name
        if ($this->validateMimeTypes && !empty($this->allowedMimeTypes)) {
            try {
                $originName = $cache->metadata['filename'] ?? $fileName;
                self::validateFile($this->backend->getUploadDir() . $fileName, $originName, $this->allowedMimeTypes);
            } catch (TusException $e) {
                // if invalid, remove file and container
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);
                // don't throw to client as 500; turn into 415/400
                throw $e;
            }
        }
        $this->backend->containerDelete($fileName);
    }

    private function parseChecksum(string $value): ?\stdClass
    {
        if (empty(trim($value))) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($value), 2);
        if (count($parts) !== 2) {
            return null;
        }
        return (object)['algorithm' => $parts[0], 'value' => $parts[1]];
    }

    private function getFileNameFromRequest(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        return basename($path);
    }

    private function parseMetadata(string $metadata): array
    {
        if (trim($metadata) === '') {
            return [];
        }
        $result = [];
        foreach (explode(',', $metadata) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            // key base64(value)
            $idx = strpos($item, ' ');
            if ($idx === false) {
                continue;
            }
            $key = substr($item, 0, $idx);
            $value = substr($item, $idx + 1);
            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                $decoded = '';
            }
            $result[$key] = $decoded;
        }
        return $result;
    }

    private function formatMetadata(array $metadata): string
    {
        $parts = [];
        foreach ($metadata as $k => $v) {
            $parts[] = $k . ' ' . base64_encode($v);
        }
        return implode(',', $parts);
    }

    private function createResponse(int $status, array $headers = [], string $errorMessage = ''): ResponseInterface
    {
        $default = ['Cache-Control' => 'no-store', 'Tus-Resumable' => self::PROTOCOL_VERSION];
        $headers = array_merge($default, $headers);
        $response = new Response($status, $headers);
        if ($errorMessage !== '') {
            $body = json_encode(['status' => 'error', 'message' => $errorMessage]);
            $response = $response->withHeader('Content-Type', 'application/json');
            $response->getBody()->write($body);
        }
        return $response;
    }

    public static function calculateMaxSize(): int
    {
        $candidates = [];
        $u = ini_get('upload_max_filesize') ?: '20M';
        $p = ini_get('post_max_size') ?: '20M';
        $candidates[] = self::toBytes($u);
        $candidates[] = self::toBytes($p);
        // if behind Cloudflare reduce
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = self::toBytes('100M');
        }
        $min = (int)min($candidates);
        // use 95% to be safe
        return (int)floor($min * 0.95);
    }

    public static function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int)preg_replace('/[^0-9]/', '', $value);
        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function cleanTmpDir(string $dir): void
    {
        // remove old container files and tmp files older than 1 day
        foreach (glob(rtrim($dir, '/') . '/*.{cachecontainer,tmp}', GLOB_BRACE) as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
        // remove empty directories
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) as $d) {
            if (is_dir($d) && count(glob("$d/*")) === 0) {
                @rmdir($d);
            }
        }
    }

    public static function validateFile(string $filePath, string $filename, array $acceptedTypes): void
    {
        if (!file_exists($filePath)) {
            throw new TusException("File not found for validation: $filePath");
        }
        // try to detect mime
        $mime = mime_content_type($filePath) ?: '';
        if ($mime === '' || !in_array($mime, $acceptedTypes, true)) {
            throw new TusException("Invalid file type: $mime");
        }
        // fix extension if needed
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if (empty($extension) || in_array(strtolower($extension), ['tmp', 'temp'], true)) {
            $newExtension = self::mimeToExtension($mime);
            if ($newExtension) {
                $newPath = dirname($filePath) . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.' . $newExtension;
                @rename($filePath, $newPath);
            }
        }
    }

    public static function mimeToExtension(string $mime): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
        ];
        return $map[$mime] ?? null;
    }

    private function isValidFilename(string $filename): bool
    {
        return !preg_match('/\.\.|\/|\\\\|:/', $filename) && strlen(trim($filename)) > 0;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        // prevent multiple leading dots or hidden files
        $filename = ltrim($filename, '.');
        return strlen($filename) >= 3 ? $filename : 'file_' . uniqid();
    }
}
