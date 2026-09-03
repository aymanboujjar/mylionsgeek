<?php

use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    // Migrated sqlite still FKs project_users.project_id to student_projects
    // after that table was renamed. Recreate without that FK for this test.
    Schema::dropIfExists('project_users');
    Schema::create('project_users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role')->default('member');
        $table->timestamp('invited_at')->nullable();
        $table->timestamp('joined_at')->nullable();
        $table->timestamps();
    });
});

function n9Staff(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['coach'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function n9Project(User $owner, string $name = 'N9 Project'): Project
{
    return Project::query()->create([
        'name' => $name,
        'status' => 'active',
        'created_by' => $owner->id,
        'is_updated' => false,
        'last_activity' => null,
    ]);
}

function n9Member(Project $project, User $user, string $role = 'member'): void
{
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'role' => $role,
        'joined_at' => now(),
    ]);
}

function n9Note(Project $project, User $author, array $overrides = []): ProjectNote
{
    $note = new ProjectNote;
    $note->title = $overrides['title'] ?? 'Original title';
    $note->content = $overrides['content'] ?? 'Original content';
    $note->tags = $overrides['tags'] ?? [];
    $note->is_pinned = $overrides['is_pinned'] ?? false;
    $note->color = $overrides['color'] ?? ProjectNote::DEFAULT_COLOR;
    $note->project_id = $project->id;
    $note->user_id = $author->id;
    $note->save();

    return $note;
}

function n9Create(User $user, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->from('/admin/projects/'.($payload['project_id'] ?? '1'))
        ->actingAs($user)
        ->postJson('/admin/project-notes', $payload);
}

function n9Update(User $user, ProjectNote $note, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->from('/admin/projects/'.$note->project_id)
        ->actingAs($user)
        ->putJson('/admin/project-notes/'.$note->id, $payload);
}

function n9Delete(User $user, ProjectNote $note)
{
    Auth::forgetGuards();

    return test()
        ->from('/admin/projects/'.$note->project_id)
        ->actingAs($user)
        ->deleteJson('/admin/project-notes/'.$note->id);
}

function n9Pin(User $user, ProjectNote $note)
{
    Auth::forgetGuards();

    return test()
        ->from('/admin/projects/'.$note->project_id)
        ->actingAs($user)
        ->postJson('/admin/project-notes/'.$note->id.'/pin');
}

test('project member can create a note authored by themselves', function () {
    $owner = n9Staff(['name' => 'N9 Owner']);
    $member = n9Staff(['name' => 'N9 Member']);
    $project = n9Project($owner);
    n9Member($project, $member);

    n9Create($member, [
        'project_id' => $project->id,
        'title' => 'Member note',
        'content' => 'Hello',
        'color' => 'bg-sky-50 dark:bg-sky-950/50',
    ])->assertRedirect();

    $note = ProjectNote::query()->first();
    expect($note)->not->toBeNull()
        ->and((int) $note->project_id)->toBe($project->id)
        ->and((int) $note->user_id)->toBe($member->id)
        ->and($note->title)->toBe('Member note')
        ->and($note->color)->toBe('bg-sky-50 dark:bg-sky-950/50');
});

test('project owner can create a note without a project_users row', function () {
    $owner = n9Staff(['name' => 'N9 Solo Owner']);
    $project = n9Project($owner);

    n9Create($owner, [
        'project_id' => $project->id,
        'title' => 'Owner note',
        'content' => 'From owner',
    ])->assertRedirect();

    expect(ProjectNote::query()->where('project_id', $project->id)->where('user_id', $owner->id)->exists())->toBeTrue();
});

test('non-member staff cannot create a note on another project', function () {
    $owner = n9Staff();
    $outsider = n9Staff(['name' => 'N9 Outsider']);
    $project = n9Project($owner);

    n9Create($outsider, [
        'project_id' => $project->id,
        'title' => 'Intrusion',
        'content' => 'Should not persist',
    ])->assertForbidden();

    expect(ProjectNote::query()->count())->toBe(0);
});

test('create ignores client user_id and keeps Auth id', function () {
    $owner = n9Staff();
    $other = n9Staff(['name' => 'N9 Other Author']);
    $project = n9Project($owner);

    n9Create($owner, [
        'project_id' => $project->id,
        'title' => 'Authored by owner',
        'content' => 'Body',
        'user_id' => $other->id,
        'created_by' => $other->id,
        'role' => 'admin',
        'status' => 'approved',
        'is_private' => 0,
        'approved' => 1,
    ])->assertRedirect();

    $note = ProjectNote::query()->first();
    expect((int) $note->user_id)->toBe($owner->id)
        ->and((int) $note->project_id)->toBe($project->id);
});

test('member cannot create a note on an unauthorized project_id', function () {
    $ownerA = n9Staff(['name' => 'N9 Owner A']);
    $ownerB = n9Staff(['name' => 'N9 Owner B']);
    $member = n9Staff(['name' => 'N9 Member A']);
    $projectA = n9Project($ownerA, 'Project A');
    $projectB = n9Project($ownerB, 'Project B');
    n9Member($projectA, $member);

    n9Create($member, [
        'project_id' => $projectB->id,
        'title' => 'Cross create',
        'content' => 'No',
    ])->assertForbidden();

    expect(ProjectNote::query()->where('project_id', $projectB->id)->exists())->toBeFalse();
});

