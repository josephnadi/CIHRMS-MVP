<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentConfidentiality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDocumentRequest extends FormRequest
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
            'file'            => ['required', 'file', 'max:25600', 'mimes:pdf,docx,doc,png,jpg,jpeg'],
        ];
    }
}
