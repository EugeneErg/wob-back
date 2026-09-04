<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a story begins, and the pictures and films around it.
 *
 * A story has no film of its own. It was going to, until it became clear that a
 * new player would sit through a story intro and then a chapter intro before
 * touching anything — two waits stacked before the first click. The chapter's
 * is the one that survives, because every chapter has one and the first
 * chapter's intro opens the story anyway.
 *
 * The media columns are plain strings rather than foreign keys into `media`,
 * matching `stories.cover` and `chapters.image`, which have always held either
 * a CSS gradient or a URL. An upload simply produces one more kind of value the
 * same column can hold — "/api/media/{uuid}" — so the client keeps one code
 * path for "what does this look like" instead of two.
 *
 * A foreign key would buy referential integrity and cost the ability to say
 * "no cover" with a gradient, which is what most stories will do.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            // Which chapter a player starts on.
            //
            // Nullable, and not because it is optional: a story with no
            // chapters yet has no answer, and the first chapter created will
            // claim the slot. A story offered to players without one is a
            // story with no way in, and that is the aggregate's business to
            // refuse, not the schema's.
            $table->string('start_chapter_id', 64)->nullable();
        });

        Schema::table('chapters', static function (Blueprint $table): void {
            $table->string('intro', 2000)->default('');

            // The picture the level map is drawn on top of. Distinct from
            // `image`, which is the chapter's card in the list: one is seen
            // before you go in, the other only once you are inside.
            $table->string('map', 2000)->default('');
        });

        Schema::table('levels', static function (Blueprint $table): void {
            $table->string('image', 2000)->default('');
            $table->string('intro', 2000)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->dropColumn(['start_chapter_id']);
        });

        Schema::table('chapters', static function (Blueprint $table): void {
            $table->dropColumn(['intro', 'map']);
        });

        Schema::table('levels', static function (Blueprint $table): void {
            $table->dropColumn(['image', 'intro']);
        });
    }
};
