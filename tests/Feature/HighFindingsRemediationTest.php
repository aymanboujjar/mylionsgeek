<?php

use App\Models\Contract;
use App\Models\Medical;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
    Storage::fake('public');
    Storage::fake('documents');
});

function hUser(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'access_cowork' => 0,
        'access_studio' => 0,
        'access_scan' => 0,
        'formation_id' => null,
    ], $overrides));
}

test('H2 coach users index omits cin has_handicap and push tokens', function () {
    $coach = hUser(['coach'], ['email' => 'h2.coach@example.com']);
    $student = hUser(['student'], [
        'email' => 'h2.student@example.com',
        'cin' => 'AB123456',
        'phone' => '0611111111',
        'has_handicap' => true,
        'expo_push_token' => 'ExponentPushToken[secret]',
    ]);

    $this->actingAs($coach)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->has('users')
            ->where('users', function ($users) use ($student) {
                $row = collect($users)->firstWhere('id', $student->id);
                if ($row === null) {
                    return false;
                }
                $row = is_array($row) ? $row : $row->toArray();

                return ! array_key_exists('cin', $row)
                    && ! array_key_exists('has_handicap', $row)
                    && ! array_key_exists('phone', $row)
                    && ! array_key_exists('expo_push_token', $row)
                    && ($row['email'] ?? null) === 'h2.student@example.com';
            }));
});

test('H2 admin users index still includes cin', function () {
    $admin = hUser(['admin'], ['email' => 'h2.admin@example.com']);
    $student = hUser(['student'], [
        'email' => 'h2.admin.student@example.com',
        'cin' => 'CD999999',
        'has_handicap' => false,
    ]);

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->where('users', function ($users) use ($student) {
                $row = collect($users)->firstWhere('id', $student->id);
                if ($row === null) {
                    return false;
                }
                $row = is_array($row) ? $row : $row->toArray();

                return ($row['cin'] ?? null) === 'CD999999'
                    && array_key_exists('has_handicap', $row);
            }));
});

test('H3 peer student profile hides email and resume_url', function () {
    $viewer = hUser(['student'], ['email' => 'h3.viewer@example.com']);
    $peer = hUser(['student'], [
        'email' => 'h3.peer@example.com',
        'resume' => 'peer-cv.pdf',
    ]);
    Storage::disk('documents')->put('resumes/peer-cv.pdf', 'fake-pdf');

    $this->actingAs($viewer)
        ->get('/students/'.$peer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('students/user/partials/StudentProfile')
            ->where('user.user.email', null)
            ->where('user.user.resume_url', null)
            ->where('user.user.resume_view_url', null));
});

test('H3 owner still receives email and gated resume_view_url', function () {
    $owner = hUser(['student'], [
        'email' => 'h3.owner@example.com',
        'resume' => 'owner-cv.pdf',
    ]);
    Storage::disk('documents')->put('resumes/owner-cv.pdf', 'fake-pdf');

    $this->actingAs($owner)
        ->get('/students/'.$owner->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('students/user/partials/StudentProfile')
            ->where('user.user.email', 'h3.owner@example.com')
            ->where('user.user.resume_url', null)
            ->where('user.user.resume_view_url', route('users.resume.view', $owner)));
});

test('H3 mobile peer profile omits email', function () {
    $viewer = hUser(['student'], ['email' => 'h3.mobile.viewer@example.com']);
    $peer = hUser(['student'], ['email' => 'h3.mobile.peer@example.com']);

    $this->actingAs($viewer, 'sanctum')
        ->getJson('/api/mobile/profile/'.$peer->id)
        ->assertOk()
        ->assertJsonPath('email', null)
        ->assertJsonPath('name', $peer->name);
});

test('H4 spaces teamMemberOptions has no emails', function () {
    $student = hUser(['student'], ['email' => 'h4.student@example.com']);
    hUser(['student'], ['email' => 'h4.other@example.com', 'name' => 'Other Student']);
    hUser(['admin'], ['email' => 'h4.admin@example.com', 'name' => 'Hidden Admin']);

    $this->actingAs($student)
        ->get('/students/spaces')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('students/spaces/index')
            ->has('teamMemberOptions')
            ->where('teamMemberOptions', function ($options) {
                $rows = collect($options);
                if ($rows->isEmpty()) {
                    return false;
                }

                $hasEmail = $rows->contains(fn ($row) => array_key_exists('email', is_array($row) ? $row : $row->toArray()));
                $hasAdmin = $rows->contains(function ($row) {
                    $row = is_array($row) ? $row : $row->toArray();

                    return ($row['name'] ?? null) === 'Hidden Admin';
                });

                return ! $hasEmail && ! $hasAdmin;
            }));
});

test('H5 viewDocument with mismatched user returns 404', function () {
    $admin = hUser(['admin'], ['email' => 'h5.admin@example.com']);
    $owner = hUser(['student'], ['email' => 'h5.owner@example.com']);
    $other = hUser(['student'], ['email' => 'h5.other@example.com']);

    Storage::disk('documents')->put('documents/med.pdf', 'medical-bytes');
    $medical = Medical::create([
        'user_id' => $owner->id,
        'mc_document' => 'documents/med.pdf',
        'description' => 'Sick note',
        'author' => 'Admin',
    ]);

    $this->actingAs($admin)
        ->get('/admin/users/'.$other->id.'/documents/medical/'.$medical->id)
        ->assertNotFound();
});

test('H5 upload rejects html and stores on private documents disk', function () {
    $admin = hUser(['admin'], ['email' => 'h5.upload.admin@example.com']);
    $student = hUser(['student'], ['email' => 'h5.upload.student@example.com']);

    $this->actingAs($admin)
        ->post('/admin/users/'.$student->id.'/documents', [
            'kind' => 'medical',
            'file' => UploadedFile::fake()->create('evil.html', 20, 'text/html'),
            'name' => 'Bad',
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->actingAs($admin)
        ->post('/admin/users/'.$student->id.'/documents', [
            'kind' => 'medical',
            'file' => UploadedFile::fake()->create('note.pdf', 40, 'application/pdf'),
            'name' => 'Good',
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $row = Medical::query()->where('user_id', $student->id)->latest('id')->first();
    expect($row)->not->toBeNull();
    expect(Storage::disk('documents')->exists($row->mc_document))->toBeTrue();
    expect(Storage::disk('public')->exists($row->mc_document))->toBeFalse();
});

test('H5 viewDocument streams owned private file', function () {
    $admin = hUser(['admin'], ['email' => 'h5.view.admin@example.com']);
    $student = hUser(['student'], ['email' => 'h5.view.student@example.com']);

    Storage::disk('documents')->put('documents/ok.pdf', '%PDF-1.4 fake');
    $medical = Medical::create([
        'user_id' => $student->id,
        'mc_document' => 'documents/ok.pdf',
        'description' => 'OK',
        'author' => 'Admin',
    ]);

    $this->actingAs($admin)
        ->get('/admin/users/'.$student->id.'/documents/medical/'.$medical->id)
        ->assertOk();
});

test('H7 spoofed X-Forwarded-For does not bypass school network', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = hUser(['student'], [
        'email' => 'h7.student@example.com',
        'formation_id' => null,
    ]);

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.1'])
        ->getJson('/api/mobile/attendance/network-check')
        ->assertForbidden()
        ->assertJson(['message' => 'You must be connected to the school WiFi to check in.']);
});

test('H7 real allowed REMOTE_ADDR passes network check', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = hUser(['student'], ['email' => 'h7.ok@example.com']);

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/api/mobile/attendance/network-check')
        ->assertOk()
        ->assertJson(['ok' => true]);
});
