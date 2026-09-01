<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Now that slots are actually written, the unique key has to account for
     * them — and doing it needs two indexes rather than one.
     *
     * Postgres does not constrain rows where an indexed column is null, so a
     * single unique over (user_id, slot_id, level_id) would enforce nothing at
     * all for progress that belongs to no slot. Two partial indexes say the
     * thing that is actually true: one completion per level per run, and one
     * completion per level for the runless progress that predates slots.
     */
    public function up(): void
    {
        Schema::table('level_completions', static function ($table): void {
            $table->dropUnique(['user_id', 'level_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX level_completions_in_slot
             ON level_completions (user_id, slot_id, level_id)
             WHERE slot_id IS NOT NULL',
        );

        DB::statement(
            'CREATE UNIQUE INDEX level_completions_no_slot
             ON level_completions (user_id, level_id)
             WHERE slot_id IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS level_completions_in_slot');
        DB::statement('DROP INDEX IF EXISTS level_completions_no_slot');

        Schema::table('level_completions', static function ($table): void {
            $table->unique(['user_id', 'level_id']);
        });
    }
};
