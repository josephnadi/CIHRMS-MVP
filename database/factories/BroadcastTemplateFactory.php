<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BroadcastAudienceType;
use App\Models\BroadcastTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BroadcastTemplateFactory extends Factory
{
    protected $model = BroadcastTemplate::class;

    public function definition(): array
    {
        return [
            'name'          => 'Test template '.$this->faker->randomNumber(),
            'audience_type' => BroadcastAudienceType::AllActiveMembers,
            'sms_body'      => 'Hello {{member.name}}, your fees are due.',
            'mail_subject'  => 'Fees reminder',
            'mail_body'     => 'Dear {{member.name}}, your outstanding balance is GHS {{member.outstanding_total}}.',
            'is_active'     => true,
            'created_by'    => User::factory(),
        ];
    }
}
