<?php

declare(strict_types=1);

namespace Modufolio\Tus;

class UploadCache
{
    public ?Checksum $checksum = null;

    public function __construct(
        public ?int $length,
        public bool $deferred,
        public array $metadata,
        public bool $is_partial,
        public array $partials,
        public string $created_at,
        public string $expires_at,
        public string $location,
    ) {}

    public static function fromArray(array $data): self
    {
        $cache = new self(
            length: isset($data['length']) ? (int)$data['length'] : null,
            deferred: (bool)($data['deferred'] ?? false),
            metadata: (array)($data['metadata'] ?? []),
            is_partial: (bool)($data['is_partial'] ?? false),
            partials: (array)($data['partials'] ?? []),
            created_at: (string)($data['created_at'] ?? ''),
            expires_at: (string)($data['expires_at'] ?? ''),
            location: (string)($data['location'] ?? ''),
        );

        if (isset($data['checksum']['algorithm'], $data['checksum']['value'])) {
            $cache->checksum = new Checksum($data['checksum']['algorithm'], $data['checksum']['value']);
        }

        return $cache;
    }
}
