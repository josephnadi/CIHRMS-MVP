<?php

namespace App\Events;

use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificationIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Certification $certification,
        public readonly ?User $actor = null,
    ) {}
}
