<?php

namespace App\Services;

final readonly class RemoteFetchProbe
{
    public function __construct(
        public string $originalUrl,
        public string $finalUrl,
        public ?int $expectedSize,
        public ?string $contentType,
        public ?string $etag,
        public ?string $lastModified,
        public bool $supportsRanges,
    ) {}
}
