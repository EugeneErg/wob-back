<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            // When the crown was won. The catalogue needs a stable order and
            // "first canonical story" has to mean something exact — it is the
            // one level a signed-out visitor is allowed to play, so it cannot
            // depend on row order or on a title changing.
            $table->timestamp('canonical_since')->nullable();

            $table->index(['canonical_since']);
        });

        // A player can take the same story more than once — a fresh run, a
        // 100% attempt, a different branch — without one erasing the other.
        Schema::create('save_slots', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('player_id');

            // Slots belong to a story, not to the player as a whole. A console
            // game's slot holds one journey through one game; here each story
            // is its own game, and someone replaying chapter one of a favourite
            // should not have to give up their place in something else.
            $table->uuid('story_id');

            // 1, 2, 3 — shown as "Slot 1". Small and per story, because the
            // point is a handful of parallel runs, not unlimited bookkeeping.
            $table->unsignedSmallInteger('number');

            $table->string('label', 60)->nullable();

            // Which release this run is being played against. A slot started on
            // version 3 stays on version 3 until the player chooses otherwise:
            // swapping the content under a run in progress would move the goal
            // posts mid-journey and invalidate the times already set.
            $table->uuid('release_id')->nullable();

            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            $table->foreign('player_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->foreign('release_id')->references('id')->on('releases')->nullOnDelete();
            $table->unique(['player_id', 'story_id', 'number']);
            $table->index(['player_id', 'story_id']);
        });

        Schema::table('level_completions', static function (Blueprint $table): void {
            // Progress now belongs to a run, not to a player. Without this a
            // second playthrough would arrive already finished, which is not a
            // second playthrough.
            //
            // Nullable so the rows that exist keep meaning what they meant:
            // progress made before slots existed, belonging to the player
            // rather than to any particular run.
            $table->uuid('slot_id')->nullable();

            $table->foreign('slot_id')->references('id')->on('save_slots')->cascadeOnDelete();

            // The unique key still reads (user_id, level_id) and deliberately
            // does not include the slot yet.
            //
            // Two reasons, and the second is the one that bites. Nothing writes
            // a slot id yet, so widening the key now would only describe a
            // state the code cannot reach. And in Postgres a unique index over
            // a nullable column does not constrain the rows where it is null —
            // so (user_id, slot_id, level_id) would quietly stop enforcing one
            // completion per level for every row written today.
            //
            // The key changes in the same commit that starts writing slots,
            // where it will need a partial index for the slot-less rows.
        });
    }

    public function down(): void
    {
        Schema::table('level_completions', static function (Blueprint $table): void {
            $table->dropForeign(['slot_id']);
            $table->dropUnique(['user_id', 'slot_id', 'level_id']);
            $table->dropColumn('slot_id');
            $table->unique(['user_id', 'level_id']);
        });

        Schema::dropIfExists('save_slots');

        Schema::table('stories', static function (Blueprint $table): void {
            $table->dropIndex(['canonical_since']);
            $table->dropColumn('canonical_since');
        });
    }
};
