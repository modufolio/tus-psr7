# TUS PSR-7

A PHP implementation of the [TUS resumable upload protocol](https://tus.io/).

## Installation

```bash
composer require modufolio/tus-psr7
``` 

## Requirements

- PHP 8.2 or higher
- PSR-7 HTTP Message implementation

## Usage

```php
use Modufolio\Tus\TusServer;

$uploadDir = '/path/to/uploads';
$maxSize = 1024 * 1024 * 100; // 100MB
$chunkSize = 1024 * 1024 * 5; // 5MB

$server = new TusServer($uploadDir, $maxSize, $chunkSize);

// Handle the request
$response = $server->handle($request);
```

## Features

- TUS protocol v1.0.0 compliant
- Resumable file uploads
- Checksum verification support (md5, sha1, sha256, sha512)
- Configurable storage backends
- MIME type validation
- Automatic cleanup of expired uploads

## License

MIT
