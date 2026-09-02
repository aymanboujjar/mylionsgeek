<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const EVENTS_UPSTREAM_KEY = 'test-lionsgeek-events-upstream-key';
const EVENTS_UPSTREAM = 'https://lionsgeek.test';

beforeEach(function () {
    Config::set('services.lionsgeek.url', EVENTS_UPSTREAM);
    Config::set('services.lionsgeek.key', EVENTS_UPSTREAM_KEY);
    Config::set('services.lionsgeek.verify', true);
    Http::preventStrayRequests();
});

function eventsNormalUser(): User
{
    return User::factory()->create([
        'role' => ['student'],
        'access_scan' => 0,
        'email_verified_at' => now(),
    ]);
}

function eventsAdminUser(): User
{
    return User::factory()->create([
        'role' => ['admin'],
        'access_scan' => 0,
        'email_verified_at' => now(),
    ]);
}

function eventsScanUser(): User
{
    return User::factory()->create([
        'role' => ['student'],
        'access_scan' => 1,
        'email_verified_at' => now(),
    ]);
}

function publicEvent(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'name' => ['en' => 'Public Event'],
        'is_private' => false,
        'private_url_token' => 'secret-public-token',
        'capacity' => 10,
        'cover' => 'cover.jpg',
    ], $overrides);
}

function privateEvent(array $overrides = []): array
{
    return array_merge([
        'id' => 2,
        'name' => ['en' => 'Private Event'],
        'is_private' => true,
        'private_url_token' => 'secret-private-token',
        'capacity' => 5,
        'cover' => 'private.jpg',
    ], $overrides);
}

function bookingRow(): array
{
    return [
        'id' => 9,
        'name' => 'Visitor One',
        'email' => 'visitor@example.com',
        'phone' => '0611111111',
        'gender' => 'male',
        'is_visited' => false,
        'event_id' => 1,
        'form_data' => ['name' => 'Visitor One'],
        'secret_note' => 'should-not-leak',
    ];
}

function participantRow(): array
{
    return [
        'id' => 4,
        'full_name' => 'Candidate One',
        'email' => 'candidate@example.com',
        'phone' => '0622222222',
        'code' => 'ABC123',
        'is_visited' => false,
        'image' => 'face.jpg',
        'revenus_mensuels' => 'hidden-income',
        'children_form_data' => ['guardian' => 'hidden'],
        'social_score' => 99,
    ];
}

test('anonymous cannot access events-info endpoints', function () {
    $this->getJson('/api/events-info/events')->assertUnauthorized();
    $this->getJson('/api/events-info/events/1')->assertUnauthorized();
    $this->postJson('/api/events-info/booking/store', ['event_id' => 1])->assertUnauthorized();
    $this->getJson('/api/events-info/session-data?id=1')->assertUnauthorized();
    $this->getJson('/api/events-info/profile-data?id=1')->assertUnauthorized();
    $this->putJson('/api/events-info/validate-invitation', [
        'email' => 'a@example.com',
        'code' => 'x',
        'sessionId' => 1,
    ])->assertUnauthorized();
    $this->putJson('/api/events-info/manual-checking', ['id' => 1])->assertUnauthorized();
    $this->putJson('/api/events-info/validate-event-invitation', [
        'email' => 'a@example.com',
        'code' => 1,
        'id' => 1,
    ])->assertUnauthorized();
    $this->putJson('/api/events-info/manual-event-checking', ['id' => 1, 'event_id' => 1])->assertUnauthorized();
    $this->postJson('/api/events-info/session-photo', ['id' => 1])->assertUnauthorized();
    $this->getJson('/api/events-info/images/events/cover.jpg')->assertUnauthorized();
    $this->getJson('/api/events-info/images/participants/face.jpg')->assertUnauthorized();
    Http::assertNothingSent();
});

test('expo public key is not accepted as authentication', function () {
    $this->withToken('expo-public-events-info-key')
        ->getJson('/api/events-info/events')
        ->assertUnauthorized();
    Http::assertNothingSent();
});

