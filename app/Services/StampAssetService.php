<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StampAssetService
{
    private const DISK = 'local';

    public function store(array $attrs, UploadedFile $file, User $by): StampAsset
    {
        $path = sprintf('assets/stamps/%s.png', Str::uuid());
        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return StampAsset::create([
            'owner_scope'   => $attrs['owner_scope'],
            'owner_id'      => $attrs['owner_scope'] === 'personal' ? $by->id : ($attrs['owner_id'] ?? null),
            'name'          => $attrs['name'],
            'storage_path'  => $path,
            'mime'          => 'image/png',
            'default_w_pct' => $attrs['default_w_pct'] ?? 18,
            'default_h_pct' => $attrs['default_h_pct'] ?? 6,
            'created_by'    => $by->id,
        ]);
    }

    public function delete(StampAsset $asset): void
    {
        Storage::disk(self::DISK)->delete($asset->storage_path);
        $asset->delete();
    }

    public function pngBase64(StampAsset $asset): string
    {
        $bytes = Storage::disk(self::DISK)->get($asset->storage_path);
        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}
