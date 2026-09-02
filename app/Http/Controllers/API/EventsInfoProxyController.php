<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proxies the public LionsGeek site (lionsgeek.ma) events endpoints for the
 * mobile app.
 *
 * Incoming requests are authenticated with Sanctum and authorized in this
 * controller / route middleware. The upstream bearer is read server-side from
 * config('services.lionsgeek') and is never taken from the device.
 */
class EventsInfoProxyController extends Controller
{
    private const TIMEOUT = 15;

    private const STAFF_BOOKING_KEYS = [
        'id',
        'name',
        'email',
        'phone',
        'tel',
        'mobile',
        'gender',
        'is_visited',
        'event_id',
        'code',
        'company',
        'organization',
        'job_title',
        'city',
        'address',
        'created_at',
        'registered_at',
        'updated_at',
        'form_data',
    ];

    private const STAFF_PARTICIPANT_KEYS = [
        'id',
        'info_session_id',
        'full_name',
        'name',
        'email',
        'phone',
        'city',
        'region',
        'code',
        'formation_field',
        'gender',
        'current_step',
        'education_level',
        'image',
        'is_visited',
        'created_at',
        'updated_at',
    ];

    public function events(Request $request): JsonResponse
    {
        $response = $this->forward('GET', 'events');
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $events = $response->getData(true);
        if (! is_array($events)) {
            return $response;
        }

        $user = $request->user();
        $staff = $user instanceof User && $user->canAccessEventsScan();

        if (! $staff) {
            $events = array_values(array_filter($events, fn ($event) => is_array($event) && ! $this->isPrivateEvent($event)));
        }

        $events = array_map(fn ($event) => is_array($event) ? $this->sanitizeEvent($event) : $event, $events);

        return response()->json($events);
    }

    public function event(Request $request, string $event): JsonResponse
    {
        $response = $this->forward('GET', "events/{$event}");
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return $response;
        }

        $eventData = is_array($payload['event'] ?? null) ? $payload['event'] : $payload;
        $user = $request->user();
        $staff = $user instanceof User && $user->canAccessEventsScan();

        if ($this->isPrivateEvent($eventData) && ! $staff) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $sanitizedEvent = $this->sanitizeEvent($eventData);

        if (! $staff) {
            return response()->json([
                'event' => $sanitizedEvent,
                'participants' => [],
            ]);
        }

        $participants = $payload['participants'] ?? [];
        $participants = is_array($participants)
            ? array_map(fn ($row) => is_array($row) ? $this->pickKeys($row, self::STAFF_BOOKING_KEYS) : $row, $participants)
            : [];

