<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal chat ("Messages" in the sidebar). One-on-one only for now.
 *
 *   GET  /chat                            — directory of all employees + recent threads
 *   GET  /chat/with/{user}                — open (or create) the conversation with this user
 *   GET  /chat/{conversation}             — show a specific conversation by id
 *   POST /chat/{conversation}/messages    — send a message
 *   GET  /chat/{conversation}/poll        — JSON poll endpoint for new messages
 *   DELETE /chat/messages/{message}       — sender retracts their own message
 */
class ChatController extends Controller
{
    /**
     * Directory of everyone you can chat with, plus a strip of your recent
     * conversations. We exclude the current user from the directory, and
     * paginate the rest so the page is bounded.
     */
    public function index(Request $request): Response
    {
        $me = $request->user();

        $q = trim((string) $request->string('q'));

        $directory = User::query()
            ->where('id', '!=', $me->id)
            ->with(['employee:id,user_id,position,department_id,avatar_path', 'employee.department:id,name'])
            ->when($q !== '', function ($builder) use ($q) {
                $builder->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('staff_id', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->paginate(18)
            ->withQueryString();

        // Recent threads (max 6) — used for the "Continue chatting" strip
        $recent = Conversation::query()
            ->forUser($me)
            ->whereNotNull('last_message_at')
            ->with(['participants:id,name,email', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(6)
            ->get()
            ->map(fn (Conversation $c) => $this->serialiseConversationSummary($c, $me));

        return Inertia::render('Chat/Index', [
            'directory'   => $directory,
            'recent'      => $recent,
            'unreadTotal' => $this->unreadTotalFor($me),
            'filters'     => ['q' => $q],
        ]);
    }

    /**
     * Open (or create) the 1:1 conversation with `$other` and redirect to its show page.
     * This is the entry-point clicked from the directory cards.
     */
    public function openWith(Request $request, User $other): RedirectResponse
    {
        $me = $request->user();

        if ($me->id === $other->id) {
            return redirect()->route('chat.index')
                ->with('flash', ['type' => 'error', 'message' => "You can't chat with yourself."]);
        }

        $conv = Conversation::findOrCreateOneOnOne($me, $other);

        return redirect()->route('chat.show', $conv);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $me = $request->user();
        $this->authorize('view', $conversation);

        // Mark read on open. We don't gate this — the act of opening the
        // thread implies you've seen the messages above this moment.
        $conversation->participants()
            ->updateExistingPivot($me->id, ['last_read_at' => now()]);

        $conversation->load([
            'participants:id,name,email',
            'participants.employee:id,user_id,position,department_id,avatar_path',
            'participants.employee.department:id,name',
        ]);

        // Sidebar list — your other conversations, latest first.
        $threads = Conversation::query()
            ->forUser($me)
            ->with(['participants:id,name,email', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(30)
            ->get()
            ->map(fn (Conversation $c) => $this->serialiseConversationSummary($c, $me));

        $messages = $conversation->messages()
            ->visible()
            ->with('sender:id,name')
            ->limit(200)
            ->get()
            ->map(fn (ChatMessage $m) => $this->serialiseMessage($m));

        return Inertia::render('Chat/Show', [
            'conversation' => [
                'id'                 => $conversation->id,
                'is_group'           => $conversation->is_group,
                'title'              => $conversation->title,
                'other'              => $this->participantDto($conversation->otherParticipant($me)),
                'participants'       => $conversation->participants->map(fn ($p) => $this->participantDto($p))->values(),
            ],
            'messages'    => $messages,
            'threads'     => $threads,
            'me'          => ['id' => $me->id, 'name' => $me->name],
            'unreadTotal' => $this->unreadTotalFor($me),
        ]);
    }

    /**
     * Send a message into a conversation. Returns redirect for Inertia
     * form posts (the Show page polls for new messages anyway).
     */
    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        DB::transaction(function () use ($conversation, $request, $data) {
            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $request->user()->id,
                'body'            => $data['body'],
            ]);

            // Bump the thread + mark sender's last_read_at so it doesn't
            // count as unread for the sender themselves.
            $conversation->update(['last_message_at' => $message->created_at]);
            $conversation->participants()
                ->updateExistingPivot($request->user()->id, ['last_read_at' => $message->created_at]);
        });

        return back();
    }

    /**
     * Lightweight JSON poll — returns any messages newer than `?since=<id>`.
     * Show.vue polls this every 4s while the thread is open.
     */
    public function poll(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $since = (int) $request->query('since', 0);

        $new = $conversation->messages()
            ->visible()
            ->where('id', '>', $since)
            ->with('sender:id,name')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (ChatMessage $m) => $this->serialiseMessage($m));

        if ($new->isNotEmpty()) {
            // Touch read pointer to the newest delivered id so the unread
            // badge falls back to zero while the user is actively reading.
            $conversation->participants()
                ->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);
        }

        return response()->json([
            'messages'    => $new,
            'unreadTotal' => $this->unreadTotalFor($request->user()),
        ]);
    }

    public function destroyMessage(Request $request, ChatMessage $message): RedirectResponse
    {
        $this->authorize('deleteMessage', $message);

        $message->update(['deleted_for_everyone_at' => now()]);

        return back();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function serialiseMessage(ChatMessage $m): array
    {
        return [
            'id'         => $m->id,
            'sender_id'  => $m->sender_id,
            'sender'     => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
            'body'       => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
            'time'       => $m->created_at?->format('H:i'),
            'date'       => $m->created_at?->format('Y-m-d'),
        ];
    }

    private function serialiseConversationSummary(Conversation $c, $me): array
    {
        $other = $c->otherParticipant($me);
        $latest = $c->latestMessage()->first() ?? null;
        $myPivot = $c->participants->firstWhere('id', $me->id)?->pivot;
        $unread = 0;
        if ($latest && (! $myPivot?->last_read_at || $myPivot->last_read_at < $latest->created_at)) {
            $unread = $c->messages()
                ->visible()
                ->where('created_at', '>', $myPivot?->last_read_at ?? '1970-01-01')
                ->where('sender_id', '!=', $me->id)
                ->count();
        }

        return [
            'id'              => $c->id,
            'other'           => $this->participantDto($other),
            'last_message'    => $latest ? [
                'body'       => $latest->deleted_for_everyone_at ? '(message deleted)' : $latest->body,
                'is_mine'    => $latest->sender_id === $me->id,
                'created_at' => $latest->created_at?->toIso8601String(),
                'time'       => $latest->created_at?->diffForHumans(['short' => true]),
            ] : null,
            'unread_count'    => $unread,
        ];
    }

    private function participantDto(?User $u): ?array
    {
        if (! $u) return null;
        $emp = $u->relationLoaded('employee') ? $u->employee : null;
        return [
            'id'         => $u->id,
            'name'       => $u->name,
            'email'      => $u->email,
            'position'   => $emp?->position,
            'department' => $emp?->department?->name,
            'avatar_url' => $emp?->avatarUrl ?: null,
            'initials'   => $this->initials($u->name ?? ''),
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0][0] ?? '';
        $last  = end($parts);
        $last  = $last && $last !== ($parts[0] ?? '') ? $last[0] : '';
        return strtoupper($first.$last);
    }

    private function unreadTotalFor($me): int
    {
        return (int) DB::table('chat_messages')
            ->join('conversation_user', 'conversation_user.conversation_id', '=', 'chat_messages.conversation_id')
            ->where('conversation_user.user_id', $me->id)
            ->whereNull('chat_messages.deleted_for_everyone_at')
            ->where('chat_messages.sender_id', '!=', $me->id)
            ->where(function ($q) {
                $q->whereNull('conversation_user.last_read_at')
                  ->orWhereColumn('chat_messages.created_at', '>', 'conversation_user.last_read_at');
            })
            ->count();
    }
}
