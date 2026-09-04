<?php

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Follower;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('attachments');
    $this->withoutVite();

    Schema::dropIfExists('attachments');
    Schema::dropIfExists('project_users');
    Schema::dropIfExists('projects');

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('photo')->nullable();
        $table->string('status')->default('active');
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->unsignedBigInteger('created_by')->nullable();
        $table->boolean('is_updated')->default(false);
        $table->timestamp('last_activity')->nullable();
        $table->timestamps();
    });

    Schema::create('project_users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role')->default('member');
        $table->timestamps();
    });

    Schema::create('attachments', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('original_name')->nullable();
        $table->string('path');
        $table->string('mime_type')->nullable();
        $table->unsignedBigInteger('size')->nullable();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->unsignedBigInteger('task_id')->nullable();
        $table->unsignedBigInteger('uploaded_by')->nullable();
        $table->timestamps();
    });
});

function attachmentPrivacyUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['admin'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

test('chat attachment upload lands on private disk and returns gated url', function () {
    $sender = attachmentPrivacyUser(['role' => ['student']]);
    $recipient = attachmentPrivacyUser(['role' => ['student']]);

    Follower::query()->create([
        'follower_id' => $sender->id,
        'followed_id' => $recipient->id,
    ]);

    $conversation = Conversation::query()->create([
        'user_one_id' => $sender->id,
        'user_two_id' => $recipient->id,
    ]);

    $response = $this->actingAs($sender, 'sanctum')
        ->post('/api/mobile/chat/conversation/'.$conversation->id.'/send', [
            'attachment' => UploadedFile::fake()->image('photo.jpg'),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated();

    $path = $response->json('message.attachment_path');
    $url = $response->json('message.attachment_url');

    expect($path)->toBeString()->not->toBeEmpty()
        ->and(Storage::disk('attachments')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->exists($path))->toBeFalse()
        ->and($url)->toContain('/api/mobile/chat/message/')
        ->and($url)->toContain('/attachment');
});

test('unauthenticated chat attachment download is denied', function () {
    $sender = attachmentPrivacyUser(['role' => ['student']]);
    $recipient = attachmentPrivacyUser(['role' => ['student']]);
    $conversation = Conversation::query()->create([
        'user_one_id' => $sender->id,
        'user_two_id' => $recipient->id,
    ]);

    Storage::disk('attachments')->put('chat/attachments/secret.jpg', 'secret-bytes');

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'body' => null,
        'attachment_path' => 'chat/attachments/secret.jpg',
        'attachment_type' => 'image',
        'attachment_name' => 'secret.jpg',
    ]);

    $this->getJson('/api/mobile/chat/message/'.$message->id.'/attachment')
        ->assertUnauthorized();
});

test('conversation participant can download chat attachment', function () {
    $sender = attachmentPrivacyUser(['role' => ['student']]);
    $recipient = attachmentPrivacyUser(['role' => ['student']]);
    $conversation = Conversation::query()->create([
        'user_one_id' => $sender->id,
        'user_two_id' => $recipient->id,
    ]);

    Storage::disk('attachments')->put('chat/attachments/ok.jpg', 'ok-bytes');

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'body' => null,
        'attachment_path' => 'chat/attachments/ok.jpg',
        'attachment_type' => 'image',
        'attachment_name' => 'ok.jpg',
    ]);

    $this->actingAs($recipient, 'sanctum')
        ->get('/api/mobile/chat/message/'.$message->id.'/attachment')
        ->assertOk();
});

test('stranger cannot download chat attachment', function () {
    $sender = attachmentPrivacyUser(['role' => ['student']]);
    $recipient = attachmentPrivacyUser(['role' => ['student']]);
    $stranger = attachmentPrivacyUser(['role' => ['student']]);
    $conversation = Conversation::query()->create([
        'user_one_id' => $sender->id,
        'user_two_id' => $recipient->id,
    ]);

    Storage::disk('attachments')->put('chat/attachments/ok.jpg', 'ok-bytes');

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'body' => null,
        'attachment_path' => 'chat/attachments/ok.jpg',
        'attachment_type' => 'image',
        'attachment_name' => 'ok.jpg',
    ]);

    $this->actingAs($stranger, 'sanctum')
        ->get('/api/mobile/chat/message/'.$message->id.'/attachment')
        ->assertForbidden();
});

test('project attachment download requires auth and membership', function () {
    $owner = attachmentPrivacyUser();
    $stranger = attachmentPrivacyUser();
    $project = Project::create([
        'name' => 'Private Files',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    Storage::disk('attachments')->put('attachments/notes.pdf', '%PDF-1.4');

    $attachment = Attachment::create([
        'name' => 'notes.pdf',
        'original_name' => 'notes.pdf',
        'path' => 'attachments/notes.pdf',
        'mime_type' => 'application/pdf',
        'size' => 12,
        'project_id' => $project->id,
        'uploaded_by' => $owner->id,
    ]);

    $this->get(route('admin.attachments.download', $attachment))
        ->assertRedirect();

    $this->actingAs($stranger)
        ->get(route('admin.attachments.download', $attachment))
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('admin.attachments.download', $attachment))
        ->assertOk();
});
