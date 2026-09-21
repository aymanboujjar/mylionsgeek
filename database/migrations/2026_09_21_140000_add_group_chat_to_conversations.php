<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type', 20)->default('direct')->after('id');
            $table->string('name')->nullable()->after('type');
            $table->string('avatar')->nullable()->after('name');
            $table->foreignId('created_by')->nullable()->after('avatar')->constrained('users')->nullOnDelete();
        });

        // Groups have no 1:1 pair; directs keep both IDs.
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['user_one_id', 'user_two_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_one_id')->nullable()->change();
            $table->unsignedBigInteger('user_two_id')->nullable()->change();
        });

        // Restore uniqueness for direct chats. Groups use (NULL, NULL); SQLite/MySQL
        // treat NULLs as distinct in UNIQUE indexes, so multiple groups remain allowed.
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['user_one_id', 'user_two_id']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // owner | admin | member — admin is reserved for future promote; only owner is assigned today.
            $table->string('role', 20)->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        // Backfill participants for existing direct conversations (chunked).
        $now = now();
        DB::table('conversations')
            ->select('id', 'user_one_id', 'user_two_id', 'created_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now) {
                $participants = [];
                foreach ($rows as $row) {
                    if ($row->user_one_id) {
                        $participants[] = [
                            'conversation_id' => $row->id,
                            'user_id' => $row->user_one_id,
                            'role' => 'member',
                            'joined_at' => $row->created_at ?? $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($row->user_two_id && (int) $row->user_two_id !== (int) $row->user_one_id) {
                        $participants[] = [
                            'conversation_id' => $row->id,
                            'user_id' => $row->user_two_id,
                            'role' => 'member',
                            'joined_at' => $row->created_at ?? $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($participants !== []) {
                    DB::table('conversation_participants')->insert($participants);
                }
            });
    }

    public function down(): void
    {
        // Groups have null pair IDs — remove them before restoring non-null unique.
        if (Schema::hasColumn('conversations', 'type')) {
            DB::table('conversations')->where('type', 'group')->delete();
        }

        Schema::dropIfExists('conversation_participants');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['user_one_id', 'user_two_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'name', 'avatar']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_one_id')->nullable(false)->change();
            $table->unsignedBigInteger('user_two_id')->nullable(false)->change();
            $table->unique(['user_one_id', 'user_two_id']);
        });
    }
};
