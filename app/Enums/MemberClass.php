<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The class of CIHRM membership a person holds. Drives which `FeeProduct`s
 * apply to them in a billing run and which UI affordances show.
 *
 *  - Associate    — entry-level qualified member, post-graduation
 *  - Professional — fully chartered practitioner
 *  - Fellow       — senior chartered designation
 *  - Student      — enrolled in a programme but not yet chartered
 *  - Alumni       — formerly chartered, no longer practising
 */
enum MemberClass: string
{
    case Associate    = 'associate';
    case Professional = 'professional';
    case Fellow       = 'fellow';
    case Student      = 'student';
    case Alumni       = 'alumni';

    public function label(): string
    {
        return match ($this) {
            self::Associate    => 'Associate',
            self::Professional => 'Professional',
            self::Fellow       => 'Fellow',
            self::Student      => 'Student',
            self::Alumni       => 'Alumni',
        };
    }
}
