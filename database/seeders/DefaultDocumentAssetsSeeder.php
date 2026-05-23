<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DefaultDocumentAssetsSeeder extends Seeder
{
    public function run(): void
    {
        if (LetterheadTemplate::where('is_default', true)->exists()) {
            return;
        }
        $src = public_path('img/letterhead.png');
        if (! is_file($src)) {
            $this->command?->warn('public/img/letterhead.png not found; skipping default letterhead seed.');
            return;
        }
        $dst = 'assets/letterheads/default-cihrm-ghana.png';
        Storage::disk('local')->put($dst, file_get_contents($src));

        $creator = User::query()->orderBy('id')->first();
        if (! $creator) {
            $this->command?->warn('No users in database; skipping default letterhead seed.');
            return;
        }

        LetterheadTemplate::create([
            'owner_scope'      => 'organization',
            'owner_id'         => null,
            'name'             => 'CIHRM-GHANA (default)',
            'storage_path'     => $dst,
            'mime'             => 'image/png',
            'header_height_mm' => 36,
            'is_default'       => true,
            'created_by'       => $creator->id,
        ]);
    }
}
