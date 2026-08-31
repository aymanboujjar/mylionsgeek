<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function fakeWakaTimeSummary(int $seconds = 3600): array
{
    return [
        'data' => [
            [
                'range' => ['date' => now()->toDateString()],
                'grand_total' => ['total_seconds' => $seconds, 'text' => '1 hr'],
                'languages' => [['name' => 'PHP', 'total_seconds' => $seconds]],
                'projects' => [['name' => 'mylionsgeek', 'total_seconds' => $seconds]],
                'editors' => [['name' => 'VS Code', 'total_seconds' => $seconds]],
                'machines' => [['name' => 'laptop', 'total_seconds' => $seconds]],
            ],
        ],
    ];
}

test('guests are redirected to the login page', function () {
    $this->get('/leaderboard/data')->assertRedirect(route('login'));
});

test('leaderboard data endpoint returns wakatime stats without leaking pii', function () {
    Http::fake([
        'https://wakatime.com/api/v1/users/current/summaries*' => Http::response(fakeWakaTimeSummary(7200)),
    ]);

    $viewer = User::factory()->create();
    $coder = User::factory()->create([
        'wakatime_api_key' => 'waka_fake_key_123',
        'promo' => 2026,
        'phone' => '0600000000',
        'cin' => 'AB123456',
    ]);

    $this->actingAs($viewer)
        ->getJson('/leaderboard/data?range=this_week')
        ->assertOk()
        ->assertJsonPath('data.0.user.id', $coder->id)
        ->assertJsonPath('data.0.user.name', $coder->name)
        ->assertJsonPath('data.0.data.total_seconds', 7200)
        ->assertJsonMissingPath('data.0.user.email')
        ->assertJsonMissingPath('data.0.user.phone')
        ->assertJsonMissingPath('data.0.user.cin')
        ->assertJsonMissingPath('data.0.user.wakatime_api_key');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'wakatime.com/api/v1/users/current/summaries'));
});

test('leaderboard insights endpoint fetches per-user data without exposing the api key', function () {
    Http::fake([
        'https://wakatime.com/api/v1/users/current/insights/*' => Http::response(['data' => ['text' => 'ok']]),
    ]);

    $viewer = User::factory()->create();
    $coder = User::factory()->create(['wakatime_api_key' => 'waka_fake_key_456']);

    $this->actingAs($viewer)
        ->getJson("/leaderboard/insights/{$coder->id}?range=this_week")
        ->assertOk()
        ->assertJsonPath('insights.best_day.data.text', 'ok');
});

test('leaderboard data ranks multiple concurrently-fetched users by total seconds', function () {
    $viewer = User::factory()->create();

    $seconds = ['Alice' => 1000, 'Bob' => 7200, 'Cara' => 3600];
    foreach ($seconds as $name => $secs) {
        User::factory()->create(['name' => $name, 'wakatime_api_key' => "key_{$name}"]);
    }

    Http::fake(function ($request) use ($seconds) {
        $auth = $request->header('Authorization')[0] ?? '';
        foreach ($seconds as $name => $secs) {
            if ($auth === 'Basic ' . base64_encode("key_{$name}" . ':')) {
                return Http::response(fakeWakaTimeSummary($secs));
            }
        }
        return Http::response([], 404);
    });

    $response = $this->actingAs($viewer)
        ->getJson('/leaderboard/data?range=this_week')
        ->assertOk();

    $rows = collect($response->json('data'));
    expect($rows->pluck('user.name')->all())->toBe(['Bob', 'Cara', 'Alice']);
    expect($rows->pluck('metrics.rank')->all())->toBe([1, 2, 3]);
});

test('weekly winners endpoint never exposes raw user model fields', function () {
    Http::fake([
        'https://wakatime.com/api/v1/users/current/stats/last_7_days' => Http::response([
            'data' => ['total_seconds' => 5400],
        ]),
    ]);

    $viewer = User::factory()->create();
    User::factory()->create([
        'wakatime_api_key' => 'waka_fake_key_789',
        'phone' => '0600000001',
        'cin' => 'CD654321',
    ]);

    $this->actingAs($viewer)
        ->getJson('/leaderboard/weekly-winners')
        ->assertOk()
        ->assertJsonMissingPath('winners.0.user.email')
        ->assertJsonMissingPath('winners.0.user.phone')
        ->assertJsonMissingPath('winners.0.user.cin')
        ->assertJsonMissingPath('winners.0.user.wakatime_api_key');
});

test('weekly winners ranks users by total seconds and only returns the top 3', function () {
    $viewer = User::factory()->create();

    $seconds = ['Alice' => 1000, 'Bob' => 5000, 'Cara' => 3000, 'Drew' => 500];
    foreach ($seconds as $name => $secs) {
        User::factory()->create(['name' => $name, 'wakatime_api_key' => "key_{$name}"]);
    }

    Http::fake(function ($request) use ($seconds) {
        $auth = $request->header('Authorization')[0] ?? '';
        foreach ($seconds as $name => $secs) {
            if ($auth === 'Basic ' . base64_encode("key_{$name}" . ':')) {
                return Http::response(['data' => ['total_seconds' => $secs]]);
            }
        }
        return Http::response([], 404);
    });

    $response = $this->actingAs($viewer)
        ->getJson('/leaderboard/weekly-winners')
        ->assertOk();

    $names = collect($response->json('winners'))->pluck('user.name')->all();
    expect($names)->toBe(['Bob', 'Cara', 'Alice']);
});

test('previous week podium ranks users and exposes the full ordered list plus top 3', function () {
    $viewer = User::factory()->create();

    $seconds = ['Alice' => 1000, 'Bob' => 5000, 'Cara' => 3000, 'Drew' => 500];
    foreach ($seconds as $name => $secs) {
        User::factory()->create(['name' => $name, 'wakatime_api_key' => "key_{$name}"]);
    }

    Http::fake(function ($request) use ($seconds) {
        $auth = $request->header('Authorization')[0] ?? '';
        foreach ($seconds as $name => $secs) {
            if ($auth === 'Basic ' . base64_encode("key_{$name}" . ':')) {
                return Http::response(fakeWakaTimeSummary($secs));
            }
        }
        return Http::response([], 404);
    });

    $response = $this->actingAs($viewer)
        ->getJson('/leaderboard/previous-week-podium')
        ->assertOk();

    expect(collect($response->json('results'))->pluck('user.name')->all())
        ->toBe(['Bob', 'Cara', 'Alice', 'Drew']);
    expect(collect($response->json('results'))->pluck('rank')->all())->toBe([1, 2, 3, 4]);
    expect(collect($response->json('winners'))->pluck('user.name')->all())
        ->toBe(['Bob', 'Cara', 'Alice']);
});
