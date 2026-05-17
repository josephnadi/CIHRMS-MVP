<?php

namespace Database\Factories;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'type'          => AnnouncementType::Notice->value,
            'severity'      => AnnouncementSeverity::Info->value,
            'title'         => fake()->sentence(6),
            'body'          => fake()->sentence(12),
            'icon'          => null,
            'link_url'      => null,
            'audience_role' => null,   // null = everyone
            'pinned'        => false,
            'is_active'     => true,
            'starts_at'     => null,
            'ends_at'       => null,
            'created_by'    => User::factory(),
        ];
    }

    public function pinned(): self
    {
        return $this->state(fn () => ['pinned' => true]);
    }

    public function urgent(): self
    {
        return $this->state(fn () => ['severity' => AnnouncementSeverity::Urgent->value]);
    }

    public function important(): self
    {
        return $this->state(fn () => ['severity' => AnnouncementSeverity::Important->value]);
    }

    public function forRole(string $role): self
    {
        return $this->state(fn () => ['audience_role' => $role]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function scheduled(\DateTimeInterface $startsAt): self
    {
        return $this->state(fn () => ['starts_at' => $startsAt]);
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(14),
            'ends_at'   => now()->subDays(1),
        ]);
    }
}
