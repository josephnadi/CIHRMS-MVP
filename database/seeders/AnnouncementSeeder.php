<?php

namespace Database\Seeders;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Seeder;

class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::where('staff_id', 'HR-001')->first()
              ?? User::where('staff_id', 'ADMIN-001')->first()
              ?? User::first();

        $samples = [
            [
                'type'      => AnnouncementType::Notice->value,
                'severity'  => AnnouncementSeverity::Important->value,
                'title'     => 'Quarterly all-hands meeting on Friday, 3:00 PM in the main auditorium',
                'body'      => 'Quarterly results, strategy reset, Q&A with the executive.',
                'pinned'    => true,
                'is_active' => true,
            ],
            [
                'type'      => AnnouncementType::System->value,
                'severity'  => AnnouncementSeverity::Info->value,
                'title'     => 'Payroll cut-off this month is Wednesday 26th — submit overtime by 5pm',
                'is_active' => true,
            ],
            [
                'type'      => AnnouncementType::Event->value,
                'severity'  => AnnouncementSeverity::Info->value,
                'title'     => 'New employee orientation begins Monday in the Accra training room',
                'is_active' => true,
            ],
            [
                'type'      => AnnouncementType::Notice->value,
                'severity'  => AnnouncementSeverity::Urgent->value,
                'title'     => 'Office closes at 1pm Friday for the annual staff retreat',
                'is_active' => true,
            ],
        ];

        foreach ($samples as $data) {
            Announcement::create([
                ...$data,
                'created_by' => $author?->id,
            ]);
        }
    }
}
