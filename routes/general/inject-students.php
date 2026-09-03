<?php

use App\Models\Formation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * One-shot student import from spreadsheet data.
 * Skips any email already in users and syncs promo from formation.
 */
Route::get('/inject-student', function () {
    $formationId = 7;

    $formation = Formation::query()->find($formationId);
    $promoRaw = $formation?->promo;
    $promo = is_numeric($promoRaw) ? (int) $promoRaw : null;

    $students = [
        ['name' => 'Inas Rhafes', 'email' => 'inass.rhafes@gmail.com', 'phone' => '0611380563'],
        ['name' => 'Basma Lamrani', 'email' => 'lamranibasmaa@gmail.com', 'phone' => '0706122461'],
        ['name' => 'Fatima Zahra Mriga', 'email' => 'fz.mriga98@gmail.com', 'phone' => '0628956296'],
        ['name' => 'Dounia Ouadi', 'email' => 'douniaouadi123@gmail.com', 'phone' => '0682286279'],
        ['name' => 'Ibtissam DRAIA', 'email' => 'Bilalibtissam84@gmail.com', 'phone' => '0688771851'],
        ['name' => 'Zhour Ouchikh', 'email' => 'zh.ouchikh@gmail.com', 'phone' => '0605-615627'],
        ['name' => 'Kawtar Sekrati', 'email' => 'kawtarsekrati.106@gmail.com', 'phone' => '0624021155'],
        ['name' => 'Safaa Lharri', 'email' => 'sofiesafaa49@gmail.com', 'phone' => '0687875162'],
        ['name' => 'Asma Boulboul', 'email' => 'boulboulasmae77@gmail.com', 'phone' => '0648108404'],
        ['name' => 'Tamrani Meriem', 'email' => 'meriemtamrani@gmail.com', 'phone' => '0631513334'],
        ['name' => 'Akram El mansouri', 'email' => 'akramelmansouri121@gmail.com', 'phone' => '0777386115'],
        ['name' => 'Anouar EL Haddad', 'email' => 'anwarelhaddad.work@gmail.com', 'phone' => '0694138817'],
        ['name' => 'Hamza Hchicha', 'email' => 'hamzahachicha383@gmail.com', 'phone' => '0699609769'],
        ['name' => 'Omar Riad', 'email' => 'omarriadofficial@gmail.com', 'phone' => '0644223229'],
        ['name' => 'Salaheddine Anamir', 'email' => 'salaheddineanamir@gmail.com', 'phone' => '0611328346'],
        ['name' => 'Bassam Rafiqi', 'email' => 'rafiqib719@gmail.com', 'phone' => '+212 694-791380'],
        ['name' => 'Hassan BOUTAMEN', 'email' => 'hassanbtn789@gmail.com', 'phone' => '0625424739'],
    ];

    $normalizeEmail = static fn (?string $email): string => Str::lower(trim((string) $email));

    $normalizePhone = static function (?string $phone): ?string {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($phone));

        if ($digits === '' || $digits === '0') {
            return null;
        }

        if (str_starts_with($digits, '212') && strlen($digits) > 9) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    };

    $inserted = [];
    $skipped = [];

    DB::beginTransaction();

    try {
        foreach ($students as $row) {
            $email = $normalizeEmail($row['email']);

            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $skipped[] = ['email' => $email, 'name' => $row['name'], 'reason' => 'already_exists'];

                continue;
            }

            $user = new User;
            $user->name = $row['name'];
            $user->email = $email;
            $user->phone = $normalizePhone($row['phone'] ?? null);
            $user->formation_id = $formationId;
            $user->promo = $promo;
            $user->access_studio = 1;
            $user->access_cowork = 1;
            $user->entreprise = null;
            $user->cin = null;
            $user->status = null;
            $user->image = null;
            $user->password = null;
            $user->email_verified_at = null;
            $user->remember_token = null;
            $user->about = null;
            $user->cover = null;
            $user->speciality = null;
            $user->resume = null;
            $user->field = null;
            $user->wakatime_api_key = null;
            $user->activation_token = null;
            $user->expo_push_token = null;
            $user->certificate_pdf_path = null;
            $user->last_online = null;
            $user->certified_at = null;
            $user->certified_training_id = null;
            $user->previous_week_rank = null;
            $user->last_rank_update = null;
            $user->save();

            $inserted[] = ['id' => $user->id, 'email' => $email, 'name' => $row['name']];
        }

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json([
            'ok' => false,
            'message' => $e->getMessage(),
            'inserted' => $inserted,
            'skipped' => $skipped,
        ], 500);
    }

    $promoSynced = 0;
    if ($promo !== null) {
        $promoSynced = User::query()
            ->where('formation_id', $formationId)
            ->where(function ($query) {
                $query->whereNull('promo')->orWhere('promo', 0)->orWhere('promo', '');
            })
            ->update([
                'promo' => $promo,
                'updated_at' => now(),
            ]);
    }

    return response()->json([
        'ok' => true,
        'formation_id' => $formationId,
        'promo' => $promo,
        'access_studio' => 1,
        'access_cowork' => 1,
        'inserted_count' => count($inserted),
        'skipped_count' => count($skipped),
        'promo_synced_count' => $promoSynced,
        'inserted' => $inserted,
        'skipped' => $skipped,
    ]);
})->name('inject-student');
