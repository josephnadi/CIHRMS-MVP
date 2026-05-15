<?php

namespace App\Integrations\Drivers\Microsoft;

use App\Integrations\Contracts\FileStorageProvider;
use App\Integrations\DTO\StoredFileDto;

/**
 * OneDrive (and SharePoint via /drives/{driveId}/) file operations against Microsoft Graph v1.0.
 * Uses the simple upload endpoint (≤ 4 MB). Large files would use createUploadSession;
 * payslips and HR docs comfortably fit the simple path.
 */
class MsGraphFilesDriver extends MsGraphBaseDriver implements FileStorageProvider
{
    public function capability(): string
    {
        return 'files';
    }

    public function upload(string $remotePath, mixed $contents, ?string $mimeType = null): StoredFileDto
    {
        $remotePath = ltrim($remotePath, '/');

        return $this->track('files.upload', ['path' => $remotePath, 'mime' => $mimeType], function () use ($remotePath, $contents, $mimeType) {
            $response = $this->putBinary("/me/drive/root:/{$remotePath}:/content", $contents, $mimeType);
            return $this->mapFile($response->json());
        });
    }

    public function download(string $remoteId): string
    {
        return $this->track('files.download', ['id' => $remoteId], fn () =>
            $this->http()->get("/me/drive/items/{$remoteId}/content")->throw()->body()
        );
    }

    public function delete(string $remoteId): bool
    {
        return $this->track('files.delete', ['id' => $remoteId], function () use ($remoteId) {
            $this->deleteRequest("/me/drive/items/{$remoteId}");
            return true;
        });
    }

    public function shareLink(string $remoteId, string $access = 'view'): string
    {
        return $this->track('files.share', ['id' => $remoteId, 'access' => $access], function () use ($remoteId, $access) {
            $payload = ['type' => $access === 'edit' ? 'edit' : 'view', 'scope' => 'organization'];
            $response = $this->post("/me/drive/items/{$remoteId}/createLink", $payload);
            return (string) data_get($response->json(), 'link.webUrl');
        });
    }

    /** @return array<int, StoredFileDto> */
    public function listFolder(?string $folderId = null): array
    {
        $endpoint = $folderId ? "/me/drive/items/{$folderId}/children" : '/me/drive/root/children';

        return $this->track('files.list', ['folder' => $folderId], function () use ($endpoint) {
            $items = (array) data_get($this->get($endpoint)->json(), 'value', []);
            return array_map(fn ($i) => $this->mapFile($i), $items);
        });
    }

    public function ensureFolder(string $name, ?string $parentId = null): string
    {
        return $this->track('files.ensure_folder', ['name' => $name, 'parent' => $parentId], function () use ($name, $parentId) {
            $endpoint = $parentId ? "/me/drive/items/{$parentId}/children" : '/me/drive/root/children';

            // Look for existing folder by name first to keep the operation idempotent.
            $existing = collect((array) data_get($this->get($endpoint, ['$filter' => "name eq '{$name}'"])->json(), 'value', []))
                ->firstWhere('folder');
            if ($existing) {
                return (string) $existing['id'];
            }

            $created = $this->post($endpoint, [
                'name'                              => $name,
                'folder'                            => new \stdClass,
                '@microsoft.graph.conflictBehavior' => 'rename',
            ]);

            return (string) $created->json('id');
        });
    }

    protected function mapFile(array $raw): StoredFileDto
    {
        return new StoredFileDto(
            id:          (string) ($raw['id'] ?? ''),
            name:        (string) ($raw['name'] ?? ''),
            mimeType:    $raw['file']['mimeType'] ?? null,
            size:        (int) ($raw['size'] ?? 0),
            webUrl:      $raw['webUrl'] ?? null,
            downloadUrl: $raw['@microsoft.graph.downloadUrl'] ?? null,
            modifiedAt:  isset($raw['lastModifiedDateTime']) ? new \DateTimeImmutable($raw['lastModifiedDateTime']) : null,
        );
    }
}
