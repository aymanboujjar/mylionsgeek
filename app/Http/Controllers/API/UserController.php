<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\UserWelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function inviteStudent(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'image' => ['nullable', 'string', 'max:2048'],
            'is_children' => ['nullable'],
        ]);

        $inviteSource = filter_var($data['is_children'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? 'lionsgeek_children'
            : 'lionsgeek_adult';

        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing) {
            if ($existing->hasPendingActivation()) {
                $existing->fill([
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?: $existing->phone,
                    'image' => $data['image'] ?? $existing->image,
                    'invite_source' => $inviteSource,
                ]);
                $existing->save();

                $mailSent = $this->sendCompleteProfileEmail($existing);
            } else {
                $mailSent = false;
            }

            return response()->json([
                'status' => 'exists',
                'mail_sent' => $mailSent,
            ]);
        }

        $user = new User();
        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::random(32),
            'phone' => $data['phone'] ?: null,
            'image' => $data['image'] ?? 'pdp.png',
            'status' => 'Studying',
            'cin' => null,
            'formation_id' => null,
            'account_state' => 0,
            'access_studio' => 1,
            'access_cowork' => 1,
            'role' => ['student'],
            'invite_source' => $inviteSource,
            'remember_token' => null,
            'email_verified_at' => null,
        ])->save();

        $mailSent = $this->sendCompleteProfileEmail($user);

        return response()->json([
            'status' => 'created',
            'mail_sent' => $mailSent,
        ]);
    }

    /**
     * Send the signed complete-profile email. Returns false on failure (user is still created/updated).
     */
    private function sendCompleteProfileEmail(User $user): bool
    {
        try {
            $plainToken = $user->issueActivationToken();

            $link = URL::temporarySignedRoute(
                'user.complete-profile',
                now()->addHours(User::ACTIVATION_TTL_HOURS),
                ['token' => $plainToken]
            );

            Mail::to($user->email)->send(new UserWelcomeMail($user, $link));

            Log::info('Complete profile email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mailer' => config('mail.default'),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Complete profile email failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mailer' => config('mail.default'),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
