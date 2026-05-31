<?php

declare(strict_types=1);

namespace App\Http\Requests\Broadcast;

use App\Enums\BroadcastAudienceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('broadcasts.manage');
    }

    public function rules(): array
    {
        // The template must have at least one of (sms_body, mail_body+subject).
        // We use required_without to enforce: if sms_body is missing, mail_*
        // must be present, and vice versa.
        return [
            'name'          => ['required', 'string', 'max:150'],
            'audience_type' => ['required', Rule::enum(BroadcastAudienceType::class)],
            'sms_body'      => ['required_without_all:mail_subject,mail_body', 'nullable', 'string', 'max:1600'],
            'mail_subject'  => ['required_without:sms_body', 'nullable', 'string', 'max:150'],
            'mail_body'     => ['required_without:sms_body', 'nullable', 'string'],
            'is_active'     => ['boolean'],
        ];
    }
}
