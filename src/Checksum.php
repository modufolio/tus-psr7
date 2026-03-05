<?php

declare(strict_types=1);

namespace Modufolio\Tus;

class Checksum
{
    public function __construct(
        public readonly string $algorithm,
        public readonly string $value,
    ) {}
}
