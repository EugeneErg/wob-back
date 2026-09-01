<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Progress belongs to a release, not to the author's draft.
     *
     * It was keyed on levels.id — a row in the author's live draft — with a
     * cascading delete. So an author removing a level from their draft wiped
     * that level's progress for everyone playing a FROZEN release in which the
     * level is still there, and always will be. The whole promise of a release
     * is that it does not change; progress against it was quietly hostage to
     * edits somewhere else entirely.
     *
     * A public id and the slot's release identify the level being played. The
     * public id names a level rather than a row, which is exactly why it
     * survives the author rewriting their draft around it.
     */
    public function up(): void
    {
        Schema::table('level_completions', static function (Blueprint $table): void {
            $table->string('level_public_id', 64)->nullable();
        });

        // Carry across what is already recorded, while the old column still
        // says what it pointed at.
        DB::statement(
            'UPDATE level_completions
             SET level_public_id = levels.public_id
             FROM levels
             WHERE levels.id = level_completions.level_id',
        );

        // Anything that cannot be resolved is a row about a level that no
        // longer exists anywhere — exactly what this change is meant to stop
        // producing, and nothing worth keeping.
        DB::table('level_completions')->whereNull('level_public_id')->delete();

        DB::statement('DROP INDEX IF EXISTS level_completions_in_slot');
        DB::statement('DROP INDEX IF EXISTS level_completions_no_slot');

        Schema::table('level_completions', static function (Blueprint $table): void {
            $table->dropForeign(['level_id']);
            $table->dropColumn('level_id');
            $table->string('level_public_id', 64)->nullable(false)->change();
        });

        // Same two partial indexes as before, on the new key. Postgres does not
        // constrain rows where an indexed column is null, so the slot-less rows
        // need their own.
        DB::statement(
            'CREATE UNIQUE INDEX level_completions_in_slot
             ON level_completions (user_id, slot_id, level_public_id)
             WHERE slot_id IS NOT NULL',
        );

        DB::statement(
            'CREATE UNIQUE INDEX level_completions_no_slot
             ON level_completions (user_id, level_public_id)
             WHERE slot_id IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS level_completions_in_slot');
        DB::statement('DROP INDEX IF EXISTS level_completions_no_slot');

        Schema::table('level_completions', static function (Blueprint $table): void {
            $table->uuid('level_id')->nullable();
        });

        DB::statement(
            'UPDATE level_completions
             SET level_id = levels.id
             FROM levels
             WHERE levels.public_id = level_completions.level_public_id',
        );

        DB::table('level_completions')->whereNull('level_id')->delete();

        Schema::table('level_completions', static function (Blueprint $table): void {
            $table->dropColumn('level_public_id');
            $table->uuid('level_id')->nullable(false)->change();
            $table->foreign('level_id')->references('id')->on('levels')->cascadeOnDelete();
        });
    }
};
