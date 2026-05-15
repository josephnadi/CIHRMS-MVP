<?php

namespace App\Integrations\Drivers\Google;

use App\Integrations\Contracts\FileStorageProvider;
use App\Integrations\DTO\StoredFileDto;
use Illuminate\Support\Facades\Http;

/**
 * Google Drive v3 file ops (upload, download, share, list, ensureFolder).
 * Uses multipart upload — fine for the document sizes HRMS produces.
 */
class GoogleDriveDriver extends GoogleBaseDriver implements FileStorageProvider
{
    private const API    = 'https://www.googleapis.com/drive/v3/';
    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/';

    public function capability(): string
    {
        return 'files';
    }

    public function upload(string $remotePath, mixed $contents, ?string $mimeType = null): StoredFileDto
    {
        return $this->track('files.upload', ['path' => $remotePath, 'mime' => $mimeType], function () use ($remotePath, $contents, $mimeType) {
            $name = basename($remotePath);
            $parentId = $this->resolveParent(dirname($remotePath));
            $body = is_resource($contents) ? stream_get_contents($contents) : (string) $contents;

            $boundary = 'cihrms-'.bin2hex(random_bytes(8));
            $metadata = json_encode(array_filter([
                'name'    => $name,
                'parents' => $parentId ? [$parentId] : null,
            ]));

            $payload =
                "--{$boundary}\r\n".
                "Content-Type: application/json; charset=UTF-8\r\n\r\n".
                $metadata."\r\n".
                "--{$boundary}\r\n".
                'Content-Type: '.($mimeType ?? 'application/octet-stream')."\r\n\r\n".
                $body."\r\n".
                "--{$boundary}--";

            $response = Http::withToken($this->accessToken())
                ->withBody($payload, "multipart/related; boundary={$boundary}")
                ->post(self::UPLOAD."files?uploadType=multipart&fields=id,name,mimeType,size,webViewLink,webContentLink,modifiedTime")
                ->throw();

            return $this->mapFile($response->json());
        });
    }

    public function download(string $remoteId): string
    {
        return $this->track('files.download', ['id' => $remoteId], fn () =>
            Http::withToken($this->accessToken())
                ->get(self::API."files/{$remoteId}?alt=media")
                ->throw()
                ->body()
        );
    }

    public function delete(string $remoteId): bool
    {
        return $this->track('files.delete', ['id' => $remoteId], function () use ($remoteId) {
            $this->deleteRequest(self::API, "files/{$remoteId}");
            return true;
        });
    }

    public function shareLink(string $remoteId, string $access = 'view'): string
    {
        return $this->track('files.share', ['id' => $remoteId, 'access' => $access], function () use ($remoteId, $access) {
            $this->post(self::API, "files/{$remoteId}/permissions", [
                'role' => $access === 'edit' ? 'writer' : 'reader',
                'type' => 'anyone',
            ]);
            $meta = $this->json(self::API, "files/{$remoteId}", ['fields' => 'webViewLink,webContentLink']);
            return (string) ($meta['webViewLink'] ?? $meta['webContentLink'] ?? '');
        });
    }

    /** @return array<int, StoredFileDto> */
    public function listFolder(?string $folderId = null): array
    {
        return $this->track('files.list', ['folder' => $folderId], function () use ($folderId) {
            $q = $folderId ? "'{$folderId}' in parents and trashed=false" : 'trashed=false';
            $data = $this->json(self::API, 'files', [
                'q'        => $q,
                'fields'   => 'files(id,name,mimeType,size,webViewLink,webContentLink,modifiedTime)',
                'pageSize' => 100,
            ]);
            return array_map(fn ($f) => $this->mapFile($f), (array) ($data['files'] ?? []));
        });
    }

    public function ensureFolder(string $name, ?string $parentId = null): string
    {
        return $this->track('files.ensure_folder', ['name' => $name, 'parent' => $parentId], function () use ($name, $parentId) {
            $q = "name='{$name}' and mimeType='application/vnd.google-apps.folder' and trashed=false"
               . ($parentId ? " and '{$parentId}' in parents" : '');
            $existing = (array) data_get(
                $this->json(self::API, 'files', ['q' => $q, 'fields' => 'files(id)']),
                'files',
                []
            );
            if (! empty($existing)) {
                return (string) $existing[0]['id'];
            }

            $response = $this->post(self::API, 'files', array_filter([
                'name'     => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents'  => $parentId ? [$parentId] : null,
            ]));
            return (string) $response->json('id');
        });
    }

    /** Resolve a path-like parent (e.g. "/HR/Payslips/2026") into a Drive folder ID. */
    protected function resolveParent(string $dir): ?string
    {
        $dir = trim($dir, "./\\");
        if ($dir === '' || $dir === '.') {
            return null;
        }
        $parent = null;
        foreach (explode('/', $dir) as $segment) {
            if ($segment === '') continue;
            $parent = $this->ensureFolder($segment, $parent);
        }
        return $parent;
    }

    /** Decoded JSON body — most Drive callers want the array, not the Response. */
    protected function json(string $base, string $path, array $query = []): array
    {
        return (array) $this->http($base)->get($path, $query)->throw()->json();
    }

    protected function mapFile(array $raw): StoredFileDto
    {
        return new StoredFileDto(
            id:          (string) ($raw['id'] ?? ''),
            name:        (string) ($raw['name'] ?? ''),
            mimeType:    $raw['mimeType'] ?? null,
            size:        (int) ($raw['size'] ?? 0),
            webUrl:      $raw['webViewLink'] ?? null,
            downloadUrl: $raw['webContentLink'] ?? null,
            modifiedAt:  isset($raw['modifiedTime']) ? new \DateTimeImmutable($raw['modifiedTime']) : null,
        );
    }
}
