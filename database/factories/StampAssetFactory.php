<?php

namespace Database\Factories;

use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StampAssetFactory extends Factory
{
    protected $model = StampAsset::class;

    public function definition(): array
    {
        return [
            'owner_scope'   => 'personal',
            'owner_id'      => User::factory(),
            'name'          => 'Approved Stamp',
            'storage_path'  => 'assets/stamps/test.png',
            'mime'          => 'image/png',
            'default_w_pct' => 18,
            'default_h_pct' => 6,
            'created_by'    => User::factory(),
        ];
    }
}
