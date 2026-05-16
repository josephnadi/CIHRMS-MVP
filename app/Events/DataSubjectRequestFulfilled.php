<?php

namespace App\Events;

use App\Models\DataSubjectRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DataSubjectRequestFulfilled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DataSubjectRequest $request) {}
}