test('authenticated normal user sees only public events without private tokens', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events' => Http::response([publicEvent(), privateEvent()], 200),
    ]);

    $response = $this->actingAs(eventsNormalUser(), 'sanctum')
        ->getJson('/api/events-info/events')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['private_url_token' => 'secret-public-token'])
        ->assertJsonMissing(['private_url_token' => 'secret-private-token']);

    expect($response->json('0.id'))->toBe(1)
        ->and($response->json('0.is_private'))->toBeFalse()
        ->and(json_encode($response->json()))->not->toContain(EVENTS_UPSTREAM_KEY);
});

test('normal user cannot view a private event by id', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events/2' => Http::response([
            'event' => privateEvent(),
            'participants' => [bookingRow()],
        ], 200),
    ]);

    $this->actingAs(eventsNormalUser(), 'sanctum')
        ->getJson('/api/events-info/events/2')
        ->assertForbidden();
});

test('normal user event detail has no attendee PII', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events/1' => Http::response([
            'event' => publicEvent(),
            'participants' => [bookingRow()],
        ], 200),
    ]);

    $response = $this->actingAs(eventsNormalUser(), 'sanctum')
        ->getJson('/api/events-info/events/1')
        ->assertOk();

    expect($response->json('participants'))->toBe([])
        ->and($response->json('event.private_url_token'))->toBeNull()
        ->and(json_encode($response->json()))->not->toContain('visitor@example.com')
        ->and(json_encode($response->json()))->not->toContain('0611111111')
        ->and(json_encode($response->json()))->not->toContain(EVENTS_UPSTREAM_KEY);
});

test('normal user cannot access staff endpoints or dump participant PII', function () {
    $user = eventsNormalUser();

    $this->actingAs($user, 'sanctum')->getJson('/api/events-info/session-data?id=1')->assertForbidden();
    $this->actingAs($user, 'sanctum')->getJson('/api/events-info/profile-data?id=1')->assertForbidden();
    $this->actingAs($user, 'sanctum')->getJson('/api/events-info/profile-data?id=2')->assertForbidden();
    $this->actingAs($user, 'sanctum')->getJson('/api/events-info/profile-data?id=3')->assertForbidden();
    $this->actingAs($user, 'sanctum')->getJson('/api/events-info/lionsgate/infosessions')->assertForbidden();
    $this->actingAs($user, 'sanctum')->putJson('/api/events-info/manual-checking', ['id' => 1])->assertForbidden();
    $this->actingAs($user, 'sanctum')->putJson('/api/events-info/validate-invitation', [
        'email' => 'a@example.com',
        'code' => 'x',
        'sessionId' => 1,
    ])->assertForbidden();
    $this->actingAs($user, 'sanctum')->putJson('/api/events-info/validate-event-invitation', [
        'email' => 'a@example.com',
        'code' => 1,
        'id' => 1,
    ])->assertForbidden();
    $this->actingAs($user, 'sanctum')->putJson('/api/events-info/manual-event-checking', [
        'id' => 1,
        'event_id' => 1,
    ])->assertForbidden();
    $this->actingAs($user, 'sanctum')->postJson('/api/events-info/session-photo', ['id' => 1])->assertForbidden();
    $this->actingAs($user, 'sanctum')->get('/api/events-info/images/participants/face.jpg')->assertForbidden();
    Http::assertNothingSent();
});

test('client supplied role or access_scan in the body does not grant staff access', function () {
    $this->actingAs(eventsNormalUser(), 'sanctum')
        ->putJson('/api/events-info/manual-checking', [
            'id' => 1,
            'role' => 'admin',
            'access_scan' => 1,
        ])
        ->assertForbidden();
    Http::assertNothingSent();
});

