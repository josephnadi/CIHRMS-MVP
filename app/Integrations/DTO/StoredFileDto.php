<?php

namespace App\Integrations\DTO;

final class StoredFileDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $mimeType,
        public readonly int $size,
        public readonly ?string $webUrl = null,
        public readonly ?string $downloadUrl = null,
        public readonly ?\DateTimeInterface $modifiedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'mime_type'    => $this->mimeType,
            'size'         => $this->size,
            'web_url'      => $this->webUrl,
            'download_url' => $this->downloadUrl,
            'modified_at'  => $this->modifiedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
