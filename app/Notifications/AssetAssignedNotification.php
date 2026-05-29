<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AssetAssignment $assignment) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $tag = $this->assignment->asset?->asset_tag ?? 'asset';
        return [
            'kind'    => 'asset_assigned',
            'message' => "Asset {$tag} has been assigned to you.",
            'link'    => "/assets/{$this->assignment->asset_id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tag  = $this->assignment->asset?->asset_tag ?? 'asset';
        $name = $this->assignment->asset?->name ?? '';
        return (new MailMessage())
            ->subject("Asset assigned — {$tag}")
            ->line("Asset {$tag} ({$name}) has been assigned to you.")
            ->action('View asset', url("/assets/{$this->assignment->asset_id}"));
    }
}
