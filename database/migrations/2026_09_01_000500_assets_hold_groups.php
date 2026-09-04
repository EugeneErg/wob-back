<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An asset holds a group of entities, not one.
 *
 * The things an author actually wants to reuse are rarely a single entity — a
 * motor with the arm it turns, a hazard with the terrain around it — and saving
 * them one at a time threw away the arrangement, which was the point.
 *
 * Every existing asset becomes a group of one, keeping its id, its title and
 * its data byte for byte. The entity inside needs an id of its own, which it
 * never had: the asset's own public id is reused, so it is stable across
 * re-runs and recognisable when it lands in a level.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('assets', static function (Blueprint $table): void {
            $table->jsonb('entities')->nullable();
        });

        foreach (DB::table('assets')->orderBy('id')->get() as $asset) {
            DB::table('assets')->where('id', $asset->id)->update([
                'entities' => json_encode([[
                    'id' => $asset->public_id,
                    'type' => $asset->type,
                    'data' => json_decode((string) $asset->data) ?? new stdClass(),
                ]]),
            ]);
        }

        Schema::table('assets', static function (Blueprint $table): void {
            $table->jsonb('entities')->nullable(false)->change();
            $table->dropColumn(['type', 'data']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', static function (Blueprint $table): void {
            $table->string('type', 64)->default('');
            $table->jsonb('data')->nullable();
            $table->dropColumn('entities');
        });
    }
};
