<?php

namespace Database\Factories;

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;
        return [
            'uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'ref_no'          => sprintf('CIHRMS/DOC/%d/%04d', now()->year, $seq),
            'title'           => fake()->sentence(4),
            'description'     => fake()->sentence(),
            'owner_id'        => User::factory(),
            'status'          => DocumentStatus::Draft,
            'confidentiality' => DocumentConfidentiality::Internal,
        ];
    }
}
