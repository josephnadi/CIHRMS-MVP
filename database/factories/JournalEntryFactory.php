<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'reference'   => fake()->unique()->bothify('JE-2026-######'),
            'entry_date'  => fake()->date(),
            'narration'   => fake()->sentence(),
            'status'      => JournalEntryStatus::Draft->value,
            'source_type' => JournalSourceType::Manual->value,
            'source_id'   => null,
            'created_by'  => User::factory(),
        ];
    }
}
