<?php

use App\Jobs\SendSlotAttendanceReminders;
use App\Models\AttendanceReminderNotification;
use App\Models\Formation;
use App\Models\User;
use App\Services\ActiveFormationEnrollmentService;
use App\Services\AttendanceCheckInService;
use App\Services\AttendanceSlotService;
use App\Services\ExpoPushNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
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

    Schema::create('attendance_reminder_notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('slot');
        $table->string('message_notification')->nullable();
        $table->string('path')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();

        $table->unique(['user_id', 'date', 'slot']);
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

function freezeReminderTime(string $time): void
{
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' '.$time, config('app.timezone', 'UTC')));
}

function runReminderJob(MockInterface $pushMock, string $slot = 'morning', ?string $date = null): void
{
    $date ??= Carbon::now()->toDateString();

    (new SendSlotAttendanceReminders($slot, $date))->handle(
        app(AttendanceSlotService::class),
        app(AttendanceCheckInService::class),
        $pushMock,
        app(ActiveFormationEnrollmentService::class),
    );
}

test('job queries is_active and sends reminder for students in active formations', function () {
    freezeReminderTime('09:42:00');

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
    ]);

    $student = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'expo_push_token' => 'ExponentPushToken[test-token]',
    ]);

    $pushMock = $this->mock(ExpoPushNotificationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('send')
            ->once()
            ->with(
                ['ExponentPushToken[test-token]'],
                'Attendance Reminder',
                'Check in for Morning',
                ['type' => 'attendance_reminder', 'slot' => 'morning'],
            )
            ->andReturn(true);
    });

    runReminderJob($pushMock);

    expect(AttendanceReminderNotification::count())->toBe(1);
    expect(AttendanceReminderNotification::first())
        ->user_id->toBe($student->id)
        ->slot->toBe('morning')
        ->message_notification->toBe('Check in for Morning');
});

test('job skips students when formation is not active', function () {
    freezeReminderTime('09:42:00');

    $formation = Formation::create([
        'name' => 'Inactive Formation',
        'category' => 'coding',
        'is_active' => false,
    ]);

    User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'expo_push_token' => 'ExponentPushToken[test-token]',
    ]);

    $pushMock = $this->mock(ExpoPushNotificationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('send');
    });

    runReminderJob($pushMock);

    expect(AttendanceReminderNotification::count())->toBe(0);
});

test('job still sends when clock is outside slot window (queue delay safe)', function () {
    // Slot was captured during morning; worker runs in the 11:05 gap — must still remind
    freezeReminderTime('11:05:00');

    $formation = Formation::create([
        'name' => 'Active Formation',
        'category' => 'coding',
        'is_active' => true,
    ]);

    $student = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
        'expo_push_token' => 'ExponentPushToken[test-token]',
    ]);

    $pushMock = $this->mock(ExpoPushNotificationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('send')
            ->once()
            ->andReturn(true);
    });

    runReminderJob($pushMock, 'morning');

    expect(AttendanceReminderNotification::count())->toBe(1);
    expect(AttendanceReminderNotification::first())
        ->user_id->toBe($student->id)
        ->slot->toBe('morning');
});
