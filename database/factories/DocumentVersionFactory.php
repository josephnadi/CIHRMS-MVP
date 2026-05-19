<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_id'   => Document::factory(),
            'version_no'    => 1,
            'original_name' => 'sample.pdf',
            'mime'          => 'application/pdf',
            'size'          => 1024,
            'storage_path'  => 'documents/dummy/v1/sample.pdf',
            'sha256'        => str_repeat('a', 64),
            'uploaded_by'   => User::factory(),
            'uploaded_at'   => now(),
        ];
    }
}
