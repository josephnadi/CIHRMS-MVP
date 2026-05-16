<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('announcements.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'type'          => ['required', Rule::in(array_column(AnnouncementType::cases(), 'value'))],
            'severity'      => ['required', Rule::in(array_column(AnnouncementSeverity::cases(), 'value'))],
            'title'         => ['required', 'string', 'max:180'],
            'body'          => ['nullable', 'string', 'max:2000'],
            'icon'          => ['nullable', 'string', 'max:40'],
            'link_url'      => ['nullable', 'url', 'max:500'],
            'audience_role' => ['nullable', 'string', 'max:40'],
            'pinned'        => ['boolean'],
            'is_active'     => ['boolean'],
            'starts_at'     => ['nullable', 'date'],
            'ends_at'       => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
