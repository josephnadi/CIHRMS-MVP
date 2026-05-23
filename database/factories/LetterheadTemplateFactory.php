<?php

namespace Database\Factories;

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LetterheadTemplateFactory extends Factory
{
    protected $model = LetterheadTemplate::class;

    public function definition(): array
    {
        return [
            'owner_scope'      => 'personal',
            'owner_id'         => User::factory(),
            'name'             => 'My Letterhead',
            'storage_path'     => 'assets/letterheads/test.png',
            'mime'             => 'image/png',
            'header_height_mm' => 36,
            'is_default'       => false,
            'created_by'       => User::factory(),
        ];
    }
}