        return response()->json([
            'event' => $sanitizedEvent,
            'participants' => $participants,
        ]);
    }

    public function validateEventInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required',
            'id' => 'required|integer',
        ]);

        return $this->sanitizeCheckInResponse($this->forward('PUT', 'validate-event-invitation', [
            'json' => $validated,
        ]), self::STAFF_BOOKING_KEYS);
    }

    public function manualEventChecking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer',
            'event_id' => 'required|integer',
        ]);

        return $this->sanitizeCheckInResponse($this->forward('PUT', 'manual-event-checking', [
            'json' => $validated,
        ]), self::STAFF_BOOKING_KEYS);
    }

    public function storeBooking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => 'required|integer',
            'answers' => 'nullable|array',
            'admin_override' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $eventResponse = $this->forward('GET', 'events/'.$validated['event_id']);
        if ($eventResponse->getStatusCode() !== 200) {
            return $eventResponse;
        }

        $payload = $eventResponse->getData(true);
        $eventData = is_array($payload['event'] ?? null) ? $payload['event'] : (is_array($payload) ? $payload : []);

        if ($this->isPrivateEvent($eventData) && ! $user->canAccessEventsScan()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $body = [
            'event_id' => $validated['event_id'],
            'answers' => $validated['answers'] ?? [],
        ];

        if (! empty($validated['admin_override']) && $user->isEventsAdmin()) {
            $body['admin_override'] = true;
        }

        return $this->forward('POST', 'booking/store', [
            'json' => $body,
        ]);
    }

    public function infoSessions(): JsonResponse
    {
        $response = $this->forward('GET', 'lionsgate/infosessions');
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return $response;
        }

        if (isset($payload['infos']) && is_array($payload['infos'])) {
            $payload['infos'] = array_map(
                fn ($session) => is_array($session) ? $this->sanitizeSession($session) : $session,
                $payload['infos']
            );
        }

        return response()->json($payload);
    }

    public function sessionData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer',
        ]);

        $response = $this->forward('GET', 'session-data?'.http_build_query(['id' => $validated['id']]));
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return $response;
        }

        if (isset($payload['session']) && is_array($payload['session'])) {
            $payload['session'] = $this->sanitizeSession($payload['session']);
        }

        foreach (['participants', 'attended', 'unattended'] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }
            $payload[$key] = array_map(
                fn ($row) => is_array($row) ? $this->pickKeys($row, self::STAFF_PARTICIPANT_KEYS) : $row,
                $payload[$key]
            );
        }

        return response()->json($payload);
    }

    public function validateInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required',
            'sessionId' => 'required|integer',
        ]);

        return $this->sanitizeCheckInResponse($this->forward('PUT', 'validate-invitation', [
            'json' => $validated,
        ]), self::STAFF_PARTICIPANT_KEYS);
    }

    public function manualChecking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer',
        ]);

        return $this->sanitizeCheckInResponse($this->forward('PUT', 'manual-checking', [
            'json' => $validated,
        ]), self::STAFF_PARTICIPANT_KEYS);
    }

    public function profileData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer',
        ]);

        $response = $this->forward('GET', 'profile-data?'.http_build_query(['id' => $validated['id']]));
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload) || isset($payload['message'])) {
            return $response;
        }

        return response()->json($this->pickKeys($payload, self::STAFF_PARTICIPANT_KEYS));
    }

    public function sessionPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file',
            'id' => 'required|integer',
        ]);

        $baseUrl = rtrim((string) config('services.lionsgeek.url'), '/');
        $apiKey = (string) config('services.lionsgeek.key');

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json(['error' => 'Events proxy is not configured.'], 500);
        }

        $verify = config('services.lionsgeek.verify', true);
        $file = $request->file('photo');

        try {
            $client = Http::withToken($apiKey)->acceptJson()->timeout(30);
            if ($verify === false) {
                $client = $client->withoutVerifying();
            }

            $response = $client
                ->attach(
                    'photo',
                    fopen($file->getRealPath(), 'r'),
                    $file->getClientOriginalName()
                )
                ->post("{$baseUrl}/api/session-photo", [
                    'id' => $request->integer('id'),
                ]);
        } catch (ConnectionException $e) {
            Log::error('LionsGeek session-photo proxy could not reach upstream.', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Could not reach the LionsGeek server.'], 502);
        } catch (Throwable $e) {
            Log::error('LionsGeek session-photo proxy failed unexpectedly.', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Unexpected error while uploading photo.'], 502);
        }

        $json = $response->json();
        if (is_array($json) && isset($json['profile']) && is_array($json['profile'])) {
            $json['profile'] = $this->pickKeys($json['profile'], self::STAFF_PARTICIPANT_KEYS);
        }

        return response()->json($json, $response->status());
    }

    public function participantPhoto(string $photo): Response
    {
        $baseUrl = rtrim((string) config('services.lionsgeek.url'), '/');

        if ($baseUrl === '') {
            return response('Events proxy is not configured.', 500);
        }

        $filename = basename(urldecode($photo));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return response('Invalid image path.', 400);
        }

        $verify = config('services.lionsgeek.verify', true);
        $imageUrl = "{$baseUrl}/storage/images/participants/".rawurlencode($filename);

        try {
            $client = Http::timeout(self::TIMEOUT);
            if ($verify === false) {
                $client = $client->withoutVerifying();
            }

            $response = $client->get($imageUrl);
        } catch (ConnectionException $e) {
            Log::error('LionsGeek participant image proxy could not reach upstream.', [
                'filename' => $filename,
                'message' => $e->getMessage(),
            ]);

            return response('Could not reach the image server.', 502);
        } catch (Throwable $e) {
            Log::error('LionsGeek participant image proxy failed unexpectedly.', [
                'filename' => $filename,
                'message' => $e->getMessage(),
            ]);

            return response('Unexpected error while fetching image.', 502);
        }

        if (! $response->successful()) {
            return response('Image not found.', $response->status());
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function eventCover(string $cover): Response
    {
        $baseUrl = rtrim((string) config('services.lionsgeek.url'), '/');

        if ($baseUrl === '') {
            return response('Events proxy is not configured.', 500);
        }

        $filename = basename(urldecode($cover));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return response('Invalid image path.', 400);
        }

        $verify = config('services.lionsgeek.verify', true);
        $imageUrl = "{$baseUrl}/storage/images/events/".rawurlencode($filename);

        try {
            $client = Http::timeout(self::TIMEOUT);
            if ($verify === false) {
                $client = $client->withoutVerifying();
            }

            $response = $client->get($imageUrl);
        } catch (ConnectionException $e) {
            Log::error('LionsGeek image proxy could not reach upstream.', [
                'filename' => $filename,
                'message' => $e->getMessage(),
            ]);

            return response('Could not reach the image server.', 502);
        } catch (Throwable $e) {
            Log::error('LionsGeek image proxy failed unexpectedly.', [
                'filename' => $filename,
                'message' => $e->getMessage(),
            ]);

            return response('Unexpected error while fetching image.', 502);
        }

        if (! $response->successful()) {
            return response('Image not found.', $response->status());
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function forward(string $method, string $path, array $options = []): JsonResponse
    {
        $baseUrl = rtrim((string) config('services.lionsgeek.url'), '/');
        $apiKey = (string) config('services.lionsgeek.key');

        if ($baseUrl === '' || $apiKey === '') {
            Log::error('LionsGeek proxy is not configured.', [
                'has_url' => $baseUrl !== '',
                'has_key' => $apiKey !== '',
            ]);

            return response()->json([
                'error' => 'Events proxy is not configured on the server. Set LIONSGEEK_MA_API_URL and LIONSGEEK_MA_API_KEY.',
            ], 500);
        }

        $verify = config('services.lionsgeek.verify', true);

        try {
            $client = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(self::TIMEOUT);

            if ($verify === false) {
                $client = $client->withoutVerifying();
            }

            $response = $client->send($method, "{$baseUrl}/api/{$path}", $options);
        } catch (ConnectionException $e) {
            Log::error('LionsGeek proxy could not reach upstream.', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Could not reach the LionsGeek events server.',
            ], 502);
        } catch (Throwable $e) {
            Log::error('LionsGeek proxy failed unexpectedly.', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Unexpected error while contacting the events server.',
            ], 502);
        }

        return response()->json($response->json(), $response->status());
    }

    /**
     * @param  list<string>  $profileKeys
     */
    private function sanitizeCheckInResponse(JsonResponse $response, array $profileKeys): JsonResponse
    {
        $payload = $response->getData(true);
        if (! is_array($payload) || ! isset($payload['profile']) || ! is_array($payload['profile'])) {
            return $response;
        }

        $payload['profile'] = $this->pickKeys($payload['profile'], $profileKeys);

        return response()->json($payload, $response->getStatusCode());
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isPrivateEvent(array $event): bool
    {
        $flag = $event['is_private'] ?? false;

        return $flag === true || $flag === 1 || $flag === '1';
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function sanitizeEvent(array $event): array
    {
        unset($event['private_url_token']);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function sanitizeSession(array $session): array
    {
        unset($session['private_url_token']);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pickKeys(array $row, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $picked[$key] = $row[$key];
            }
        }

        return $picked;
    }
}
