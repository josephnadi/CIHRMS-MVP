<?php

namespace App\Http\Requests\Learning;

use App\Enums\CourseCategory;
use App\Enums\CourseFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('learning.manage');
    }

    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'string', 'max:200'],
            'description'      => ['nullable', 'string', 'max:8000'],
            'category'         => ['sometimes', Rule::enum(CourseCategory::class)],
            'format'           => ['sometimes', Rule::enum(CourseFormat::class)],
            'provider'         => ['nullable', 'string', 'max:120'],
            'cover_image'      => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'price'            => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'skill_tags'       => ['nullable', 'array', 'max:20'],
            'skill_tags.*'     => ['string', 'max:60'],
            'is_published'     => ['boolean'],
        ];
    }
}
