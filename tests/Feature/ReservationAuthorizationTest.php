<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
});

function h4User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_studio' => 0,
        'access_cowork' => 0,
    ], $overrides));
}

function h4Staff(string $role): User
{
    return h4User([
        'role' => [$role],
        'name' => 'H4 Staff '.$role,
    ]);
}

function h4Studio(): int
{
    return (int) DB::table('studios')->insertGetId([
        'name' => 'H4 Studio',
        'state' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function h4StudioReservation(User $owner, ?int $studioId = null): int
{
    return (int) DB::table('reservations')->insertGetId([
        'title' => 'H4 Studio Reservation',
        'description' => 'Private studio booking',
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'passed' => 0,
        'approved' => 0,
        'user_id' => $owner->id,
        'studio_id' => $studioId ?? h4Studio(),
        'start_signed' => 0,
        'end_signed' => 0,
        'type' => 'studio',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function h4CoworkReservation(User $owner): int
{
    return (int) DB::table('reservation_coworks')->insertGetId([
        'table' => 7,
        'seats' => 1,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'passed' => 0,
        'approved' => 1,
        'user_id' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function h4MeetingRoom(): int
{
    return (int) DB::table('meeting_rooms')->insertGetId([
        'name' => 'H4 Meeting Room',
        'state' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function h4MeetingReservation(User $owner, ?int $roomId = null): int
{
    return (int) DB::table('reservation_meeting_rooms')->insertGetId([
        'meeting_room_id' => $roomId ?? h4MeetingRoom(),
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'passed' => 0,
        'approved' => 1,
        'user_id' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function h4Appointment(User $requester, User $person): int
{
    return (int) DB::table('appointments')->insertGetId([
        'user_id' => $requester->id,
        'person_name' => $person->name,
        'person_email' => $person->email,
        'day' => '2026-09-15',
        'start' => '09:00',
        'end' => '09:30',
        'status' => 'pending',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function h4AppointmentPerson(): User
{
    return h4User([
        'name' => 'Mahdi Bouziane',
        'email' => 'h4.mahdi.person@example.com',
        'role' => ['admin'],
    ]);
}

function h4StorePayload(int $studioId, int $forgedUserId): array
{
    return [
        'studio_id' => $studioId,
        'title' => 'Forged studio reservation',
        'description' => 'Should belong to the authenticated user',
        'day' => '2026-09-20',
        'start' => '14:00',
        'end' => '16:00',
        'user_id' => $forgedUserId,
    ];
}

test('student A cannot view student B studio reservation details', function () {
    $a = h4User(['name' => 'Student A']);
    $b = h4User(['name' => 'Student B']);
    $reservationId = h4StudioReservation($b);

    $this->actingAs($a)
        ->get('/students/reservations/'.$reservationId.'/details')
        ->assertForbidden();

    $this->actingAs($a)
        ->get('/admin/reservations/'.$reservationId.'/details')
        ->assertForbidden();
});

test('owner can view own studio reservation details', function () {
    $owner = h4User(['name' => 'Reservation Owner']);
    $reservationId = h4StudioReservation($owner);

    $this->actingAs($owner)
        ->get('/students/reservations/'.$reservationId.'/details')
        ->assertSuccessful();
});

test('reservation staff can view another user studio reservation', function (string $role) {
    $owner = h4User(['name' => 'Reservation Owner']);
    $staff = h4Staff($role);
    $reservationId = h4StudioReservation($owner);

    $this->actingAs($staff)
        ->get('/students/reservations/'.$reservationId.'/details')
        ->assertSuccessful();
})->with(['admin', 'super_admin', 'moderateur', 'studio_responsable', 'pro']);

test('coach cannot view another user studio reservation', function () {
    $owner = h4User();
    $coach = h4Staff('coach');
    $reservationId = h4StudioReservation($owner);

    $this->actingAs($coach)
        ->get('/students/reservations/'.$reservationId.'/details')
        ->assertForbidden();
});

test('student A cannot cancel student B cowork reservation', function () {
    $a = h4User();
    $b = h4User();
    $coworkId = h4CoworkReservation($b);

    $this->actingAs($a)
        ->post('/admin/reservations/cowork/'.$coworkId.'/cancel')
        ->assertForbidden();

    expect((int) DB::table('reservation_coworks')->where('id', $coworkId)->value('canceled'))->toBe(0);
});

test('owner can cancel own cowork reservation', function () {
    $owner = h4User();
    $coworkId = h4CoworkReservation($owner);

    $this->actingAs($owner)
        ->from('/students/reservations')
        ->post('/admin/reservations/cowork/'.$coworkId.'/cancel')
        ->assertRedirect();

    expect((int) DB::table('reservation_coworks')->where('id', $coworkId)->value('canceled'))->toBe(1);
});

test('reservation staff can cancel another user cowork reservation', function (string $role) {
    $owner = h4User();
    $staff = h4Staff($role);
    $coworkId = h4CoworkReservation($owner);

    $this->actingAs($staff)
        ->from('/admin/reservations')
        ->post('/admin/reservations/cowork/'.$coworkId.'/cancel')
        ->assertRedirect();

    expect((int) DB::table('reservation_coworks')->where('id', $coworkId)->value('canceled'))->toBe(1);
})->with(['admin', 'super_admin', 'moderateur', 'studio_responsable', 'pro']);

test('student A cannot cancel student B meeting room reservation', function () {
    $a = h4User();
    $b = h4User();
    $meetingId = h4MeetingReservation($b);

    $this->actingAs($a)
        ->post('/admin/reservations/meeting-room/'.$meetingId.'/cancel')
        ->assertForbidden();

    expect((int) DB::table('reservation_meeting_rooms')->where('id', $meetingId)->value('canceled'))->toBe(0);
});

test('owner can cancel own meeting room reservation', function () {
    $owner = h4User();
    $meetingId = h4MeetingReservation($owner);

    $this->actingAs($owner)
        ->from('/students/reservations')
        ->post('/admin/reservations/meeting-room/'.$meetingId.'/cancel')
        ->assertRedirect();

    expect((int) DB::table('reservation_meeting_rooms')->where('id', $meetingId)->value('canceled'))->toBe(1);
});

test('reservation staff can cancel another user meeting room reservation', function (string $role) {
    $owner = h4User();
    $staff = h4Staff($role);
    $meetingId = h4MeetingReservation($owner);

    $this->actingAs($staff)
        ->from('/admin/reservations')
        ->post('/admin/reservations/meeting-room/'.$meetingId.'/cancel')
        ->assertRedirect();

    expect((int) DB::table('reservation_meeting_rooms')->where('id', $meetingId)->value('canceled'))->toBe(1);
})->with(['admin', 'super_admin', 'moderateur', 'studio_responsable', 'pro']);

test('sanctum user A cannot create a studio reservation as user B', function () {
    $a = h4User(['access_studio' => 1, 'name' => 'Actor A']);
    $b = h4User(['access_studio' => 1, 'name' => 'Target B']);
    $studioId = h4Studio();

    $this->actingAs($a, 'sanctum')
        ->postJson('/api/reservations/store', h4StorePayload($studioId, (int) $b->id))
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    expect(DB::table('reservations')->where('user_id', $b->id)->exists())->toBeFalse();
    expect((int) DB::table('reservations')->where('user_id', $a->id)->count())->toBe(1);
});

test('access_studio is checked against the authenticated user not submitted user_id', function () {
    $a = h4User(['access_studio' => 0]);
    $b = h4User(['access_studio' => 1]);
    $studioId = h4Studio();

    $this->actingAs($a, 'sanctum')
        ->postJson('/api/reservations/store', h4StorePayload($studioId, (int) $b->id))
        ->assertForbidden();

    expect(DB::table('reservations')->count())->toBe(0);
});

test('unrelated verified user cannot approve another user appointment', function () {
    $requester = h4User();
    $person = h4AppointmentPerson();
    $stranger = h4User();
    $appointmentId = h4Appointment($requester, $person);

    $this->actingAs($stranger)
        ->post('/admin/appointments/'.$appointmentId.'/approve')
        ->assertForbidden();

    expect(DB::table('appointments')->where('id', $appointmentId)->value('status'))->toBe('pending');
});

test('unrelated verified user cannot cancel another user appointment', function () {
    $requester = h4User();
    $person = h4AppointmentPerson();
    $stranger = h4User();
    $appointmentId = h4Appointment($requester, $person);

    $this->actingAs($stranger)
        ->post('/admin/appointments/'.$appointmentId.'/cancel')
        ->assertForbidden();

    expect(DB::table('appointments')->where('id', $appointmentId)->value('status'))->toBe('pending');
});

test('unrelated verified user cannot suggest time for another user appointment', function () {
    $requester = h4User();
    $person = h4AppointmentPerson();
    $stranger = h4User();
    $appointmentId = h4Appointment($requester, $person);

    $this->actingAs($stranger)
        ->post('/admin/appointments/'.$appointmentId.'/suggest-time', [
            'suggested_day' => '2026-09-16',
            'suggested_start' => '11:00',
            'suggested_end' => '11:30',
        ])
        ->assertForbidden();

    expect(DB::table('appointments')->where('id', $appointmentId)->value('status'))->toBe('pending');
});

test('authorized appointment person can approve cancel and suggest', function () {
    $requester = h4User();
    $person = h4AppointmentPerson();

    $approveId = h4Appointment($requester, $person);
    $this->actingAs($person)
        ->from('/admin/appointments')
        ->post('/admin/appointments/'.$approveId.'/approve')
        ->assertRedirect();
    expect(DB::table('appointments')->where('id', $approveId)->value('status'))->toBe('approved');

    $cancelId = h4Appointment($requester, $person);
    $this->actingAs($person)
        ->from('/admin/appointments')
        ->post('/admin/appointments/'.$cancelId.'/cancel')
        ->assertRedirect();
    expect(DB::table('appointments')->where('id', $cancelId)->value('status'))->toBe('canceled');

    $suggestId = h4Appointment($requester, $person);
    $this->actingAs($person)
        ->from('/admin/appointments')
        ->post('/admin/appointments/'.$suggestId.'/suggest-time', [
            'suggested_day' => '2026-09-16',
            'suggested_start' => '11:00',
            'suggested_end' => '11:30',
        ])
        ->assertRedirect();
    expect(DB::table('appointments')->where('id', $suggestId)->value('status'))->toBe('suggested');
});

test('public occupancy endpoint does not expose private reservation identity', function () {
    $owner = h4User([
        'name' => 'H4PrivateOwnerName',
        'email' => 'h4.private.owner@example.com',
        'phone' => '0611223344',
    ]);
    $studioId = h4Studio();
    $reservationId = h4StudioReservation($owner, $studioId);

    $response = $this->getJson('/reservations/public-place/studio/'.$studioId)
        ->assertOk();

    $payload = $response->json();
    expect($payload)->not->toBeEmpty();

    $encoded = json_encode($payload);
    expect($encoded)->not->toContain($owner->name)
        ->and($encoded)->not->toContain($owner->email)
        ->and($encoded)->not->toContain($owner->phone)
        ->and($encoded)->not->toContain('user_id')
        ->and($encoded)->not->toContain('user_name');

    foreach ($payload as $slot) {
        expect($slot)->toHaveKeys(['start', 'end'])
            ->and($slot)->not->toHaveKeys(['id', 'user_id', 'user_name', 'email', 'phone', 'title']);
    }
});

test('anonymous access to protected reservation api store returns 401', function () {
    $this->postJson('/api/reservations/store', [
        'studio_id' => 1,
        'title' => 'Anonymous attempt',
        'day' => '2026-09-20',
        'start' => '14:00',
        'end' => '16:00',
        'user_id' => 1,
    ])->assertUnauthorized();
});

test('coach cannot view another user studio reservation through the mobile api', function () {
    $owner = h4User([
        'name' => 'H4 Api Owner',
        'email' => 'h4.api.owner@example.com',
        'phone' => '0699887766',
    ]);
    $coach = h4Staff('coach');
    $reservationId = h4StudioReservation($owner);

    $response = $this->actingAs($coach, 'sanctum')
        ->getJson('/api/mobile/reservations/'.$reservationId)
        ->assertForbidden();

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain($owner->email)
        ->and($encoded)->not->toContain($owner->phone)
        ->and($encoded)->not->toContain($owner->name);
});

test('coach cannot view another user studio reservation through the duplicate api route', function () {
    $owner = h4User([
        'name' => 'H4 Duplicate Owner',
        'email' => 'h4.duplicate.owner@example.com',
        'phone' => '0612349999',
    ]);
    $coach = h4Staff('coach');
    $reservationId = h4StudioReservation($owner);

    $response = $this->actingAs($coach, 'sanctum')
        ->getJson('/api/reservations/'.$reservationId)
        ->assertForbidden();

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain($owner->email)
        ->and($encoded)->not->toContain($owner->phone)
        ->and($encoded)->not->toContain($owner->name);
});

test('owner can view own studio reservation through the mobile api', function () {
    $owner = h4User([
        'name' => 'H4 Api Self Owner',
        'email' => 'h4.api.self@example.com',
        'phone' => '0600112233',
    ]);
    $reservationId = h4StudioReservation($owner);

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/mobile/reservations/'.$reservationId)
        ->assertOk()
        ->assertJsonPath('reservation.id', $reservationId)
        ->assertJsonPath('reservation.user_email', $owner->email)
        ->assertJsonPath('reservation.user_phone', $owner->phone);
});

test('student A cannot view student B studio reservation through the mobile api', function () {
    $a = h4User(['email' => 'h4.api.student.a@example.com']);
    $b = h4User([
        'name' => 'H4 Api Student B',
        'email' => 'h4.api.student.b@example.com',
        'phone' => '0677001122',
    ]);
    $reservationId = h4StudioReservation($b);

    $response = $this->actingAs($a, 'sanctum')
        ->getJson('/api/mobile/reservations/'.$reservationId)
        ->assertForbidden();

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain($b->email)
        ->and($encoded)->not->toContain($b->phone);
});

test('reservation staff can view another user studio reservation through the mobile api', function (string $role) {
    $owner = h4User([
        'email' => 'h4.api.staff.owner.'.$role.'@example.com',
        'phone' => '0688000000',
    ]);
    $staff = h4Staff($role);
    $reservationId = h4StudioReservation($owner);

    $this->actingAs($staff, 'sanctum')
        ->getJson('/api/mobile/reservations/'.$reservationId)
        ->assertOk()
        ->assertJsonPath('reservation.id', $reservationId)
        ->assertJsonPath('reservation.user_email', $owner->email);
})->with(['admin', 'super_admin', 'moderateur', 'studio_responsable', 'pro']);

test('unauthenticated access to reservation detail api returns 401', function () {
    $owner = h4User();
    $reservationId = h4StudioReservation($owner);

    $this->getJson('/api/mobile/reservations/'.$reservationId)
        ->assertUnauthorized();

    $this->getJson('/api/reservations/'.$reservationId)
        ->assertUnauthorized();
});
