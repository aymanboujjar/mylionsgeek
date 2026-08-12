<?php

use App\Jobs\FinalizeSlotAttendance;
use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\DisciplineNotification;
use App\Models\Formation;
use App\Models\User;
use App\Services\ActiveFormationEnrollmentService;
use App\Services\AttendanceLegacyIdService;
use App\Services\AttendanceSlotService;
use App\Services\DisciplineService;
use App\Services\ExpoPushNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

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
        $table->string('expo_push_token')->nullable();
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
});

afterEach(function () {
    Carbon::setTestNow();
});

function runFinalizeJob(string $slot, ?string $date = null): void
{
    $date ??= Carbon::now()->toDateString();

    (new FinalizeSlotAttendance($slot, $date))->handle(
        app(AttendanceSlotService::class),
        app(ActiveFormationEnrollmentService::class),
        app(AttendanceLegacyIdService::class),
        app(DisciplineService::class),
    );
}

/**
 * Formation with exactly 20 working days → 60 slots.
 * One absent costs 100/60 ≈ 1.6667%; three absents sit exactly on the 95% band.
 */
function createDisciplineThresholdFormation(): Formation
{
    return Formation::create([
        'name' => 'Discipline Threshold Formation',
        'category' => 'coding',
        'is_active' => true,
        // Mon 2026-01-05 … Fri 2026-01-30 = 20 weekdays → 60 slots
        'start_time' => '2026-01-05',
        'end_time' => '2026-01-30',
    ]);
}

test('finalize creates a row with absent for closed slot and pending for future', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 11:00:00', config('app.timezone', 'UTC')));

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $student = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
    ]);

    runFinalizeJob('morning');

    $row = AttendanceListe::where('user_id', $student->id)->first();
    expect($row)->not->toBeNull();
    expect($row->morning)->toBe('absent');
    expect($row->lunch)->toBe('pending');
    expect($row->evening)->toBe('pending');
});

test('finalize updates pending closed slot to absent and skips already resolved', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 13:00:00', config('app.timezone', 'UTC')));

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $studentA = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'email' => 'a@example.com',
    ]);
    $studentB = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'email' => 'b@example.com',
    ]);

    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'Coach',
    ]);

    AttendanceListe::create([
        'user_id' => $studentA->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'present',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    AttendanceListe::create([
        'user_id' => $studentB->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'late',
        'lunch' => 'present',
        'evening' => 'pending',
    ]);

    runFinalizeJob('lunch');

    $rowA = AttendanceListe::where('user_id', $studentA->id)->first();
    expect($rowA->morning)->toBe('present');
    expect($rowA->lunch)->toBe('absent');
    expect($rowA->evening)->toBe('pending');

    $rowB = AttendanceListe::where('user_id', $studentB->id)->first();
    expect($rowB->morning)->toBe('late');
    expect($rowB->lunch)->toBe('present');
    expect($rowB->evening)->toBe('pending');
});

test('finalize is idempotent when run twice', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 11:00:00', config('app.timezone', 'UTC')));

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $student = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
    ]);

    runFinalizeJob('morning');
    runFinalizeJob('morning');

    expect(AttendanceListe::where('user_id', $student->id)->count())->toBe(1);
    expect(AttendanceListe::first()->morning)->toBe('absent');
});

test('finalize still runs when clock is past close minute (queue delay safe)', function () {
    // Slot was captured at 11:00; worker runs at 11:05 — must still finalize morning
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 11:05:00', config('app.timezone', 'UTC')));

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $student = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
    ]);

    runFinalizeJob('morning', Carbon::now()->toDateString());

    $row = AttendanceListe::where('user_id', $student->id)->first();
    expect($row)->not->toBeNull();
    expect($row->morning)->toBe('absent');
    expect($row->lunch)->toBe('pending');
    expect($row->evening)->toBe('pending');
});

test('evening finalize backfills pending morning when later slots already marked', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 17:05:00', config('app.timezone', 'UTC')));

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $student = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
    ]);

    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'System',
    ]);

    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'pending',
        'lunch' => 'present',
        'evening' => 'present',
    ]);

    runFinalizeJob('evening');

    $row = AttendanceListe::where('user_id', $student->id)->first();
    expect($row->morning)->toBe('absent');
    expect($row->lunch)->toBe('present');
    expect($row->evening)->toBe('present');
});

