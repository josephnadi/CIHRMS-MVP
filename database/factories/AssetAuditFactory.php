<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetAuditFactory extends Factory
{
    protected $model = AssetAudit::class;

    public function definition(): array
    {
        return [
            'reference'  => 'ASA-' . fake()->unique()->numerify('######'),
            'status'     => 'in_progress',
            'scope_type' => 'all',
            'opened_by'  => User::factory(),
            'opened_at'  => now(),
        ];
    }
}
