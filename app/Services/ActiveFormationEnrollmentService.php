<?php

namespace App\Services;

use App\Models\Formation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared targeting for attendance jobs that act on students in is_active formations.
 */
class ActiveFormationEnrollmentService
{
    /**
     * @return list<int>
     */
    public function activeFormationIds(): array
    {
        return Formation::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Yield each enrolled user with one active formation id to act on.
     *
     * @param  list<int>  $activeFormationIds
     * @return \Generator<int, array{user: User, formation_id: int}>
     */
    public function eachEnrolledStudent(array $activeFormationIds): \Generator
    {
        if ($activeFormationIds === []) {
            return;
        }

        $activeFormationLookup = array_fill_keys($activeFormationIds, true);

        $query = User::query()->where(function ($builder) use ($activeFormationIds) {
            $builder->whereIn('formation_id', $activeFormationIds);

            if (Schema::hasTable('formation_user')) {
                $builder->orWhereIn('id', DB::table('formation_user')
                    ->whereIn('formation_id', $activeFormationIds)
                    ->select('user_id'));
            }
        });

        foreach ($query->cursor() as $user) {
            $formationId = $this->resolveActiveFormationId($user, $activeFormationLookup);
            if ($formationId === null) {
                continue;
            }

            yield [
                'user' => $user,
                'formation_id' => $formationId,
            ];
        }
    }

    /**
     * @param  array<int, true>  $activeFormationLookup
     */
    public function resolveActiveFormationId(User $user, array $activeFormationLookup): ?int
    {
        foreach ($user->resolvedFormationIds() as $formationId) {
            if (isset($activeFormationLookup[$formationId])) {
                return $formationId;
            }
        }

        return null;
    }
}
