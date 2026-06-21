<?php

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->create(['name' => 'Alice Mensah']);
    $this->bob   = User::factory()->create(['name' => 'Bob Owusu']);
    $this->eve   = User::factory()->create(['name' => 'Eve Asante']);
});

it('shows the directory excluding the current user', function () {
    $this->actingAs($this->alice)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chat/Index')
            ->has('directory.data', 2)  // Bob + Eve, not Alice
            ->has('recent')
            ->where('unreadTotal', 0)
        );
});

it('is reachable by every role and the directory spans all roles', function () {
    // A non-privileged employee must be able to open chat and see people of
    // EVERY role (admins, finance, support, …) — messaging cuts across roles.
    $employee = User::factory()->create(['name' => 'Worker One', 'role' => 'employee']);
    $admin    = User::factory()->create(['name' => 'Admin One',  'role' => 'super_admin']);
    $finance  = User::factory()->create(['name' => 'Finance One','role' => 'finance_officer']);
    $support  = User::factory()->create(['name' => 'IT One',     'role' => 'it_support']);

    $res = $this->actingAs($employee)
        ->get(route('chat.index'))
        ->assertOk();

    $names = collect($res->viewData('page')['props']['directory']['data'] ?? [])->pluck('name');
    // Alice/Bob/Eve from beforeEach + the three other-role users are all present;
    // the employee themselves is excluded.
    expect($names)->toContain('Admin One', 'Finance One', 'IT One')
        ->and($names)->not->toContain('Worker One');
});

it('searching the directory narrows by name', function () {
    $this->actingAs($this->alice)
        ->get(route('chat.index', ['q' => 'Bob']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chat/Index')
            ->has('directory.data', 1)
            ->where('directory.data.0.name', 'Bob Owusu')
        );
});

it('opens a 1-on-1 conversation and reuses it on the second open', function () {
    // First open creates the conversation
    $first = $this->actingAs($this->alice)
        ->get(route('chat.openWith', $this->bob->id))
        ->assertRedirect();

    $conv1 = Conversation::firstOrFail();
    expect($conv1->participants()->count())->toBe(2);

    // Second open from either side reuses the same conversation
    $this->actingAs($this->bob)
        ->get(route('chat.openWith', $this->alice->id))
        ->assertRedirect(route('chat.show', $conv1));

    expect(Conversation::count())->toBe(1);
});

it('refuses to start a conversation with yourself', function () {
    $this->actingAs($this->alice)
        ->get(route('chat.openWith', $this->alice->id))
        ->assertRedirect(route('chat.index'));

    expect(Conversation::count())->toBe(0);
});

it('sends a message and the recipient sees it', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);

    $this->actingAs($this->alice)
        ->post(route('chat.send', $conv), ['body' => 'Hello Bob 👋'])
        ->assertRedirect();

    expect($conv->fresh()->messages()->count())->toBe(1);
    expect($conv->fresh()->last_message_at)->not->toBeNull();

    // Bob's directory now reports 1 unread
    $this->actingAs($this->bob)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chat/Index')
            ->where('unreadTotal', 1)
        );
});

it('opening the conversation marks all of it as read', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);
    $this->actingAs($this->alice)->post(route('chat.send', $conv), ['body' => 'one']);
    $this->actingAs($this->alice)->post(route('chat.send', $conv), ['body' => 'two']);

    $this->actingAs($this->bob)
        ->get(route('chat.show', $conv))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chat/Show')
            ->has('messages', 2)
            ->where('unreadTotal', 0)  // marked read on open
        );
});

it('blocks a non-participant from viewing a conversation', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);

    $this->actingAs($this->eve)
        ->get(route('chat.show', $conv))
        ->assertForbidden();
});

it('blocks a non-participant from sending into a conversation', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);

    $this->actingAs($this->eve)
        ->post(route('chat.send', $conv), ['body' => 'sneaky'])
        ->assertForbidden();

    expect($conv->fresh()->messages()->count())->toBe(0);
});

it('poll returns only messages newer than `since`', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);
    $this->actingAs($this->alice)->post(route('chat.send', $conv), ['body' => 'first']);
    $this->actingAs($this->alice)->post(route('chat.send', $conv), ['body' => 'second']);

    $firstId = ChatMessage::orderBy('id')->first()->id;

    $this->actingAs($this->bob)
        ->getJson(route('chat.poll', $conv) . '?since=' . $firstId)
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', 'second');
});

it('sender can soft-delete their own message', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);
    $this->actingAs($this->alice)->post(route('chat.send', $conv), ['body' => 'oops']);

    $msg = ChatMessage::firstOrFail();

    $this->actingAs($this->alice)
        ->delete(route('chat.messages.destroy', $msg))
        ->assertRedirect();

    expect($msg->fresh()->deleted_for_everyone_at)->not->toBeNull();

    // Bob's view no longer includes the deleted message
    $this->actingAs($this->bob)
        ->get(route('chat.show', $conv))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chat/Show')
            ->has('messages', 0)
        );
});

it('recipient cannot delete a message they did not send', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);
    $this->actingAs($this->alice)->post(route('chat.send', $conv), ['body' => 'cant touch this']);
    $msg = ChatMessage::firstOrFail();

    $this->actingAs($this->bob)
        ->delete(route('chat.messages.destroy', $msg))
        ->assertForbidden();

    expect($msg->fresh()->deleted_for_everyone_at)->toBeNull();
});

it('validates the message body', function () {
    $conv = Conversation::findOrCreateOneOnOne($this->alice, $this->bob);

    $this->actingAs($this->alice)
        ->postJson(route('chat.send', $conv), ['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});
