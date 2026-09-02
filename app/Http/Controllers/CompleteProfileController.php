<?php

namespace App\Http\Controllers;

use App\Mail\NewPasswordNotification;
use App\Mail\UserWelcomeMail;
use App\Mail\WelcomeUserAfterProfileComplete;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Support\Facades\Hash;

class CompleteProfileController extends Controller
{
    public function goToCompleteProfile(Request $request, $token)
    {
        $user = $this->resolveActivationUser($request, $token);
        if (! $user) {
            return Inertia::render('profile/ExpiredLink');
        }

        $inviteSource = $user->invite_source;
        $fromLionsgeek = in_array($inviteSource, ['lionsgeek_adult', 'lionsgeek_children'], true);

        $expiresAt = $user->activation_token_expires_at ?? now()->addHours(User::ACTIVATION_TTL_HOURS);

        return Inertia::render('profile/index', [
            'user' => [
                'name' => $user->name,
                'phone' => $user->phone,
            ],
            'submitUrl' => URL::temporarySignedRoute(
                'user.complete-profile.update',
                $expiresAt,
                ['token' => $token]
            ),
            'profileMeta' => [
                'from_lionsgeek' => $fromLionsgeek,
                'is_children' => $inviteSource === 'lionsgeek_children',
                'require_phone' => ! $fromLionsgeek,
                'show_cin' => $inviteSource !== 'lionsgeek_children',
            ],
        ]);
    }

    public function resendActivationLink($id)
    {
        $user = User::find($id);
        $plainToken = $user->issueActivationToken();

        $link = URL::temporarySignedRoute(
            'user.complete-profile',
            now()->addHours(User::ACTIVATION_TTL_HOURS),
            ['token' => $plainToken]
        );

        Mail::to($user->email)->send(new UserWelcomeMail($user, $link));

        return redirect()->back()->with('success', 'Activation link resent successfully.');
    }

    public function submitCompleteProfile(Request $request, $token)
    {
        $user = $this->resolveActivationUser($request, $token);
        if (! $user) {
            return Inertia::render('profile/ExpiredLink');
        }

        $fromLionsgeek = in_array($user->invite_source, ['lionsgeek_adult', 'lionsgeek_children'], true);
        $isChildren = $user->invite_source === 'lionsgeek_children';

        $request->merge([
            'cin' => $request->filled('cin') ? $request->input('cin') : null,
            'entreprise' => $request->filled('entreprise') ? $request->input('entreprise') : null,
        ]);

        $rules = [
            'password' => 'required|string|min:8|confirmed',
            'entreprise' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:2048',
        ];

        if ($fromLionsgeek) {
            $rules['phone'] = 'nullable|string|max:30';
        } else {
            $rules['phone'] = 'required|string|max:15';
        }

        if ($isChildren) {
            $rules['cin'] = 'nullable|string|max:10|unique:users,cin,' . $user->id;
        } else {
            $rules['cin'] = 'nullable|string|max:10|unique:users,cin,' . $user->id;
        }

        $validated = $request->validate($rules);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = $file->hashName();
            $file->move(public_path('/storage/img/profile'), $filename);
            $validated['image'] = $filename;
        }

        $user->password = Hash::make($validated['password']);

        if ($fromLionsgeek) {
            // Keep name/phone already provided by lionsgeek-app.
            if (! empty($validated['phone'])) {
                $user->phone = $validated['phone'];
            }
        } else {
            $user->phone = $validated['phone'];
        }

        if (! $isChildren) {
            $user->cin = $validated['cin'] ?? null;
        }

        $user->entreprise = $validated['entreprise'] ?? null;
        $user->account_state = 0;
        if (array_key_exists('image', $validated)) {
            $user->image = $validated['image'];
        }
        $user->save();
        $user->consumeActivationToken();

        Mail::to($user->email)->send(new WelcomeUserAfterProfileComplete($user));

        return redirect('/login')->with('success', 'Profile completed successfully. You can now log in.');
    }

    public function resetPassword($id)
    {
        $user = User::find($id);
        $newPassword = Str::random(10);

        $user->password = Hash::make($newPassword);
        $user->save();

        $user->tokens()->delete();

        Mail::to($user->email)->send(new NewPasswordNotification($user, $newPassword));

        return back()->with('success', 'Password reset and email sent to user.');
    }

    private function resolveActivationUser(Request $request, string $token): ?User
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        return User::findByActivationToken($token);
    }
}
