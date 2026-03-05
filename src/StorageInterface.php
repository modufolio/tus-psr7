<?php

declare(strict_types=1);

namespace Modufolio\Tus;

interface StorageInterface
{
    public function setUploadDir(string $uploadDir): bool;
    public function getUploadDir(): string;
    public function exists(string $filename): bool;
    public function create(string $filename): void;
    public function append(string $filename, string $data): void;
    public function getSize(string $filename): int;
    public function delete(string $filename): void;
    public function containerCreate(string $filename, UploadCache $cache): void;
    public function containerExists(string $filename): bool;
    public function containerFetch(string $filename): ?UploadCache;
    public function containerDelete(string $filename): void;
    public function complete(string $filename): bool;
    public function completeAndFetch(string $filename, string $destinationDirectory, bool $removeAfter = true): bool;
    public function completeAndStream(string $filename, bool $removeAfter = true): mixed;
    public function supportsCrossCheck(): bool;
    public function getCrossCheckAlgorithms(): array;
    public function crossCheck(string $filename, string $algorithm, string $checksum): bool;
}
