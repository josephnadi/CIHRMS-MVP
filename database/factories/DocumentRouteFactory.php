<?php

namespace Database\Factories;

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentRouteStatus;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentRouteFactory extends Factory
{
    protected $model = DocumentRoute::class;

    public function definition(): array
    {
        return [
            'document_id'     => Document::factory(),
            'version_id'      => DocumentVersion::factory(),
            'sequence'        => 1,
            'from_user_id'    => User::factory(),
            'to_user_id'      => User::factory(),
            'action_required' => DocumentRouteAction::Sign,
            'status'          => DocumentRouteStatus::Pending,
        ];
    }
}