test('unknown project cannot receive a note', function () {
    $staff = n9Staff();

    n9Create($staff, [
        'project_id' => 999999,
        'title' => 'Ghost',
        'content' => 'No project',
    ])->assertUnprocessable();

    expect(ProjectNote::query()->count())->toBe(0);
});

test('authorized member can update a note', function () {
    $owner = n9Staff();
    $member = n9Staff(['name' => 'N9 Editor']);
    $project = n9Project($owner);
    n9Member($project, $member);
    $note = n9Note($project, $owner);

    n9Update($member, $note, [
        'title' => 'Updated title',
        'content' => 'Updated content',
        'color' => 'bg-rose-50 dark:bg-rose-950/50',
    ])->assertRedirect();

    expect($note->fresh()->title)->toBe('Updated title')
        ->and($note->fresh()->content)->toBe('Updated content')
        ->and($note->fresh()->color)->toBe('bg-rose-50 dark:bg-rose-950/50');
});

test('non-member cannot update a note', function () {
    $owner = n9Staff();
    $outsider = n9Staff(['name' => 'N9 Update Outsider']);
    $project = n9Project($owner);
    $note = n9Note($project, $owner);

    n9Update($outsider, $note, [
        'title' => 'Hijacked',
        'content' => 'Nope',
    ])->assertForbidden();

    expect($note->fresh()->title)->toBe('Original title');
});

test('update cannot change authorship or move the note to another project', function () {
    $owner = n9Staff();
    $other = n9Staff(['name' => 'N9 Fake Author']);
    $otherOwner = n9Staff(['name' => 'N9 Other Owner']);
    $project = n9Project($owner, 'Keep here');
    $otherProject = n9Project($otherOwner, 'Victim project');
    $note = n9Note($project, $owner);

    n9Update($owner, $note, [
        'title' => 'Still mine',
        'content' => 'Still here',
        'user_id' => $other->id,
        'project_id' => $otherProject->id,
        'created_by' => $other->id,
        'role' => 'admin',
        'status' => 'approved',
        'is_private' => 1,
        'approved' => 1,
    ])->assertRedirect();

    $fresh = $note->fresh();
    expect((int) $fresh->user_id)->toBe($owner->id)
        ->and((int) $fresh->project_id)->toBe($project->id)
        ->and($fresh->title)->toBe('Still mine')
        ->and(ProjectNote::query()->where('project_id', $otherProject->id)->exists())->toBeFalse();
});

test('member of project A cannot update a note from project B', function () {
    $ownerA = n9Staff();
    $ownerB = n9Staff();
    $memberA = n9Staff(['name' => 'N9 Member Only A']);
    $projectA = n9Project($ownerA, 'A');
    $projectB = n9Project($ownerB, 'B');
    n9Member($projectA, $memberA);
    $noteB = n9Note($projectB, $ownerB, ['title' => 'Secret B']);

    n9Update($memberA, $noteB, [
        'title' => 'Stolen',
        'content' => 'From A',
        'project_id' => $projectA->id,
    ])->assertForbidden();

    expect($noteB->fresh()->title)->toBe('Secret B')
        ->and((int) $noteB->fresh()->project_id)->toBe($projectB->id);
});

test('authorized member can delete a note and non-member cannot', function () {
    $owner = n9Staff();
    $member = n9Staff(['name' => 'N9 Deleter']);
    $outsider = n9Staff(['name' => 'N9 Delete Outsider']);
    $project = n9Project($owner);
    n9Member($project, $member);
    $allowed = n9Note($project, $owner, ['title' => 'Delete me']);
    $blocked = n9Note($project, $owner, ['title' => 'Keep me']);

    n9Delete($member, $allowed)->assertRedirect();
    expect(ProjectNote::query()->where('id', $allowed->id)->exists())->toBeFalse();

    n9Delete($outsider, $blocked)->assertForbidden();
    expect(ProjectNote::query()->where('id', $blocked->id)->exists())->toBeTrue();
});

test('member of project A cannot delete a note from project B', function () {
    $ownerA = n9Staff();
    $ownerB = n9Staff();
    $memberA = n9Staff();
    $projectA = n9Project($ownerA, 'A');
    $projectB = n9Project($ownerB, 'B');
    n9Member($projectA, $memberA);
    $noteB = n9Note($projectB, $ownerB);

    n9Delete($memberA, $noteB)->assertForbidden();
    expect(ProjectNote::query()->where('id', $noteB->id)->exists())->toBeTrue();
});

test('authorized member can pin and non-member cannot pin another project note', function () {
    $owner = n9Staff();
    $member = n9Staff(['name' => 'N9 Pinner']);
    $outsider = n9Staff(['name' => 'N9 Pin Outsider']);
    $project = n9Project($owner);
    $otherOwner = n9Staff(['name' => 'N9 Other Pin Owner']);
    $other = n9Project($otherOwner, 'Other');
    n9Member($project, $member);
    $ownNote = n9Note($project, $owner, ['is_pinned' => false]);
    $foreignNote = n9Note($other, $otherOwner, ['is_pinned' => false]);

    n9Pin($member, $ownNote)->assertRedirect();
    expect((bool) $ownNote->fresh()->is_pinned)->toBeTrue();

    n9Pin($outsider, $ownNote)->assertForbidden();
    expect((bool) $ownNote->fresh()->is_pinned)->toBeTrue();

    n9Pin($member, $foreignNote)->assertForbidden();
    expect((bool) $foreignNote->fresh()->is_pinned)->toBeFalse();
});
