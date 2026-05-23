<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentConfidentiality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ComposeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Document::class);
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'confidentiality' => ['nullable', new Enum(DocumentConfidentiality::class)],
            'tags'            => ['nullable', 'array'],
            'tags.*'          => ['string', 'max:40'],
            // body_html is the composer's HTML output. Bound a generous limit
            // so people can write multi-page memos but still reject obvious
            // attacks (multi-MB pastes). 100k chars ≈ 25 pages of dense text.
            'body_html'       => ['required', 'string', 'min:1', 'max:100000'],
            'letterhead'      => ['nullable', 'boolean'],
            'letterhead_id' => ['nullable', 'integer', 'exists:letterhead_templates,id'],
        ];
    }
}
