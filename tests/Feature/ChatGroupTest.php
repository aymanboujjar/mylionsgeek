<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Follower;
use App\Models\Message;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function groupChatUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function followUser(User $follower, User $followed): void
{
    Follower::query()->create([
        'follower_id' => $follower->id,
        'followed_id' => $followed->id,
    ]);
}

test('user can create a group with followed members', function () {
    $owner = groupChatUser(['name' => 'Owner']);
    $a = groupChatUser(['name' => 'Alice']);
    $b = groupChatUser(['name' => 'Bob']);
    followUser($owner, $a);
    followUser($owner, $b);

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/chat/groups', [
            'name' => 'Weekend Shoot',
            'member_ids' => [$a->id, $b->id],
        ])
        ->assertCreated()
        ->assertJsonPath('conversation.type', 'group')
        ->assertJsonPath('conversation.name', 'Weekend Shoot')
        ->assertJsonPath('conversation.members_count', 3);

    $conversation = Conversation::query()->where('type', 'group')->first();
    expect($conversation)->not->toBeNull();
    expect(ConversationParticipant::query()->where('conversation_id', $conversation->id)->count())->toBe(3);
});

test('cannot create group with users you do not follow', function () {
    $owner = groupChatUser();
    $stranger = groupChatUser();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/chat/groups', [
            'name' => 'Nope',
            'member_ids' => [$stranger->id],
        ])
        ->assertForbidden();
});

test('group members can list send and read messages', function () {
    $owner = groupChatUser(['name' => 'Owner']);
    $member = groupChatUser(['name' => 'Member']);
    followUser($owner, $member);

    $create = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/chat/groups', [
            'name' => 'Dev Team',
            'member_ids' => [$member->id],
        ])
        ->assertCreated();

    $groupId = $create->json('conversation.id');

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/mobile/chat')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Dev Team', 'type' => 'group']);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/chat/conversation/{$groupId}/send", [
            'body' => 'Hello group',
        ])
        ->assertCreated()
        ->assertJsonPath('message.body', 'Hello group');

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/mobile/chat/groups/{$groupId}")
        ->assertOk()
        ->assertJsonPath('conversation.name', 'Dev Team')
        ->assertJsonCount(1, 'conversation.messages');

    expect(Message::query()->where('conversation_id', $groupId)->count())->toBe(1);
});

test('non member cannot open group', function () {
    $owner = groupChatUser();
    $member = groupChatUser();
    $stranger = groupChatUser();
    followUser($owner, $member);

    $groupId = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/chat/groups', [
            'name' => 'Private',
            'member_ids' => [$member->id],
        ])
        ->json('conversation.id');

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/mobile/chat/groups/{$groupId}")
        ->assertNotFound();
});

test('owner can add members and member can leave', function () {
    $owner = groupChatUser();
    $a = groupChatUser();
    $b = groupChatUser();
    followUser($owner, $a);
    followUser($owner, $b);

    $groupId = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/chat/groups', [
            'name' => 'Crew',
            'member_ids' => [$a->id],
        ])
        ->json('conversation.id');

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/chat/groups/{$groupId}/members", [
            'member_ids' => [$b->id],
        ])
        ->assertOk()
        ->assertJsonPath('conversation.members_count', 3);

    $this->actingAs($a, 'sanctum')
        ->deleteJson("/api/mobile/chat/groups/{$groupId}/members/{$a->id}")
        ->assertOk();

    expect(
        ConversationParticipant::query()
            ->where('conversation_id', $groupId)
            ->where('user_id', $a->id)
            ->exists()
    )->toBeFalse();
});

function makeGroup(User $owner, User ...$members): int
{
    foreach ($members as $member) {
        followUser($owner, $member);
    }

    return (int) test()->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/chat/groups', [
            'name' => 'Deny Paths',
            'member_ids' => collect($members)->pluck('id')->all(),
        ])
        ->assertCreated()
        ->json('conversation.id');
}

test('member cannot rename group', function () {
    $owner = groupChatUser();
    $member = groupChatUser();
    $groupId = makeGroup($owner, $member);

    $this->actingAs($member, 'sanctum')
        ->putJson("/api/mobile/chat/groups/{$groupId}", ['name' => 'Hacked'])
        ->assertForbidden();
});

test('member cannot add members', function () {
    $owner = groupChatUser();
    $member = groupChatUser();
    $extra = groupChatUser();
    followUser($member, $extra);
    $groupId = makeGroup($owner, $member);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/mobile/chat/groups/{$groupId}/members", [
            'member_ids' => [$extra->id],
        ])
        ->assertForbidden();
});

test('member cannot remove another user', function () {
    $owner = groupChatUser();
    $member = groupChatUser();
    $other = groupChatUser();
    $groupId = makeGroup($owner, $member, $other);

    $this->actingAs($member, 'sanctum')
        ->deleteJson("/api/mobile/chat/groups/{$groupId}/members/{$other->id}")
        ->assertForbidden();
});

test('cannot remove the group owner', function () {
    $owner = groupChatUser();
    $member = groupChatUser();
    $groupId = makeGroup($owner, $member);

    // Even as owner acting on self-via-remove-other path is leave; removing owner as different user.
    // Promote is not available — member trying to remove owner.
    $this->actingAs($member, 'sanctum')
        ->deleteJson("/api/mobile/chat/groups/{$groupId}/members/{$owner->id}")
        ->assertForbidden();

    // Owner cannot be removed by another admin path either — create a second owner-like attempt
    // by acting as owner removing themselves is leave (allowed). Removing owner as other fails above.
    expect(
        ConversationParticipant::query()
            ->where('conversation_id', $groupId)
            ->where('user_id', $owner->id)
            ->exists()
    )->toBeTrue();
});

test('stranger cannot send or get messages in a group', function () {
    $owner = groupChatUser();
    $member = groupChatUser();
    $stranger = groupChatUser();
    $groupId = makeGroup($owner, $member);

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/chat/conversation/{$groupId}/send", [
            'body' => 'Nope',
        ])
        ->assertNotFound();

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/mobile/chat/conversation/{$groupId}/messages")
        ->assertNotFound();
});

test('direct conversation unique prevents duplicate pairs', function () {
    $a = groupChatUser();
    $b = groupChatUser();
    followUser($a, $b);

    $first = $this->actingAs($a, 'sanctum')
        ->getJson("/api/mobile/chat/conversation/{$b->id}")
        ->assertOk()
        ->json('conversation.id');

    $second = $this->actingAs($a, 'sanctum')
        ->getJson("/api/mobile/chat/conversation/{$b->id}")
        ->assertOk()
        ->json('conversation.id');

    expect($second)->toBe($first);

    expect(
        Conversation::query()
            ->where('type', 'direct')
            ->where('user_one_id', min($a->id, $b->id))
            ->where('user_two_id', max($a->id, $b->id))
            ->count()
    )->toBe(1);
});