test('normal user can book a public event and admin_override is stripped', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events/1' => Http::response([
            'event' => publicEvent(),
            'participants' => [],
        ], 200),
        EVENTS_UPSTREAM.'/api/booking/store' => Http::response([
            'success' => true,
            'booking' => ['id' => 11, 'event_id' => 1, 'email' => 'student@example.com'],
        ], 200),
    ]);

    $this->actingAs(eventsNormalUser(), 'sanctum')
        ->postJson('/api/events-info/booking/store', [
            'event_id' => 1,
            'answers' => ['name' => 'Student', 'email' => 'student@example.com'],
            'admin_override' => true,
            'role' => 'admin',
            'access_scan' => 1,
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/api/booking/store')) {
            return false;
        }

        $data = $request->data();

        return $request->hasHeader('Authorization', 'Bearer '.EVENTS_UPSTREAM_KEY)
            && ($data['event_id'] ?? null) === 1
            && empty($data['admin_override'])
            && ! array_key_exists('role', $data)
            && ! array_key_exists('access_scan', $data);
    });
});

test('normal user cannot book a private event', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events/2' => Http::response([
            'event' => privateEvent(),
            'participants' => [],
        ], 200),
    ]);

    $this->actingAs(eventsNormalUser(), 'sanctum')
        ->postJson('/api/events-info/booking/store', [
            'event_id' => 2,
            'answers' => ['name' => 'Student', 'email' => 'student@example.com'],
        ])
        ->assertForbidden();

    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/booking/store'));
});

test('admin can access staff endpoints including private events and check-in', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events' => Http::response([publicEvent(), privateEvent()], 200),
        EVENTS_UPSTREAM.'/api/events/2' => Http::response([
            'event' => privateEvent(),
            'participants' => [bookingRow()],
        ], 200),
        EVENTS_UPSTREAM.'/api/lionsgate/infosessions' => Http::response([
            'infos' => [['id' => 3, 'name' => 'Session', 'private_url_token' => 'session-secret']],
        ], 200),
        EVENTS_UPSTREAM.'/api/manual-event-checking' => Http::response([
            'message' => 'manual visite',
            'profile' => bookingRow(),
        ], 200),
    ]);

    $admin = eventsAdminUser();

    $list = $this->actingAs($admin, 'sanctum')->getJson('/api/events-info/events')->assertOk();
    expect(collect($list->json())->pluck('id')->all())->toEqualCanonicalizing([1, 2]);

    $detail = $this->actingAs($admin, 'sanctum')->getJson('/api/events-info/events/2')->assertOk();
    expect($detail->json('participants.0.email'))->toBe('visitor@example.com')
        ->and($detail->json('participants.0.secret_note'))->toBeNull()
        ->and($detail->json('event.private_url_token'))->toBeNull();

    $sessions = $this->actingAs($admin, 'sanctum')->getJson('/api/events-info/lionsgate/infosessions')->assertOk();
    expect($sessions->json('infos.0.private_url_token'))->toBeNull();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/events-info/manual-event-checking', ['id' => 9, 'event_id' => 2])
        ->assertOk()
        ->assertJsonPath('profile.email', 'visitor@example.com')
        ->assertJsonMissing(['secret_note' => 'should-not-leak']);
});

