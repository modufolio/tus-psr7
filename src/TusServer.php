<?php

declare(strict_types=1);

namespace Modufolio\Tus;

use Modufolio\Psr7\Http\Response;
use Modufolio\Tus\Exception\TusException;
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
    private int $cleanupProbabilityPercent = 5;
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

    private function handleCreation(ServerRequestInterface $request): ResponseInterface
    {
        $uploadLength = $request->getHeaderLine('Upload-Length');
        $deferLength = $request->getHeaderLine('Upload-Defer-Length') === '1';
        $uploadMetadata = $this->parseMetadata($request->getHeaderLine('Upload-Metadata'));
        $isPartial = stripos($request->getHeaderLine('Upload-Concat'), 'partial') !== false;
        $concatHeader = $request->getHeaderLine('Upload-Concat');
        $crossChecksum = $this->extCrossCheck ? $this->parseChecksum($request->getHeaderLine('Upload-CrossChecksum')) : null;

        if ($uploadLength !== '' && ((int)$uploadLength > $this->maxSize)) {
            return $this->createResponse(413, [], 'File size exceeds maximum limit');
        }

        if (!isset($uploadMetadata['filename']) || !$this->isValidFilename($uploadMetadata['filename'])) {
            return $this->createResponse(400, [], 'Invalid or missing filename in Upload-Metadata');
        }

        $fileName = $this->sanitizeFilename($uploadMetadata['filename']);

        if ($this->backend->exists($fileName) || $this->backend->containerExists($fileName)) {
            $cache = $this->backend->containerFetch($fileName);
            if ($cache !== null) {
                $headers = [
                    'Location' => $cache->location ?? ($this->apiPath . '/' . $fileName),
                    'Tus-Resumable' => self::PROTOCOL_VERSION,
                    'Cache-Control' => 'no-store',
                ];

                return $this->createResponse(200, $headers, '');
            }

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

        if (stripos($concatHeader, 'final;') === 0) {
            $parts = explode(';', $concatHeader, 2);
            $list = isset($parts[1]) ? preg_split('/\s+/', trim($parts[1])) : [];
            $cache->partials = $list;
        }

        $this->backend->containerCreate($fileName, $cache);

        $body = $request->getBody();
        $hasBody = $body && $body->getSize() > 0;

        if ($hasBody) {
            $this->backend->create($fileName);
            $ctx = null;

            if ($this->extCrossCheck && isset($cache->checksum->algorithm)) {
                $ctx = hash_init($cache->checksum->algorithm);
            }

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
                if ($cache->length !== null && $this->backend->getSize($fileName) > $cache->length) {
                    $this->backend->delete($fileName);
                    $this->backend->containerDelete($fileName);

                    return $this->createResponse(413, [], 'Upload exceeds declared length');
                }
            }

            if ($ctx !== null) {
                $localChecksum = base64_encode(hash_final($ctx, true));
                if ($localChecksum !== ($cache->checksum->value ?? null)) {
                    $this->backend->delete($fileName);
                    $this->backend->containerDelete($fileName);

                    return $this->createResponse(460, [], 'Checksum mismatch on creation upload');
                }
            }
        } else {
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

        $currentSize = $this->backend->getSize($fileName);
        if ($offset !== $currentSize) {
            return $this->createResponse(409, ['Upload-Offset' => (string)$currentSize], 'Invalid offset');
        }

        $body = $request->getBody();
        if (!$body) {
            return $this->createResponse(400, [], 'No upload data provided');
        }

        $declaredLength = $cache->length;

        $patchChecksum = $this->parseChecksum($request->getHeaderLine('Upload-Checksum'));
        if ($patchChecksum && !in_array($patchChecksum->algorithm, $this->backend->getCrossCheckAlgorithms(), true)) {
            return $this->createResponse(400, [], 'Unsupported checksum algorithm');
        }

        $ctx = null;
        if ($patchChecksum) {
            $ctx = hash_init($patchChecksum->algorithm);
        }

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
            $nowSize = $this->backend->getSize($fileName);
            if ($nowSize > $this->chunkSize + $offset && strlen($chunk) > $this->chunkSize) {
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

        if ($declaredLength !== null && $newOffset >= $declaredLength && empty($cache->is_partial)) {
            if ($this->extCrossCheck && isset($cache->checksum->algorithm, $cache->checksum->value)) {
                if (!$this->backend->crossCheck($fileName, $cache->checksum->algorithm, $cache->checksum->value)) {
                    $this->backend->delete($fileName);
                    $this->backend->containerDelete($fileName);

                    return $this->createResponse(410, [], 'Final file checksum mismatch');
                }
            }

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
        $partials = array_map(fn($u) => basename(parse_url($u, PHP_URL_PATH)), $cache->partials);

        $this->backend->create($fileName);

        foreach ($partials as $p) {
            if (!$this->backend->exists($p)) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);

                throw new TusException("Missing partial during concatenation: $p");
            }

            $partialPath = $this->backend->getUploadDir() . $p;
            $fh = fopen($partialPath, 'rb');

            if (!$fh) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);

                throw new TusException("Unable to open partial file: $p");
            }

            while (!feof($fh)) {
                $data = fread($fh, $this->chunkSize);
                if ($data === false || $data === '') {
                    break;
                }
                $this->backend->append($fileName, $data);
            }

            fclose($fh);
        }

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
        if ($this->validateMimeTypes && !empty($this->allowedMimeTypes)) {
            try {
                $originName = $cache->metadata['filename'] ?? $fileName;
                self::validateFile($this->backend->getUploadDir() . $fileName, $originName, $this->allowedMimeTypes);
            } catch (TusException $e) {
                $this->backend->delete($fileName);
                $this->backend->containerDelete($fileName);

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
        return basename($request->getUri()->getPath());
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
            $idx = strpos($item, ' ');
            if ($idx === false) {
                continue;
            }
            $key = substr($item, 0, $idx);
            $value = substr($item, $idx + 1);
            $decoded = base64_decode($value, true);
            $result[$key] = $decoded !== false ? $decoded : '';
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
        $headers = array_merge(['Cache-Control' => 'no-store', 'Tus-Resumable' => self::PROTOCOL_VERSION], $headers);
        $response = new Response($status, $headers);
        if ($errorMessage !== '') {
            $response = $response->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => $errorMessage]));
        }

        return $response;
    }

    public static function calculateMaxSize(): int
    {
        $candidates = [
            self::toBytes(ini_get('upload_max_filesize') ?: '20M'),
            self::toBytes(ini_get('post_max_size') ?: '20M'),
        ];
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = self::toBytes('100M');
        }

        return (int)floor((int)min($candidates) * 0.95);
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
        foreach (glob(rtrim($dir, '/') . '/*.{cachecontainer,tmp}', GLOB_BRACE) as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
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
        $mime = mime_content_type($filePath) ?: '';
        if ($mime === '' || !in_array($mime, $acceptedTypes, true)) {
            throw new TusException("Invalid file type: $mime");
        }
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
        $filename = ltrim($filename, '.');

        return strlen($filename) >= 3 ? $filename : 'file_' . uniqid();
    }
}
