<?php

namespace Database\Factories;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'asset_tag'      => 'ASSET-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name'           => fake()->randomElement(['Laptop', 'Monitor', 'Desk Phone', 'Office Chair', 'Projector']) . ' ' . fake()->bothify('??-###'),
            'category'       => fake()->randomElement(AssetCategory::cases())->value,
            'serial_number'  => fake()->optional()->bothify('SN-#######??'),
            'brand'          => fake()->optional()->randomElement(['Dell', 'HP', 'Lenovo', 'Apple', 'Samsung']),
            'model'          => fake()->optional()->bothify('Model-###'),
            'purchase_date'  => fake()->optional()->dateTimeBetween('-5 years', '-1 month')?->format('Y-m-d'),
            'purchase_cost'  => fake()->optional()->randomFloat(2, 100, 5000),
            'currency'       => 'GHS',
            'current_status' => AssetStatus::InStock->value,
        ];
    }
}
