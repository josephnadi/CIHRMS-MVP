<?php

declare(strict_types=1);

namespace App\Http\Requests\Broadcast;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user->hasPermission('broadcasts.manage')) {
            return false;
        }

        // Throttle override requires the bypass perm.
        // We read directly from the user's JSON `permissions` column (bypassing
        // the timestamp-keyed allPermissions() cache) because `bypass_throttle`
        // is a custom per-user grant — never a role default — so the raw column
        // is the canonical source of truth and avoids stale-cache issues when
        // permissions are updated between requests in the same second.
        if ($this->boolean('throttle_overridden')) {
            $customPerms = $user->permissions ?? [];
            if (! in_array('broadcasts.bypass_throttle', $customPerms, true)
                && ! $user->hasPermission('broadcasts.bypass_throttle')) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'title'                    => ['required', 'string', 'max:150'],
            'audience_type'            => ['required', Rule::enum(BroadcastAudienceType::class)],
            'audience_params'          => ['present', 'array'],
            'channels'                 => ['required', 'array', 'min:1'],
            'channels.*'               => [Rule::enum(BroadcastChannel::class)],
            'template_id'              => ['nullable', 'integer', 'exists:broadcast_templates,id'],
            'sms_body'                 => ['nullable', 'string', 'max:1600'],
            'mail_subject'             => ['nullable', 'string', 'max:150'],
            'mail_body'                => ['nullable', 'string'],
            'scheduled_at'             => ['nullable', 'date', 'after:now'],
            'throttle_overridden'      => ['boolean'],
            'throttle_override_reason' => ['nullable', 'string', 'max:255',
                                            'required_if_accepted:throttle_overridden'],
        ];
    }
}
