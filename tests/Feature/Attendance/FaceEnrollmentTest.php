<?php

use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Schema::dropAllTables();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('remember_token')->nullable();
        $table->json('role')->nullable();
        $table->string('status')->default('Studying');
        $table->integer('formation_id')->nullable();
        $table->timestamps();
    });

    Schema::create('formations', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('img')->nullable();
        $table->string('category')->nullable();
        $table->string('start_time')->nullable();
        $table->string('end_time')->nullable();
        $table->integer('user_id')->nullable();
        $table->timestamps();
    });

    Schema::create('face_enrollments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->string('disk');
        $table->string('path');
        $table->unsignedBigInteger('enrolled_by')->nullable();
        $table->timestamp('enrolled_at');
        $table->timestamps();
    });

    $this->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\EnsureOrganisationOnboarded::class,
        \App\Http\Middleware\UpdateRolesUsers::class,
    ]);
});

function m8EnrollmentUser(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
    ], $overrides));
}

function postWebFaceEnrollment($test, User $actor, User $student, array $payload): Illuminate\Testing\TestResponse
{
    return $test->actingAs($actor)
        ->post('/admin/users/'.$student->id.'/face-enrollment', $payload, [
            'Accept' => 'application/json',
        ]);
}

test('staff coach can enroll a valid one-face image on the private disk', function () {
    $client = bindRekognitionFaceVerifier();
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'enroll.student@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->image('face.jpg'),
    ])
        ->assertOk()
        ->assertJsonPath('enrolled', true);

    $enrollment = FaceEnrollment::query()->where('user_id', $student->id)->first();
    expect($enrollment)->not->toBeNull()
        ->and($enrollment->disk)->toBe('face_enrollments')
        ->and($enrollment->enrolled_by)->toBe($coach->id)
        ->and($enrollment->path)->not->toContain('img/profile')
        ->and($client->detectCalls)->toBe(1);

    Storage::disk('face_enrollments')->assertExists($enrollment->path);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('staff admin can enroll a face', function () {
    bindRekognitionFaceVerifier();
    $admin = m8EnrollmentUser(['admin']);
    $student = m8EnrollmentUser(['student'], ['email' => 'enroll.admin@example.com']);

    postWebFaceEnrollment($this, $admin, $student, [
        'reference_photo' => UploadedFile::fake()->image('face.jpg'),
    ])->assertOk();
});

test('student cannot enroll a face', function () {
    bindRekognitionFaceVerifier();
    $student = m8EnrollmentUser(['student']);

    postWebFaceEnrollment($this, $student, $student, [
        'reference_photo' => UploadedFile::fake()->image('face.jpg'),
    ])->assertForbidden();

    expect(FaceEnrollment::query()->count())->toBe(0);
    expect(Storage::disk('face_enrollments')->allFiles())->toBeEmpty();
});

test('non-authorized staff cannot enroll a face', function () {
    bindRekognitionFaceVerifier();
    $student = m8EnrollmentUser(['student'], ['email' => 'target@example.com']);

    foreach ([['studio_responsable'], ['moderateur'], ['recruiter'], ['pro']] as $roles) {
        $staff = m8EnrollmentUser($roles, ['email' => $roles[0].'.staff@example.com']);

        postWebFaceEnrollment($this, $staff, $student, [
            'reference_photo' => UploadedFile::fake()->image('face.jpg'),
        ])->assertForbidden();
    }

    expect(FaceEnrollment::query()->count())->toBe(0);
});

test('zero-face enrollment is rejected and not stored', function () {
    $client = bindRekognitionFaceVerifier();
    $client->detectFaceCount = 0;
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'zero.face@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->image('face.jpg'),
    ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Enrollment photo must contain a face.']);

    expect(FaceEnrollment::query()->count())->toBe(0);
    expect(Storage::disk('face_enrollments')->allFiles())->toBeEmpty();
});

test('multi-face enrollment is rejected and not stored', function () {
    $client = bindRekognitionFaceVerifier();
    $client->detectFaceCount = 2;
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'multi.face@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->image('face.jpg'),
    ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Enrollment photo must contain exactly one face.']);

    expect(FaceEnrollment::query()->count())->toBe(0);
});

test('invalid enrollment file is rejected', function () {
    bindRekognitionFaceVerifier();
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'invalid.file@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reference_photo');

    expect(FaceEnrollment::query()->count())->toBe(0);
});

test('svg enrollment is rejected', function () {
    bindRekognitionFaceVerifier();
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'svg.file@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->create('face.svg', 20, 'image/svg+xml'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reference_photo');

    expect(FaceEnrollment::query()->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('replacing enrollment requires staff authorization and stays private', function () {
    bindRekognitionFaceVerifier();
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'replace.student@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->image('first.jpg'),
    ])->assertOk();

    $firstPath = FaceEnrollment::query()->where('user_id', $student->id)->value('path');

    $this->flushSession();
    auth()->logout();

    postWebFaceEnrollment($this, $student, $student, [
        'reference_photo' => UploadedFile::fake()->image('hijack.jpg'),
    ])->assertForbidden();

    expect(FaceEnrollment::query()->where('user_id', $student->id)->value('path'))->toBe($firstPath);
    Storage::disk('face_enrollments')->assertExists($firstPath);

    $this->flushSession();
    auth()->logout();

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->image('second.jpg'),
    ])->assertOk();

    $second = FaceEnrollment::query()->where('user_id', $student->id)->first();
    expect(FaceEnrollment::query()->where('user_id', $student->id)->count())->toBe(1)
        ->and($second->path)->not->toBe($firstPath)
        ->and($second->enrolled_by)->toBe($coach->id);

    Storage::disk('face_enrollments')->assertMissing($firstPath);
    Storage::disk('face_enrollments')->assertExists($second->path);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('enrollment is unavailable when Rekognition cannot be reached', function () {
    $client = bindRekognitionFaceVerifier();
    $client->throwOnDetect = true;
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'aws.down@example.com']);

    postWebFaceEnrollment($this, $coach, $student, [
        'reference_photo' => UploadedFile::fake()->image('face.jpg'),
    ])
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Unable to enroll a face right now.']);

    expect(FaceEnrollment::query()->count())->toBe(0);
});

test('mobile staff can enroll via the API', function () {
    bindRekognitionFaceVerifier();
    $coach = m8EnrollmentUser(['coach']);
    $student = m8EnrollmentUser(['student'], ['email' => 'api.enroll@example.com']);

    $this->actingAs($coach, 'sanctum')
        ->post('/api/mobile/users/'.$student->id.'/face-enrollment', [
            'reference_photo' => UploadedFile::fake()->image('face.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('enrolled', true);
});
