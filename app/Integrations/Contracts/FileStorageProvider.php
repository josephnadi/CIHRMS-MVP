<?php

namespace App\Integrations\Contracts;

use App\Integrations\DTO\StoredFileDto;

interface FileStorageProvider extends IntegrationProvider
{
    /**
     * Upload a local file (or raw stream) to the remote drive and return its handle.
     *
     * @param  resource|string  $contents
     */
    public function upload(string $remotePath, mixed $contents, ?string $mimeType = null): StoredFileDto;

    public function download(string $remoteId): string;

    public function delete(string $remoteId): bool;

    /** Generate a (typically time-limited) shareable link. */
    public function shareLink(string $remoteId, string $access = 'view'): string;

    /** @return array<int, StoredFileDto> */
    public function listFolder(?string $folderId = null): array;

    public function ensureFolder(string $name, ?string $parentId = null): string;
}
