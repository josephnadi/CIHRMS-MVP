<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BroadcastFactory extends Factory
{
    protected $model = Broadcast::class;

    public function definition(): array
    {
        return [
            'title'           => 'Test broadcast '.$this->faker->randomNumber(),
            'audience_type'   => BroadcastAudienceType::AllActiveMembers,
            'audience_params' => [],
            'channels'        => ['sms', 'mail'],
            'sms_body'        => 'Hello {{member.name}}, this is a test.',
            'mail_subject'    => 'Test broadcast',
            'mail_body'       => 'Hi {{member.name}}, this is a test broadcast.',
            'status'          => BroadcastStatus::Queued,
            'created_by'      => User::factory(),
        ];
    }
}
