<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LetterheadTemplateService
{
    private const DISK = 'local';

    public function store(array $attrs, UploadedFile $file, User $by): LetterheadTemplate
    {
        $ext  = strtolower($file->getClientOriginalExtension()) ?: 'png';
        $path = sprintf('assets/letterheads/%s.%s', Str::uuid(), $ext);
        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return LetterheadTemplate::create([
            'owner_scope'      => $attrs['owner_scope'],
            'owner_id'         => $attrs['owner_scope'] === 'personal' ? $by->id : ($attrs['owner_id'] ?? null),
            'name'             => $attrs['name'],
            'storage_path'     => $path,
            'mime'             => $file->getClientMimeType(),
            'header_height_mm' => $attrs['header_height_mm'] ?? 36,
            'is_default'       => false,
            'created_by'       => $by->id,
        ]);
    }

    public function delete(LetterheadTemplate $template): void
    {
        Storage::disk(self::DISK)->delete($template->storage_path);
        $template->delete();
    }

    public function absolutePath(LetterheadTemplate $template): string
    {
        return Storage::disk(self::DISK)->path($template->storage_path);
    }
}
