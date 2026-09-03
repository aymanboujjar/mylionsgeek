<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

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
        $table->string('name');
        $table->string('img')->default('default_training.jpg');
        $table->string('category')->nullable();
        $table->string('start_time')->nullable();
        $table->string('end_time')->nullable();
        $table->integer('user_id')->nullable();
        $table->string('promo')->nullable();
        $table->boolean('is_active')->default(false);
        $table->timestamps();
    });

    Schema::create('attendances', function (Blueprint $table) {
        $table->id();
        $table->integer('formation_id');
        $table->string('attendance_day');
        $table->string('staff_name');
        $table->timestamps();
    });

    Schema::create('attendance_lists', function (Blueprint $table) {
        $table->id();
        $table->integer('user_id');
        $table->integer('attendance_id');
        $table->string('attendance_day');
        $table->string('morning')->nullable();
        $table->string('lunch')->nullable();
        $table->string('evening')->nullable();
        $table->timestamps();
    });

    Schema::create('discipline_notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('message_notification')->nullable();
        $table->decimal('discipline_change', 5, 2)->nullable();
        $table->string('path')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
    });

    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('attendance_id')->nullable();
        $table->string('note');
        $table->string('author')->nullable();
        $table->timestamps();
    });

    Schema::create('user_social_links', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('title');
        $table->string('url');
        $table->timestamps();
    });

    Schema::create('reservations', function (Blueprint $table) {
        $table->id();
        $table->boolean('approved')->default(false);
        $table->boolean('canceled')->default(false);
        $table->boolean('passed')->default(false);
    });

    Schema::create('reservation_coworks', function (Blueprint $table) {
        $table->id();
        $table->boolean('approved')->default(false);
        $table->boolean('canceled')->default(false);
        $table->boolean('passed')->default(false);
    });

    Schema::create('organizations', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('account_user_id')->nullable();
    });

    $this->formation = Formation::create([
        'name' => 'Test Formation',
        'category' => 'coding',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $this->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\EnsureOrganisationOnboarded::class,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function createWebStudent(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'formation_id' => test()->formation->id,
        'email_verified_at' => now(),
    ], $attributes));
}

function freezeWebTime(string $time): void
{
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' '.$time, 'Africa/Casablanca'));
}

function getWebAttendancePage(TestCase $test, User $actor, string $remoteAddr): TestResponse
{
    return $test->actingAs($actor)
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->get('/students/attendance');
}

function postWebCheckIn(TestCase $test, User $actor, string $remoteAddr, array $overrides = [], bool $withLivePhoto = true): TestResponse
{
    $payload = array_merge([
        'formation_id' => $test->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
    ], $overrides);

    if ($withLivePhoto && ! array_key_exists('live_photo', $payload)) {
        $payload['live_photo'] = m8LivePhoto();
    }

    return $test->actingAs($actor)
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->post('/students/attendance/check-in', $payload, [
            'Accept' => 'application/json',
        ]);
}

