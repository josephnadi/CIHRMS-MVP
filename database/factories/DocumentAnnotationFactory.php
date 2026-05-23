<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentAnnotationFactory extends Factory
{
    protected $model = DocumentAnnotation::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_id'  => DocumentVersion::factory(),
            'user_id'     => User::factory(),
            'type'        => 'signature',
            'page'        => 1,
            'x_pct'       => 10, 'y_pct' => 10, 'w_pct' => 22, 'h_pct' => 8,
            'rotation'    => 0,
            'data'        => ['png_base64' => 'data:image/png;base64,iVBORw0KGgo='],
        ];
    }
}