test('command does not dispatch before any slot has closed', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 09:00:00', config('app.timezone', 'UTC')));
    Bus::fake();

    $this->artisan('attendance:finalize-closed-slots')
        ->expectsOutputToContain('No attendance slots have closed yet')
        ->assertSuccessful();

    Bus::assertNotDispatched(FinalizeSlotAttendance::class);
    Bus::assertNotDispatchedSync(FinalizeSlotAttendance::class);
});

test('end-of-day command runs finalize synchronously for evening', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 17:05:00', config('app.timezone', 'UTC')));
    Bus::fake();

    $date = Carbon::now()->toDateString();

    $this->artisan('attendance:finalize-closed-slots')
        ->expectsOutputToContain("slot [evening] on [{$date}]")
        ->assertSuccessful();

    Bus::assertDispatchedSync(FinalizeSlotAttendance::class, function (FinalizeSlotAttendance $job) use ($date) {
        return $job->slot === 'evening' && $job->date === $date;
    });
});

test('command runs finalize synchronously via --slot override', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 09:00:00', config('app.timezone', 'UTC')));
    Bus::fake();

    $date = Carbon::now()->toDateString();

    $this->artisan('attendance:finalize-closed-slots', ['--slot' => 'morning', '--date' => $date])
        ->expectsOutputToContain("slot [morning] on [{$date}]")
        ->assertSuccessful();

    Bus::assertDispatchedSync(FinalizeSlotAttendance::class, function (FinalizeSlotAttendance $job) use ($date) {
        return $job->slot === 'morning' && $job->date === $date;
    });
});
test('artisan command finalizes pending slots inline without queueing', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 17:05:00', config('app.timezone', 'UTC')));

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $resolved = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'email' => 'resolved@example.com',
    ]);
    $pendingClosed = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'email' => 'pending@example.com',
    ]);

    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'Coach',
    ]);

    AttendanceListe::create([
        'user_id' => $resolved->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'present',
        'lunch' => 'present',
        'evening' => 'late',
    ]);

    AttendanceListe::create([
        'user_id' => $pendingClosed->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'pending',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    $this->artisan('attendance:finalize-closed-slots')->assertSuccessful();

    $resolvedRow = AttendanceListe::where('user_id', $resolved->id)->first();
    expect($resolvedRow->morning)->toBe('present');
    expect($resolvedRow->lunch)->toBe('present');
    expect($resolvedRow->evening)->toBe('late');

    $pendingRow = AttendanceListe::where('user_id', $pendingClosed->id)->first();
    expect($pendingRow->morning)->toBe('absent');
    expect($pendingRow->lunch)->toBe('absent');
    expect($pendingRow->evening)->toBe('absent');

    // Second run is a no-op (idempotent)
    $this->artisan('attendance:finalize-closed-slots')->assertSuccessful();
    expect(AttendanceListe::where('user_id', $pendingClosed->id)->count())->toBe(1);
    expect(AttendanceListe::where('user_id', $pendingClosed->id)->first()->evening)->toBe('absent');
});

test('one student failure does not stop finalizing others', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 11:00:00', config('app.timezone', 'UTC')));
    \Illuminate\Support\Facades\Log::spy();

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
        'start_time' => Carbon::now()->subMonth()->toDateString(),
        'end_time' => Carbon::now()->addMonths(5)->toDateString(),
    ]);

    $badStudent = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'email' => 'bad@example.com',
    ]);
    $goodStudent = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'email' => 'good@example.com',
    ]);

    $realDiscipline = new DisciplineService;
    $this->mock(DisciplineService::class, function ($mock) use ($realDiscipline, $badStudent) {
        $mock->shouldReceive('calculateDisciplineScore')
            ->andReturnUsing(function (User $user) use ($realDiscipline, $badStudent) {
                if ((int) $user->id === (int) $badStudent->id) {
                    throw new \RuntimeException('simulated student failure');
                }

                return $realDiscipline->calculateDisciplineScore($user);
            });
        $mock->shouldReceive('processDisciplineChange')
            ->andReturnUsing(fn (User $user, float $old) => $realDiscipline->processDisciplineChange($user, $old));
    });

    runFinalizeJob('morning');

    expect(AttendanceListe::where('user_id', $badStudent->id)->exists())->toBeFalse();

    $goodRow = AttendanceListe::where('user_id', $goodStudent->id)->first();
    expect($goodRow)->not->toBeNull();
    expect($goodRow->morning)->toBe('absent');
    expect($goodRow->lunch)->toBe('pending');
    expect($goodRow->evening)->toBe('pending');

    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
        ->withArgs(function ($message, $context = []) use ($badStudent) {
            return str_contains((string) $message, 'student finalize failed')
                && ($context['user_id'] ?? null) === $badStudent->id;
        })
        ->atLeast()
        ->once();
});

