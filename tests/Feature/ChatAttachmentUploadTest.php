<?php

use App\Models\Conversation;
use App\Models\Follower;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

function m4User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function m4Conversation(User $sender, User $recipient, bool $follow = true): Conversation
{
    if ($follow) {
        Follower::query()->create([
            'follower_id' => $sender->id,
            'followed_id' => $recipient->id,
        ]);
    }

    return Conversation::query()->create([
        'user_one_id' => $sender->id,
        'user_two_id' => $recipient->id,
    ]);
}

function m4Send($user, Conversation $conversation, array $payload)
{
    return test()
        ->actingAs($user, 'sanctum')
        ->post('/api/mobile/chat/conversation/'.$conversation->id.'/send', $payload, [
            'Accept' => 'application/json',
        ]);
}

test('anonymous cannot upload a chat attachment', function () {
    $sender = m4User();
    $recipient = m4User();
    $conversation = m4Conversation($sender, $recipient);

    $this->post('/api/mobile/chat/conversation/'.$conversation->id.'/send', [
        'attachment' => UploadedFile::fake()->image('photo.jpg'),
    ], [
        'Accept' => 'application/json',
    ])->assertUnauthorized();

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('chat/attachments'))->toBeEmpty();
});

test('dangerous chat attachments are rejected and not stored', function (string $name, string $mime) {
    $sender = m4User();
    $recipient = m4User();
    $conversation = m4Conversation($sender, $recipient);

    m4Send($sender, $conversation, [
        'attachment' => UploadedFile::fake()->create($name, 20, $mime),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachment');

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('chat/attachments'))->toBeEmpty();
})->with([
    'html' => ['xss.html', 'text/html'],
    'svg' => ['xss.svg', 'image/svg+xml'],
    'js' => ['payload.js', 'application/javascript'],
    'php' => ['shell.php', 'application/x-httpd-php'],
]);

test('allowed jpeg png pdf and m4a attachments are accepted', function (string $name, string $mime, string $expectedType, bool $useImageFake) {
    $sender = m4User();
    $recipient = m4User();
    $conversation = m4Conversation($sender, $recipient);

    $file = $useImageFake
        ? UploadedFile::fake()->image($name)
        : UploadedFile::fake()->create($name, 20, $mime);

    $response = m4Send($sender, $conversation, [
        'attachment' => $file,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message.attachment_type', $expectedType)
        ->assertJsonPath('message.attachment_name', $name);

    $path = $response->json('message.attachment_path');
    expect($path)->toBeString()->not->toBeEmpty()
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Message::query()->count())->toBe(1);
})->with([
    'jpeg' => ['photo.jpg', 'image/jpeg', 'image', true],
    'png' => ['photo.png', 'image/png', 'image', true],
    'pdf' => ['doc.pdf', 'application/pdf', 'file', false],
    'm4a' => ['voice.m4a', 'audio/mp4', 'audio', false],
]);

test('client attachment_type image cannot make html acceptable', function () {
    $sender = m4User();
    $recipient = m4User();
    $conversation = m4Conversation($sender, $recipient);

    m4Send($sender, $conversation, [
        'attachment' => UploadedFile::fake()->create('xss.html', 20, 'text/html'),
        'attachment_type' => 'image',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachment');

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('chat/attachments'))->toBeEmpty();
});

test('sender must belong to the conversation', function () {
    $sender = m4User();
    $recipient = m4User();
    $stranger = m4User();
    $conversation = m4Conversation($sender, $recipient);

    Follower::query()->create([
        'follower_id' => $stranger->id,
        'followed_id' => $recipient->id,
    ]);

    m4Send($stranger, $conversation, [
        'attachment' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertNotFound();

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('chat/attachments'))->toBeEmpty();
});

test('sender must follow the other participant', function () {
    $sender = m4User();
    $recipient = m4User();
    $conversation = m4Conversation($sender, $recipient, follow: false);

    m4Send($sender, $conversation, [
        'attachment' => UploadedFile::fake()->image('photo.jpg'),
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'You can only message users you follow');

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('chat/attachments'))->toBeEmpty();
});
