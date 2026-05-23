<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\DocumentConfidentiality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Metadata-only update (Documents v2 — Phase 1). Asset-picker fields
 * (letterhead_id, watermark_id, watermark_mode) ship in later phases.
 *
 * The model-bound `document` is whatever Laravel resolved from the route
 * parameter; ownership / status checks are enforced by DocumentPolicy::update,
 * which the controller calls explicitly.
 */
class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) === true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['sometimes', 'required', 'string', 'max:255'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confidentiality' => ['sometimes', new Enum(DocumentConfidentiality::class)],
            'tags'            => ['sometimes', 'nullable', 'array'],
            'tags.*'          => ['string', 'max:40'],
            'letterhead_id'   => ['sometimes', 'nullable', 'integer', 'exists:letterhead_templates,id'],
        ];
    }
}