test('access_scan user can access staff data and check-in', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/session-data*' => Http::response([
            'session' => ['id' => 3, 'name' => 'Coding', 'private_url_token' => 'sess-token'],
            'participants' => [participantRow()],
            'attended' => [],
            'unattended' => [participantRow()],
        ], 200),
        EVENTS_UPSTREAM.'/api/profile-data*' => Http::response(participantRow(), 200),
        EVENTS_UPSTREAM.'/api/validate-invitation' => Http::response([
            'message' => 'Credentials match.',
            'profile' => participantRow(),
        ], 200),
        EVENTS_UPSTREAM.'/api/manual-checking' => Http::response([
            'message' => 'manual visite',
            'profile' => participantRow(),
        ], 200),
    ]);

    $staff = eventsScanUser();

    $session = $this->actingAs($staff, 'sanctum')
        ->getJson('/api/events-info/session-data?id=3&extra=1')
        ->assertOk();

    expect($session->json('session.private_url_token'))->toBeNull()
        ->and($session->json('participants.0.email'))->toBe('candidate@example.com')
        ->and($session->json('participants.0.revenus_mensuels'))->toBeNull()
        ->and($session->json('participants.0.children_form_data'))->toBeNull()
        ->and($session->json('participants.0.social_score'))->toBeNull();

    $profile = $this->actingAs($staff, 'sanctum')
        ->getJson('/api/events-info/profile-data?id=4&leak=1')
        ->assertOk();

    expect($profile->json('email'))->toBe('candidate@example.com')
        ->and($profile->json('revenus_mensuels'))->toBeNull();

    $this->actingAs($staff, 'sanctum')
        ->putJson('/api/events-info/validate-invitation', [
            'email' => 'candidate@example.com',
            'code' => 'ABC123',
            'sessionId' => 3,
        ])
        ->assertOk();

    $this->actingAs($staff, 'sanctum')
        ->putJson('/api/events-info/manual-checking', ['id' => 4])
        ->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/session-data')
            && $request->hasHeader('Authorization', 'Bearer '.EVENTS_UPSTREAM_KEY)
            && str_contains($request->url(), 'id=3')
            && ! str_contains($request->url(), 'extra=');
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/profile-data')
            && str_contains($request->url(), 'id=4')
            && ! str_contains($request->url(), 'leak=');
    });
});

test('access_scan user cannot use admin_override', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events/1' => Http::response([
            'event' => publicEvent(['capacity' => 0]),
            'participants' => [],
        ], 200),
        EVENTS_UPSTREAM.'/api/booking/store' => Http::response(['success' => true], 200),
    ]);

    $this->actingAs(eventsScanUser(), 'sanctum')
        ->postJson('/api/events-info/booking/store', [
            'event_id' => 1,
            'answers' => ['name' => 'Staff', 'email' => 'staff@example.com'],
            'admin_override' => true,
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/booking/store')) {
            return false;
        }
        $data = $request->data();

        return empty($data['admin_override']);
    });
});

test('admin can send admin_override to upstream', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events/1' => Http::response([
            'event' => publicEvent(['capacity' => 0]),
            'participants' => [],
        ], 200),
        EVENTS_UPSTREAM.'/api/booking/store' => Http::response(['success' => true], 200),
    ]);

    $this->actingAs(eventsAdminUser(), 'sanctum')
        ->postJson('/api/events-info/booking/store', [
            'event_id' => 1,
            'answers' => ['name' => 'Admin', 'email' => 'admin@example.com'],
            'admin_override' => true,
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/booking/store')) {
            return false;
        }

        return ($request->data()['admin_override'] ?? false) === true
            && $request->hasHeader('Authorization', 'Bearer '.EVENTS_UPSTREAM_KEY);
    });
});

test('proxy uses the server-side upstream key rather than the mobile Sanctum token', function () {
    Http::fake([
        EVENTS_UPSTREAM.'/api/events' => Http::response([publicEvent()], 200),
    ]);

    $this->actingAs(eventsNormalUser(), 'sanctum')
        ->getJson('/api/events-info/events')
        ->assertOk();

    Http::assertSent(function ($request) {
        $authorization = $request->header('Authorization')[0] ?? '';

        return $request->url() === EVENTS_UPSTREAM.'/api/events'
            && $authorization === 'Bearer '.EVENTS_UPSTREAM_KEY;
    });
});

test('staff can upload a session photo and extra fields are not forwarded', function () {
    Storage::fake('local');
    Http::fake([
        EVENTS_UPSTREAM.'/api/session-photo' => Http::response([
            'message' => 'Photo uploaded successfully!',
            'profile' => participantRow(),
        ], 200),
    ]);

    $file = UploadedFile::fake()->image('face.jpg');

    $this->actingAs(eventsScanUser(), 'sanctum')
        ->post('/api/events-info/session-photo', [
            'id' => 4,
            'photo' => $file,
            'role' => 'admin',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonMissing(['revenus_mensuels' => 'hidden-income']);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), '/api/session-photo')
            && $request->hasHeader('Authorization', 'Bearer '.EVENTS_UPSTREAM_KEY)
            && ! array_key_exists('role', $data);
    });
});
