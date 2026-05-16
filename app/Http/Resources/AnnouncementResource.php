<?php

namespace App\Http\Resources;

use App\Enums\AnnouncementType;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type?->value,
            'type_label'=> $this->type?->label(),
            'severity'  => $this->severity?->value,
            'title'     => $this->title,
            'body'      => $this->body,
            'icon'      => $this->icon ?: $this->type?->icon(),
            'link_url'  => $this->link_url,
            'audience_role' => $this->audience_role,
            'pinned'    => (bool) $this->pinned,
            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at'   => $this->ends_at?->toIso8601String(),
            'created_at'=> $this->created_at?->toIso8601String(),
            'author'    => $this->whenLoaded('author', fn () => [
                'id'   => $this->author?->id,
                'name' => $this->author?->name,
            ]),
        ];
    }
}
