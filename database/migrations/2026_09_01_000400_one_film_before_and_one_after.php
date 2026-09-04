<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One film before the story, one after each level, and none anywhere else.
 *
 * Films were tried in three places at once. A story intro and a chapter intro
 * both play before anything is touched, so a player opening a story met two
 * waits in a row; and a level intro and a point outro are the same beat around
 * one level, counted twice.
 *
 * What is left cannot double up. The story's plays once per playthrough, before
 * the first click. Each point's plays after its level is won, and it belongs to
 * the point rather than the level, so a level met twice can end two ways. The
 * chapter keeps only its map backdrop, which is a picture, not a wait.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->string('intro', 2000)->default('');
        });

        Schema::table('chapters', static function (Blueprint $table): void {
            $table->dropColumn('intro');
        });

        Schema::table('levels', static function (Blueprint $table): void {
            $table->dropColumn('intro');
        });
    }

    public function down(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->dropColumn('intro');
        });

        Schema::table('chapters', static function (Blueprint $table): void {
            $table->string('intro', 2000)->default('');
        });

        Schema::table('levels', static function (Blueprint $table): void {
            $table->string('intro', 2000)->default('');
        });
    }
};