function postMobileCheckIn(TestCase $test, User $actor, string $remoteAddr, array $overrides = [], bool $withLivePhoto = true): TestResponse
{
    $payload = array_merge([
        'formation_id' => $test->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
    ], $overrides);

    if ($withLivePhoto && ! array_key_exists('live_photo', $payload)) {
        $payload['live_photo'] = m8LivePhoto();
    }

    return $test->actingAs($actor, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->post('/api/mobile/attendance/check-in', $payload, [
            'Accept' => 'application/json',
        ]);
}

test('student off-network is blocked from web attendance page', function () {
    $student = createWebStudent();

    getWebAttendancePage($this, $student, '198.51.100.99')
        ->assertForbidden()
        ->assertJson([
            'message' => 'You must be connected to the school WiFi to check in.',
        ]);
});

test('student on allowed IP can load web attendance page', function () {
    freezeWebTime('09:42:00');
    $student = createWebStudent();

    $this->withoutVite();

    getWebAttendancePage($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('students/attendance/index')
            ->has('formation')
            ->has('slot_status')
        );
});

test('staff bypasses network restriction on web check-in', function () {
    freezeWebTime('09:42:00');
    bindVerifiedFaceVerifier();
    $studentCoach = createWebStudent(['role' => ['student', 'coach']]);

    postWebCheckIn($this, $studentCoach, '198.51.100.99')->assertOk();
});

test('empty whitelist returns 503 on web attendance page', function () {
    config(['attendance.allowed_ips' => []]);
    $student = createWebStudent();

    getWebAttendancePage($this, $student, '203.0.113.1')
        ->assertStatus(503)
        ->assertJson(['message' => 'Attendance network is not configured.']);
});

test('web network-check returns ok for student on allowed IP', function () {
    $student = createWebStudent();

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/students/attendance/network-check')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('web network-check returns forbidden for student off-network', function () {
    $student = createWebStudent();

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->getJson('/students/attendance/network-check')
        ->assertForbidden()
        ->assertJson([
            'message' => 'You must be connected to the school WiFi to check in.',
        ]);
});

test('web network-check returns 503 when whitelist is empty', function () {
    config(['attendance.allowed_ips' => []]);
    $student = createWebStudent();

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/students/attendance/network-check')
        ->assertStatus(503)
        ->assertJson(['message' => 'Attendance network is not configured.']);
});

test('web check-in saves present during the present window', function () {
    freezeWebTime('09:42:00');
    bindVerifiedFaceVerifier();
    $student = createWebStudent();
    $countBefore = AttendanceListe::count();

    postWebCheckIn($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertJson([
            'slot' => 'morning',
            'status' => 'present',
            'row' => [
                'morning' => 'present',
                'lunch' => 'pending',
                'evening' => 'pending',
            ],
        ]);

    expect(AttendanceListe::count())->toBe($countBefore + 1);
});

test('web check-in saves late after the present window', function () {
    freezeWebTime('09:46:00');
    bindVerifiedFaceVerifier();
    $student = createWebStudent();

    postWebCheckIn($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertJsonPath('status', 'late');
});

test('web check-in without live_photo is rejected and does not write attendance', function () {
    freezeWebTime('09:42:00');
    $student = createWebStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postWebCheckIn($this, $student, '203.0.113.1', withLivePhoto: false)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('live_photo');

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore);
});

test('web check-in with a jpeg but no face verification does not write attendance', function () {
    freezeWebTime('09:42:00');
    $student = createWebStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postWebCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(503)
        ->assertJson(['message' => 'Unable to verify your identity.']);

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore);
});

test('web check-in during a gap returns 422 with no database writes', function () {
    freezeWebTime('11:05:00');
    $student = createWebStudent();
    $countBefore = AttendanceListe::count();

    postWebCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(422)
        ->assertJson(['message' => 'No attendance to mark right now.']);

    expect(AttendanceListe::count())->toBe($countBefore);
});

test('web check-in for an already marked slot returns 409 with no database writes', function () {
    freezeWebTime('09:40:00');
    bindVerifiedFaceVerifier();
    $student = createWebStudent();
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'Test',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'present',
    ]);

    $countBefore = AttendanceListe::count();

    postWebCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(409)
        ->assertJson(['message' => "You've already marked attendance for this slot."]);

    expect(AttendanceListe::count())->toBe($countBefore);
});

test('web and mobile check-in produce identical grading via shared service', function () {
    freezeWebTime('09:42:00');
    bindVerifiedFaceVerifier();

    $webStudent = createWebStudent(['email' => 'web-student@example.com']);
    $mobileStudent = createWebStudent(['email' => 'mobile-student@example.com']);

    $webResponse = postWebCheckIn($this, $webStudent, '203.0.113.1')->assertOk()->json();
    $mobileResponse = postMobileCheckIn($this, $mobileStudent, '203.0.113.1')->assertOk()->json();

    expect($webResponse['slot'])->toBe($mobileResponse['slot']);
    expect($webResponse['status'])->toBe($mobileResponse['status']);
    expect($webResponse['row']['morning'])->toBe($mobileResponse['row']['morning']);
    expect($webResponse['row']['lunch'])->toBe($mobileResponse['row']['lunch']);
    expect($webResponse['row']['evening'])->toBe($mobileResponse['row']['evening']);
});

test('web slot-status endpoint returns server-computed state', function () {
    freezeWebTime('11:05:00');
    $student = createWebStudent();

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/students/attendance/slot-status?'.http_build_query([
            'formation_id' => $this->formation->id,
            'attendance_day' => Carbon::now()->toDateString(),
        ]))
        ->assertOk()
        ->assertJson([
            'current_slot' => null,
            'phase' => 'gap',
            'present_minutes' => 15,
            'next_slot' => [
                'slot' => 'lunch',
                'opens_at' => '11:30',
            ],
        ]);
});

test('student off-network can read slot-status for home reminder banner', function () {
    freezeWebTime('09:42:00');
    $student = createWebStudent();

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->getJson('/students/attendance/slot-status?'.http_build_query([
            'formation_id' => $this->formation->id,
        ]))
        ->assertOk()
        ->assertJson([
            'current_slot' => 'morning',
            'phase' => 'active',
            'minutes_into_slot' => 12,
            'present_minutes' => 15,
        ]);
});

test('student off-network can read home-slot-status for reminder banner', function () {
    freezeWebTime('09:42:00');
    $student = createWebStudent();

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->getJson('/students/attendance/home-slot-status')
        ->assertOk()
        ->assertJson([
            'formation' => [
                'id' => $this->formation->id,
                'name' => 'Test Formation',
            ],
            'slot_status' => [
                'current_slot' => 'morning',
                'phase' => 'active',
                'minutes_into_slot' => 12,
                'present_minutes' => 15,
                'already_marked_slots' => [],
            ],
        ]);
});

test('home-slot-status returns null payload when student has no formation', function () {
    freezeWebTime('09:42:00');
    $student = createWebStudent(['formation_id' => null]);

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->getJson('/students/attendance/home-slot-status')
        ->assertOk()
        ->assertJson([
            'formation' => null,
            'slot_status' => null,
        ]);
});
