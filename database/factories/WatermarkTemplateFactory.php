<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class WatermarkTemplateFactory extends Factory
{
    protected $model = WatermarkTemplate::class;

    public function definition(): array
    {
        return [
            'owner_scope' => 'personal',
            'owner_id'    => User::factory(),
            'name'        => 'Confidential',
            'type'        => 'text',
            'text'        => 'CONFIDENTIAL',
            'color'       => '#dc2626',
            'opacity'     => 0.18,
            'angle_deg'   => -30,
            'created_by'  => User::factory(),
        ];
    }
}
