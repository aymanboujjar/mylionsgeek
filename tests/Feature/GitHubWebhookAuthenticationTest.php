<?php

use App\Models\Project;
use App\Models\ProjectRepositoryEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const N5_TEST_SECRET = 'n5-test-only-github-webhook-secret';

beforeEach(function () {
    $this->withoutVite();
    config(['services.github.webhook_secret' => null]);
});

function n5Project(): Project
{
    $user = User::factory()->create([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
    ]);

    return Project::query()->create([
        'name' => 'N5 Project',
        'status' => 'active',
        'created_by' => $user->id,
        'is_updated' => false,
        'last_activity' => null,
    ]);
}

function n5Body(): string
{
    return '{"ref":"refs/heads/main","after":"abcdef1234567890","commits":[{}],"head_commit":{"url":"https://github.com/org/repo/commit/abc"},"repository":{"full_name":"org/repo","html_url":"https://github.com/org/repo","name":"repo"},"sender":{"login":"octocat","avatar_url":"https://example.test/avatar.png"}}';
}

function n5Sign(string $body, string $secret = N5_TEST_SECRET): string
{
    return 'sha256='.hash_hmac('sha256', $body, $secret);
}

function n5Post(Project $project, string $body, array $headers = [])
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call(
        'POST',
        '/projects/'.$project->id.'/repository-events/github',
        [],
        [],
        [],
        $server,
        $body
    );
}

function n5EventCount(): int
{
    return (int) DB::table('project_repository_events')->count();
}

test('configured secret and valid signature records a repository event', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();
    $body = n5Body();

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body),
        'X-GitHub-Event' => 'push',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(n5EventCount())->toBe(1)
        ->and((int) $project->fresh()->is_updated)->toBe(1)
        ->and($project->fresh()->last_activity)->not->toBeNull()
        ->and(ProjectRepositoryEvent::query()->value('event_type'))->toBe('github_push')
        ->and(ProjectRepositoryEvent::query()->value('project_id'))->toBe($project->id);
});

test('configured secret and invalid signature is rejected', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();
    $body = n5Body();

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body, 'wrong-secret'),
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});

test('configured secret and missing signature is rejected', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();

    n5Post($project, n5Body(), [
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});

test('configured secret and empty signature is rejected', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();

    n5Post($project, n5Body(), [
        'X-Hub-Signature-256' => '',
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0);
});

test('configured secret and malformed signature prefix is rejected', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();
    $body = n5Body();
    $digest = hash_hmac('sha256', $body, N5_TEST_SECRET);

    n5Post($project, $body, [
        'X-Hub-Signature-256' => 'sha1='.$digest,
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0);
});

test('configured secret and wrong-length signature is rejected', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();

    n5Post($project, n5Body(), [
        'X-Hub-Signature-256' => 'sha256=abcd',
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0);
});

test('configured secret and non-hex signature is rejected', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();

    n5Post($project, n5Body(), [
        'X-Hub-Signature-256' => 'sha256='.str_repeat('g', 64),
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});

test('missing webhook secret is rejected with 503', function () {
    config(['services.github.webhook_secret' => null]);
    $project = n5Project();
    $body = n5Body();

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body),
        'X-GitHub-Event' => 'push',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'GitHub webhook is not configured.');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});

test('empty webhook secret is rejected with 503', function () {
    config(['services.github.webhook_secret' => '']);
    $project = n5Project();
    $body = n5Body();

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body),
        'X-GitHub-Event' => 'push',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'GitHub webhook is not configured.');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0);
});

test('whitespace-only webhook secret is rejected with 503', function () {
    config(['services.github.webhook_secret' => '   ']);
    $project = n5Project();
    $body = n5Body();

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body, '   '),
        'X-GitHub-Event' => 'push',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'GitHub webhook is not configured.');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});

test('signature is calculated against the exact raw request body', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();
    $rawBody = '{"repository": {"full_name": "org/repo"}, "sender": {"login": "octocat"}}';
    $reencodedBody = json_encode(json_decode($rawBody, true));

    n5Post($project, $rawBody, [
        'X-Hub-Signature-256' => n5Sign($reencodedBody),
        'X-GitHub-Event' => 'push',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0);

    n5Post($project, $rawBody, [
        'X-Hub-Signature-256' => n5Sign($rawBody),
        'X-GitHub-Event' => 'push',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(n5EventCount())->toBe(1);
});

test('valid signed ping does not create an event or update the project', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();
    $body = '{"zen":"N5 ping","hook_id":1}';

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body),
        'X-GitHub-Event' => 'ping',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Webhook connected');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});

test('changing X-GitHub-Event cannot bypass signature verification', function () {
    config(['services.github.webhook_secret' => N5_TEST_SECRET]);
    $project = n5Project();
    $body = n5Body();

    n5Post($project, $body, [
        'X-Hub-Signature-256' => n5Sign($body, 'wrong-secret'),
        'X-GitHub-Event' => 'ping',
    ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Invalid signature');

    expect(n5EventCount())->toBe(0)
        ->and((int) $project->fresh()->is_updated)->toBe(0)
        ->and($project->fresh()->last_activity)->toBeNull();
});
