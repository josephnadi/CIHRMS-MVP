<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'description'      => $this->description,
            'category'         => $this->category?->value,
            'category_label'   => $this->category?->label(),
            'category_color'   => $this->category?->color(),
            'format'           => $this->format?->value,
            'format_label'     => $this->format?->label(),
            'provider'         => $this->provider,
            'cover_image'      => $this->cover_image,
            'duration_minutes' => $this->duration_minutes,
            'duration_label'   => $this->duration_label,
            'price'            => (float) $this->price,
            'currency'         => $this->currency,
            'skill_tags'       => $this->skill_tags ?? [],
            'is_published'     => (bool) $this->is_published,
            'published_at'     => $this->published_at?->toIso8601String(),
            'enrolled_count'   => $this->enrolled_count ?? null,
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
