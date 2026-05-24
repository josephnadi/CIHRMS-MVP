<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'is_group', 'title', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_group'        => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot(['last_read_at', 'archived_at', 'muted_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function scopeForUser(Builder $q, User $user): Builder
    {
        return $q->whereHas('participants', fn ($q) => $q->where('users.id', $user->id));
    }

    /**
     * Resolve (or create) the unique 1:1 conversation between two users.
     * Both sides see exactly the same conversation_id — we don't create
     * a second row when person B replies from their side.
     *
     * The DB::transaction + re-check under sharedLock closes the race where
     * two concurrent "open chat with B" calls from A would otherwise both
     * miss the existence check and create two conversations for the same pair.
     */
    public static function findOrCreateOneOnOne(User $a, User $b): self
    {
        if ($a->id === $b->id) {
            throw new \DomainException('Cannot start a conversation with yourself.');
        }

        $finder = fn () => static::query()
            ->where('is_group', false)
            ->whereHas('participants', fn ($q) => $q->where('users.id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $b->id))
            ->first();

        // Cheap reuse path — no need to open a transaction if the row already exists.
        if ($existing = $finder()) {
            return $existing;
        }

        return DB::transaction(function () use ($a, $b, $finder) {
            // Re-check under a shared lock. If a concurrent caller created
            // the conversation between our first check and the transaction
            // opening, we return their row instead of creating a duplicate.
            $existing = static::query()
                ->where('is_group', false)
                ->whereHas('participants', fn ($q) => $q->where('users.id', $a->id))
                ->whereHas('participants', fn ($q) => $q->where('users.id', $b->id))
                ->sharedLock()
                ->first();

            if ($existing) {
                return $existing;
            }

            $conv = static::create(['is_group' => false]);
            $conv->participants()->attach([$a->id, $b->id]);
            return $conv;
        });
    }

    /** Return the "other" participant for 1:1 chats (or null for groups). */
    public function otherParticipant(User $me): ?User
    {
        if ($this->is_group) return null;
        // Note: Eloquent collection firstWhere accepts only ['=','!=','<>','<','<=','>','>=']
        // — strict-equality operators are silently treated as something else, so use
        // the explicit closure form for safety.
        return $this->participants->first(fn ($u) => $u->id !== $me->id);
    }
}
