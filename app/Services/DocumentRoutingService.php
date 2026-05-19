<?php

namespace App\Services;

use App\Enums\DocumentEventType;
use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Events\DocumentCompleted;
use App\Events\DocumentRejected;
use App\Events\DocumentRouted;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use App\Notifications\DocumentAwaitingAction;
use App\Notifications\DocumentCompletedNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class DocumentRoutingService
{
    public function __construct(private DocumentService $docs) {}

    /**
     * @param  array<int, array{user_id:int, action_required:\App\Enums\DocumentRouteAction|string, due_at?:?string}>  $recipients
     */
    public function route(Document $doc, array $recipients): void
    {
        if ($doc->status !== DocumentStatus::Draft) {
            throw new InvalidArgumentException('Only draft documents can be routed.');
        }
        if (empty($recipients)) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }

        DB::transaction(function () use ($doc, $recipients) {
            foreach ($recipients as $i => $r) {
                DocumentRoute::create([
                    'document_id'     => $doc->id,
                    'version_id'      => $doc->current_version_id,
                    'sequence'        => $i + 1,
                    'from_user_id'    => $i === 0 ? $doc->owner_id
                                                  : $recipients[$i - 1]['user_id'],
                    'to_user_id'      => $r['user_id'],
                    'action_required' => $r['action_required'] instanceof \App\Enums\DocumentRouteAction
                                          ? $r['action_required']->value
                                          : $r['action_required'],
                    'status'          => $i === 0
                                          ? DocumentRouteStatus::InProgress->value
                                          : DocumentRouteStatus::Pending->value,
                    'due_at'          => $r['due_at'] ?? null,
                ]);
            }

            $doc->update(['status' => DocumentStatus::InReview]);

            $first = $doc->routes()->orderBy('sequence')->first();
            $this->docs->logEvent($doc, $doc->owner, DocumentEventType::Routed, [
                'route_count' => count($recipients),
                'first_route' => $first->id,
            ]);

            Notification::send($first->toUser, new DocumentAwaitingAction($doc, $first));
            event(new DocumentRouted($doc, $first));
        });
    }

    public function act(DocumentRoute $route, string $decision, ?string $comment, User $by): void
    {
        if ($route->status !== DocumentRouteStatus::InProgress) {
            throw new InvalidArgumentException('Route is not awaiting action.');
        }
        if (! in_array($decision, ['complete', 'reject'], true)) {
            throw new InvalidArgumentException("Unknown decision: {$decision}");
        }

        DB::transaction(function () use ($route, $decision, $comment, $by) {
            $doc = $route->document;

            if ($decision === 'reject') {
                $route->update([
                    'status'   => DocumentRouteStatus::Rejected,
                    'acted_at' => now(),
                    'comment'  => $comment,
                ]);
                $doc->routes()
                    ->where('sequence', '>', $route->sequence)
                    ->update(['status' => DocumentRouteStatus::Cancelled->value]);
                $doc->update(['status' => DocumentStatus::Rejected]);

                $this->docs->logEvent($doc, $by, DocumentEventType::Rejected, [
                    'route_id' => $route->id,
                    'comment'  => $comment,
                ]);

                Notification::send($doc->owner, new DocumentCompletedNotice($doc, rejected: true));
                event(new DocumentRejected($doc, $route));
                return;
            }

            // complete
            $route->update([
                'status'   => DocumentRouteStatus::Completed,
                'acted_at' => now(),
                'comment'  => $comment,
            ]);

            $next = $doc->routes()
                ->where('sequence', '>', $route->sequence)
                ->orderBy('sequence')
                ->first();

            if ($next) {
                $next->update(['status' => DocumentRouteStatus::InProgress->value]);
                $this->docs->logEvent($doc, $by, DocumentEventType::Forwarded, [
                    'from_route' => $route->id,
                    'to_route'   => $next->id,
                ]);
                Notification::send($next->toUser, new DocumentAwaitingAction($doc, $next));
                return;
            }

            $doc->update(['status' => DocumentStatus::Completed]);
            $this->docs->logEvent($doc, $by, DocumentEventType::Completed, [
                'route_id' => $route->id,
            ]);
            Notification::send($doc->owner, new DocumentCompletedNotice($doc, rejected: false));
            event(new DocumentCompleted($doc));
        });
    }

    public function withdraw(Document $doc, User $by): void
    {
        if ($doc->status !== DocumentStatus::InReview) {
            throw new InvalidArgumentException('Only in-review documents can be withdrawn.');
        }

        DB::transaction(function () use ($doc, $by) {
            $doc->routes()
                ->whereIn('status', [
                    DocumentRouteStatus::InProgress->value,
                    DocumentRouteStatus::Pending->value,
                ])
                ->update(['status' => DocumentRouteStatus::Cancelled->value]);

            $doc->update(['status' => DocumentStatus::Withdrawn]);
            $this->docs->logEvent($doc, $by, DocumentEventType::Withdrawn);
        });
    }
}
