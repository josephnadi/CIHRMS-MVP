<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetReturnedNotification extends Notification implements ShouldQueue
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
        $isAssignee = $notifiable?->id === $this->assignment->employee?->user_id;
        return [
            'kind'    => 'asset_returned',
            'message' => $isAssignee
                ? "You returned asset {$tag}."
                : "Asset {$tag} was returned.",
            'link'    => "/assets/{$this->assignment->asset_id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tag  = $this->assignment->asset?->asset_tag ?? 'asset';
        $name = $this->assignment->asset?->name ?? '';
        $isAssignee = $notifiable?->id === $this->assignment->employee?->user_id;
        $subject = $isAssignee ? "Asset return confirmed — {$tag}" : "Asset returned — {$tag}";
        $line = $isAssignee
            ? "You have returned asset {$tag} ({$name})."
            : "Asset {$tag} ({$name}) has been returned.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->action('View asset', url("/assets/{$this->assignment->asset_id}"));
    }
}
