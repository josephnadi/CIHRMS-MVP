<?php

namespace App\Enums;

enum CourseFormat: string
{
    case SelfPaced      = 'self_paced';
    case InstructorLed  = 'instructor_led';
    case Blended        = 'blended';
    case External       = 'external';

    public function label(): string
    {
        return match($this) {
            self::SelfPaced     => 'Self-paced',
            self::InstructorLed => 'Instructor-led',
            self::Blended       => 'Blended',
            self::External      => 'External / 3rd-party',
        };
    }
}
