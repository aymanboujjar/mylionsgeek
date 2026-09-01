<?php

namespace App\Http\Controllers;

use App\Models\LinkedAccount;
use App\Models\AppSetting;
use App\Services\ProgramStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LinkedInController extends Controller
{
    private function linkedinClientId(): ?string
    {
        $fromEnv = config('services.linkedin.client_id');
        if (! empty($fromEnv)) return (string) $fromEnv;
        $fromDb = AppSetting::get('linkedin.client_id', null);
        return $fromDb ? (string) $fromDb : null;
    }

    private function linkedinClientSecret(): ?string
    {
        $fromEnv = config('services.linkedin.client_secret');
        if (! empty($fromEnv)) return (string) $fromEnv;
        $fromDb = AppSetting::get('linkedin.client_secret', null);
        return $fromDb ? (string) $fromDb : null;
    }

    private function linkedinRedirectUri(): string
    {
        // Always derive from APP_URL so you don't need to edit .env per environment.
        return rtrim((string) config('app.url'), '/') . '/auth/linkedin/callback';
    }

    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $clientId = $this->linkedinClientId();
        $redirectUri = $this->linkedinRedirectUri();

        if (! $clientId) {
            return redirect()->back()->with('error', 'LinkedIn is not configured yet. Ask an admin to set it in Admin → Settings → LinkedIn.');
        }

        $state = Str::random(40);
        $request->session()->put('linkedin_oauth_state', $state);
        $request->session()->put('linkedin_oauth_return_to', $request->input('return_to', url()->previous()));

        $scopes = [
            'r_liteprofile',
            'w_member_social',
        ];

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scopes),
        ]);

        return redirect()->away('https://www.linkedin.com/oauth/v2/authorization?' . $query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $expectedState = (string) $request->session()->pull('linkedin_oauth_state', '');
        $returnTo = (string) $request->session()->pull('linkedin_oauth_return_to', route('home'));

        $state = (string) $request->query('state', '');
        if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
            return redirect($returnTo)->with('error', 'LinkedIn connection failed (invalid state). Please try again.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            $error = (string) $request->query('error_description', $request->query('error', 'unknown_error'));
            return redirect($returnTo)->with('error', 'LinkedIn connection failed: ' . $error);
        }

        $clientId = $this->linkedinClientId();
        $clientSecret = $this->linkedinClientSecret();
        $redirectUri = $this->linkedinRedirectUri();

        if (! $clientId || ! $clientSecret) {
            return redirect($returnTo)->with('error', 'LinkedIn is not configured yet. Ask an admin to set it in Admin → Settings → LinkedIn.');
        }

        try {
            $tokenResponse = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

            if (! $tokenResponse->successful()) {
                Log::warning('LinkedIn token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body(),
                ]);
                return redirect($returnTo)->with('error', 'LinkedIn connection failed while exchanging token.');
            }

            $tokenJson = $tokenResponse->json();
            $accessToken = (string) ($tokenJson['access_token'] ?? '');
            $expiresIn = (int) ($tokenJson['expires_in'] ?? 0);

            if ($accessToken === '') {
                return redirect($returnTo)->with('error', 'LinkedIn connection failed (missing access token).');
            }

            // Fetch member id
            $meResponse = Http::withToken($accessToken)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->get('https://api.linkedin.com/v2/me');

            if (! $meResponse->successful()) {
                Log::warning('LinkedIn /me failed', [
                    'status' => $meResponse->status(),
                    'body' => $meResponse->body(),
                ]);
                return redirect($returnTo)->with('error', 'LinkedIn connection succeeded, but fetching profile failed.');
            }

            $memberId = (string) ($meResponse->json('id') ?? '');

            LinkedAccount::updateOrCreate(
                ['provider' => 'linkedin', 'user_id' => $user->id],
                [
                    'provider_user_id' => $memberId !== '' ? $memberId : null,
                    'access_token' => $accessToken,
                    'refresh_token' => $tokenJson['refresh_token'] ?? null,
                    'expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
                    'scopes' => ['r_liteprofile', 'w_member_social'],
                    'meta' => [
                        'raw_token' => array_diff_key($tokenJson, array_flip(['access_token', 'refresh_token'])),
                    ],
                ],
            );

            return redirect($returnTo)->with('success', 'LinkedIn connected successfully.');
        } catch (\Throwable $e) {
            Log::error('LinkedIn callback error', [
                'error' => $e->getMessage(),
            ]);

            return redirect($returnTo)->with('error', 'LinkedIn connection failed due to a server error.');
        }
    }

    public function markSharePrompted(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) abort(401);

        if ($user->linkedin_share_prompted_at === null) {
            $user->forceFill([
                'linkedin_share_prompted_at' => now(),
            ])->save();
        }

        return response()->json(['ok' => true]);
    }

    public function dismissSharePrompt(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) abort(401);

        $user->forceFill([
            'linkedin_share_dismissed_at' => now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    public function shareCertificate(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'Certificate sharing on LinkedIn is temporarily disabled.',
        ], 410);

        $user = $request->user();
        if (! $user) abort(401);

        if ((string) ($user->program_status ?? '') !== ProgramStatusService::CERTIFIED) {
            return response()->json(['error' => 'Only certified students can share a certificate.'], 403);
        }

        $linked = LinkedAccount::query()
            ->where('provider', 'linkedin')
            ->where('user_id', $user->id)
            ->first();

        if (! $linked) {
            return response()->json(['error' => 'LinkedIn is not connected.'], 400);
        }

        $memberId = (string) ($linked->provider_user_id ?? '');
        if ($memberId === '') {
            return response()->json(['error' => 'LinkedIn member id is missing. Please reconnect LinkedIn.'], 400);
        }

        // Use the stored path when available; fall back to the flat-path convention
        // for certificates generated before the certificate_pdf_path column existed.
        $pdfPath = $user->certificate_pdf_path ?? ('certificates/' . $user->id . '.pdf');

        // PNG lives alongside the PDF — same base path, different extension.
        $pngPath = preg_replace('/\.pdf$/i', '.png', $pdfPath);

        if (! Storage::disk('public')->exists($pngPath)) {
            // Attempt to generate PNG from the stored PDF if Imagick is available
            if (! class_exists(\Imagick::class) || ! Storage::disk('public')->exists($pdfPath)) {
                return response()->json([
                    'error' => 'Certificate image is not available. Please ask staff to regenerate certificates (PNG) or enable Imagick on the server.',
                ], 500);
            }

            try {
                $pdfBytes = Storage::disk('public')->get($pdfPath);
                $imagick = new \Imagick();
                $imagick->setResolution(200, 200);
                $imagick->readImageBlob($pdfBytes);
                $imagick->setImageFormat('png');
                $imagick->setIteratorIndex(0);
                $pngBytes = $imagick->getImageBlob();
                Storage::disk('public')->put($pngPath, $pngBytes);
                $imagick->clear();
                $imagick->destroy();
            } catch (\Throwable $e) {
                Log::error('Failed to generate certificate PNG for LinkedIn', [
                    'user_id' => $user->id,
                    'training_id' => $trainingId,
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['error' => 'Failed to generate certificate image.'], 500);
            }
        }

        $pngBytes = Storage::disk('public')->get($pngPath);

        $text = (string) $request->input('text', '');
        if ($text === '') {
            // Default share message
            $field = strtolower((string) ($user->field ?? ''));
            $track = str_contains($field, 'media') ? 'Media' : (str_contains($field, 'coding') || str_contains($field, 'dev') ? 'Coding' : 'program');
            $text = "I'm honored to share that I completed LionsGeek ({$track}).";
        }

        $accessToken = (string) $linked->access_token;
        $ownerUrn = 'urn:li:person:' . $memberId;

        try {
            // 1) Register upload
            $registerPayload = [
                'registerUploadRequest' => [
                    'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                    'owner' => $ownerUrn,
                    'serviceRelationships' => [
                        [
                            'relationshipType' => 'OWNER',
                            'identifier' => 'urn:li:userGeneratedContent',
                        ],
                    ],
                ],
            ];

            $registerResp = Http::withToken($accessToken)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->post('https://api.linkedin.com/v2/assets?action=registerUpload', $registerPayload);

            if (! $registerResp->successful()) {
                Log::warning('LinkedIn registerUpload failed', ['status' => $registerResp->status(), 'body' => $registerResp->body()]);
                return response()->json(['error' => 'LinkedIn upload registration failed.'], 502);
            }

            $value = $registerResp->json('value') ?? [];
            $asset = (string) ($value['asset'] ?? '');
            $uploadUrl = (string) data_get($value, 'uploadMechanism.com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest.uploadUrl', '');

            if ($asset === '' || $uploadUrl === '') {
                return response()->json(['error' => 'LinkedIn upload registration returned an unexpected response.'], 502);
            }

            // 2) Upload bytes
            $uploadResp = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/octet-stream'])
                ->withBody($pngBytes, 'application/octet-stream')
                ->send('PUT', $uploadUrl);

            if ($uploadResp->status() < 200 || $uploadResp->status() >= 300) {
                Log::warning('LinkedIn upload PUT failed', ['status' => $uploadResp->status(), 'body' => $uploadResp->body()]);
                return response()->json(['error' => 'LinkedIn media upload failed.'], 502);
            }

            // 3) Create post
            $postPayload = [
                'author' => $ownerUrn,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => ['text' => $text],
                        'shareMediaCategory' => 'IMAGE',
                        'media' => [
                            [
                                'status' => 'READY',
                                'media' => $asset,
                                'title' => ['text' => 'LionsGeek Certificate'],
                            ],
                        ],
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ];

            $postResp = Http::withToken($accessToken)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->post('https://api.linkedin.com/v2/ugcPosts', $postPayload);

            if (! $postResp->successful()) {
                Log::warning('LinkedIn ugcPosts failed', ['status' => $postResp->status(), 'body' => $postResp->body()]);
                return response()->json(['error' => 'LinkedIn post creation failed.'], 502);
            }

            $user->forceFill(['linkedin_shared_at' => now()])->save();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('LinkedIn shareCertificate error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'LinkedIn share failed due to a server error.'], 500);
        }
    }
}