test('finalize crossing a 5% discipline threshold creates notification and sends push', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-04 11:00:00', config('app.timezone', 'UTC')));

    $formation = createDisciplineThresholdFormation();
    $discipline = app(DisciplineService::class);

    expect($discipline->getTotalSlots($formation))->toBe(60);

    $student = User::factory()->create([
        'name' => 'Threshold Student',
        'role' => ['student'],
        'formation_id' => $formation->id,
        'expo_push_token' => 'ExponentPushToken[discipline-test]',
    ]);

    // Three prior absents → lostScore 3 → 100 - (3/60*100) = 95.00 (on the 95% band)
    $prior = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => '2026-01-06',
        'staff_name' => 'Coach',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $prior->id,
        'attendance_day' => '2026-01-06',
        'morning' => 'absent',
        'lunch' => 'absent',
        'evening' => 'absent',
    ]);

    $today = Carbon::now()->toDateString();
    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => $today,
        'staff_name' => 'System',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => $today,
        'morning' => 'pending',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    $oldScore = $discipline->calculateDisciplineScore($student);
    expect($oldScore)->toBe(95.0);

    // Finalizing morning pending→absent adds 1 lost slot → 93.33, crosses below 95
    $expectedNewScore = round(100 - (4 / 60 * 100), 2);
    expect($expectedNewScore)->toBe(93.33);
    expect($expectedNewScore)->toBeLessThan(95.0);

    $pushMock = $this->mock(ExpoPushNotificationService::class, function (MockInterface $mock) use ($student, $expectedNewScore) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(function (User $user, string $title, string $body, array $data) use ($student, $expectedNewScore) {
                return (int) $user->id === (int) $student->id
                    && $title === 'Discipline Update'
                    && $body === "{$student->name} - discipline decreased 5%"
                    && ($data['type'] ?? null) === 'discipline_change'
                    && ($data['change_type'] ?? null) === 'decrease'
                    && (float) ($data['discipline_value'] ?? 0) === $expectedNewScore;
            })
            ->andReturn(true);
    });

    // Bind is enough — DisciplineService resolves push via app()
    expect(app(ExpoPushNotificationService::class))->toBe($pushMock);

    runFinalizeJob('morning', $today);

    expect(DisciplineNotification::where('user_id', $student->id)->count())->toBe(1);

    $notification = DisciplineNotification::where('user_id', $student->id)->first();
    expect($notification->message_notification)->toBe("{$student->name} - discipline decreased 5%");
    expect((float) $notification->discipline_change)->toBe($expectedNewScore);

    expect(AttendanceListe::where('user_id', $student->id)->where('attendance_day', $today)->first())
        ->morning->toBe('absent')
        ->lunch->toBe('pending')
        ->evening->toBe('pending');
});

test('finalize that does not cross a discipline threshold creates no notification', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-04 11:00:00', config('app.timezone', 'UTC')));

    $formation = createDisciplineThresholdFormation();
    $discipline = app(DisciplineService::class);

    $student = User::factory()->create([
        'name' => 'Safe Band Student',
        'role' => ['student'],
        'formation_id' => $formation->id,
        'expo_push_token' => 'ExponentPushToken[discipline-safe]',
    ]);

    // No prior absents — one finalized absent → 98.33, still above 95 → shouldNotify false
    $today = Carbon::now()->toDateString();
    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => $today,
        'staff_name' => 'System',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => $today,
        'morning' => 'pending',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    expect($discipline->calculateDisciplineScore($student))->toBe(100.0);

    $this->mock(ExpoPushNotificationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('sendToUser');
    });

    runFinalizeJob('morning', $today);

    $newScore = $discipline->calculateDisciplineScore($student->fresh());
    expect($newScore)->toBe(98.33);
    expect($newScore)->toBeGreaterThanOrEqual(95.0);

    expect(DisciplineNotification::where('user_id', $student->id)->count())->toBe(0);
    expect(AttendanceListe::where('user_id', $student->id)->first()->morning)->toBe('absent');
});
