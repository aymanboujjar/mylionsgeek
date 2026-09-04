<?php

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

function resumePrivacyUser(array $roles = ['student'], array $overrides = []): User
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

test('new resume uploads land on the private documents disk, not public', function () {
    $owner = resumePrivacyUser(['student']);

    $filename = $owner->storeResumeFromUpload(
        UploadedFile::fake()->create('cv.pdf', 40, 'application/pdf')
    );
    $owner->forceFill(['resume' => $filename])->save();

    expect(Storage::disk('documents')->exists('resumes/'.$filename))->toBeTrue()
        ->and(Storage::disk('public')->exists('resumes/'.$filename))->toBeFalse()
        ->and($owner->resumePublicUrl())->toBeNull();
});

test('unauthenticated resume view is denied', function () {
    $owner = resumePrivacyUser(['student'], ['resume' => 'private-cv.pdf']);
    Storage::disk('documents')->put('resumes/private-cv.pdf', '%PDF-1.4 private');

    $this->get(route('users.resume.view', $owner))
        ->assertRedirect();
});

test('peer cannot view another student resume', function () {
    $owner = resumePrivacyUser(['student'], ['resume' => 'owner-cv.pdf']);
    $peer = resumePrivacyUser(['student']);
    Storage::disk('documents')->put('resumes/owner-cv.pdf', '%PDF-1.4 owner');

    $this->actingAs($peer)
        ->get(route('users.resume.view', $owner))
        ->assertForbidden();
});

test('owner can view gated resume', function () {
    $owner = resumePrivacyUser(['student'], ['resume' => 'owner-cv.pdf']);
    Storage::disk('documents')->put('resumes/owner-cv.pdf', '%PDF-1.4 owner');

    $this->actingAs($owner)
        ->get(route('users.resume.view', $owner))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
