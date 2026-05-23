<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WatermarkTemplateService
{
    private const DISK = 'local';

    public function store(array $attrs, ?UploadedFile $file, User $by): WatermarkTemplate
    {
        $path = null;
        if ($attrs['type'] === 'image' && $file) {
            $path = sprintf('assets/watermarks/%s.png', Str::uuid());
            Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));
        }

        return WatermarkTemplate::create([
            'owner_scope'    => $attrs['owner_scope'],
            'owner_id'       => $attrs['owner_scope'] === 'personal' ? $by->id : ($attrs['owner_id'] ?? null),
            'name'           => $attrs['name'],
            'type'           => $attrs['type'],
            'text'           => $attrs['text'] ?? null,
            'color'          => $attrs['color'] ?? '#dc2626',
            'storage_path'   => $path,
            'mime'           => $file?->getClientMimeType(),
            'opacity'        => $attrs['opacity'] ?? 0.18,
            'angle_deg'      => $attrs['angle_deg'] ?? -30,
            'font_size_hint' => $attrs['font_size_hint'] ?? null,
            'created_by'     => $by->id,
        ]);
    }

    public function delete(WatermarkTemplate $template): void
    {
        if ($template->storage_path) {
            Storage::disk(self::DISK)->delete($template->storage_path);
        }
        $template->delete();
    }
}
