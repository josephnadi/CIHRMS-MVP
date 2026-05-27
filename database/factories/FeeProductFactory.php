<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\MemberClass;
use App\Models\FeeProduct;
use App\Models\GlAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeeProductFactory extends Factory
{
    protected $model = FeeProduct::class;

    public function definition(): array
    {
        // Test envs need an income GL to point at; if the chart of accounts
        // seeder ran, pick a real income account, otherwise factory one.
        $income = GlAccount::ofType('income')->active()->orderBy('code')->first()
            ?? GlAccount::factory()->state(['type' => 'income'])->create();

        return [
            'code'                 => 'FEE-' . strtoupper(fake()->unique()->lexify('????')),
            'name'                 => fake()->randomElement([
                'Annual Member Dues', 'Graduation Fee', 'Exam Fee', 'Library Card', 'Conference Pass',
            ]) . ' ' . fake()->year(),
            'description'          => fake()->sentence(),
            'amount'               => fake()->randomFloat(2, 50, 1500),
            'currency'             => 'GHS',
            'billing_cycle'        => BillingCycle::Annual->value,
            'applies_to_classes'   => null,
            'gl_income_account_id' => $income->id,
            'is_active'            => true,
        ];
    }

    public function forClasses(array $classes): static
    {
        return $this->state(fn () => [
            'applies_to_classes' => array_map(
                fn ($c) => $c instanceof MemberClass ? $c->value : (string) $c,
                $classes,
            ),
        ]);
    }
}
