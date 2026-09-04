<?php

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
        $table->string('role')->nullable();
        $table->timestamp('invited_at')->nullable();
        $table->timestamp('joined_at')->nullable();
        $table->timestamps();
    });

    Schema::create('attachments', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('original_name');
        $table->string('path');
        $table->string('mime_type')->nullable();
        $table->unsignedBigInteger('size')->nullable();
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('task_id')->nullable();
        $table->unsignedBigInteger('uploaded_by')->nullable();
        $table->timestamps();
    });
});

function projectUploadSecurityUser(array $roles = ['coach'], array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

test('svg project attachment upload is rejected and not stored', function () {
    $user = projectUploadSecurityUser();
    $project = Project::create([
        'name' => 'Secure Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $svg = UploadedFile::fake()->create('xss.svg', 12, 'image/svg+xml');

    $this->actingAs($user)
        ->post(route('admin.projects.upload-attachment'), [
            'project_id' => $project->id,
            'file' => $svg,
        ])
        ->assertSessionHasErrors('file');

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(\App\Models\Attachment::count())->toBe(0);
});

test('html project attachment upload is rejected and not stored', function () {
    $user = projectUploadSecurityUser();
    $project = Project::create([
        'name' => 'Secure Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $html = UploadedFile::fake()->create('page.html', 20, 'text/html');

    $this->actingAs($user)
        ->post(route('admin.attachments.store'), [
            'project_id' => $project->id,
            'file' => $html,
        ])
        ->assertSessionHasErrors('file');

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(\App\Models\Attachment::count())->toBe(0);
});

test('pdf project attachment upload is accepted', function () {
    $user = projectUploadSecurityUser();
    $project = Project::create([
        'name' => 'Secure Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $pdf = UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf');

    $this->actingAs($user)
        ->post(route('admin.projects.upload-attachment'), [
            'project_id' => $project->id,
            'file' => $pdf,
        ])
        ->assertSessionDoesntHaveErrors();

    expect(\App\Models\Attachment::count())->toBe(1)
        ->and(Storage::disk('attachments')->allFiles())->not->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('zip project attachment upload is rejected', function () {
    $user = projectUploadSecurityUser();
    $project = Project::create([
        'name' => 'Secure Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $zip = UploadedFile::fake()->create('archive.zip', 40, 'application/zip');

    $this->actingAs($user)
        ->post(route('admin.projects.upload-attachment'), [
            'project_id' => $project->id,
            'file' => $zip,
        ])
        ->assertSessionHasErrors('file');

    expect(\App\Models\Attachment::count())->toBe(0)
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);
});
