<?php

namespace App\Dto;

/**
 * A file just put into private storage (PrivateFileStorage::store()), to be recorded on an entity.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $fileName,
        public string $originalName,
        public string $mimeType,
        public int $size,
    ) {
    }
}
