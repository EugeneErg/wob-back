<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('releases', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('story_id');

            // Per story, starting at 1. What players call "version 3".
            $table->unsignedInteger('number');

            // The frozen content. Not a pointer at the story's live chapters
            // and levels: those change the moment the author saves again, and
            // every vote and record attached to this release would silently
            // start describing different content.
            $table->jsonb('content');

            $table->char('content_hash', 8);

            // What this release was cut from, for carrying votes forward and
            // for showing an author what changed between their own versions.
            $table->uuid('previous_release_id')->nullable();

            // Nobody but the author may play it until the author has finished
            // it themselves. A story its own creator cannot complete is not
            // ready for strangers.
            $table->timestamp('author_cleared_at')->nullable();

            $table->timestamps();

            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->unique(['story_id', 'number']);
            $table->index('content_hash');
        });

        // Added after the table exists rather than inside it: a self-reference
        // needs the primary key to already be in place, and Postgres will not
        // accept the constraint while the table is still being defined.
        Schema::table('releases', static function (Blueprint $table): void {
            $table->foreign('previous_release_id')->references('id')->on('releases')->nullOnDelete();
        });

        Schema::table('stories', static function (Blueprint $table): void {
            // Canon is a pointer at one release, not a flag on the story. A
            // story can have a canonical version 3 while its author is already
            // publishing 4 and 5 — those are playable, they gather their own
            // votes, and until one of them clears the bar on its own merits the
            // crown stays where it is. Demotion never happens; the crown simply
            // fails to move.
            $table->uuid('canonical_release_id')->nullable();

            // A fork keeps a line home. Not decoration: it is what makes the
            // fork's untouched content resolvable at all, and what a pull
            // request is opened against.
            $table->uuid('forked_from_story_id')->nullable();
            $table->uuid('forked_from_release_id')->nullable();

            $table->foreign('canonical_release_id')->references('id')->on('releases')->nullOnDelete();
            $table->foreign('forked_from_story_id')->references('id')->on('stories')->nullOnDelete();
            $table->foreign('forked_from_release_id')->references('id')->on('releases')->nullOnDelete();
        });

        // A fork stores only what its editor actually touched. Everything else
        // has no row here and is read from the base release — that is the whole
        // copy-on-write, and it is why forking a fifty-level story to fix one
        // typo costs one row rather than fifty.
        Schema::create('fork_overrides', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('story_id');

            // 'level' or 'chapter'. The public id stays the same as the base's:
            // a public id names a level, not a row, which is what lets an
            // untouched chapter go on pointing at ids that resolve to a mix of
            // the fork's versions and the base's.
            $table->string('kind', 16);
            $table->string('public_id', 64);

            // Null content is a tombstone. Absence already means "not touched,
            // go look at the base", so a deletion needs to say so out loud or
            // it would resurrect on the next read.
            $table->jsonb('content')->nullable();

            $table->timestamps();

            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->unique(['story_id', 'kind', 'public_id']);
        });

        Schema::create('edit_sessions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('editor_id');
            $table->uuid('base_release_id');
            $table->uuid('base_story_id');

            // Null until the first real change. Opening someone's release to
            // look around creates a session and nothing else; the fork is born
            // on the first write.
            $table->uuid('fork_story_id')->nullable();

            $table->timestamps();

            $table->foreign('editor_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('base_release_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('base_story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->foreign('fork_story_id')->references('id')->on('stories')->nullOnDelete();

            // One session per editor per base release: reopening what you are
            // already editing resumes it instead of starting a second fork.
            $table->unique(['editor_id', 'base_release_id']);
        });

        Schema::create('pull_requests', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('target_story_id');
            $table->uuid('base_release_id');
            $table->uuid('fork_story_id');
            $table->uuid('author_id');
            $table->string('title', 200);

            // open | accepted | rejected | withdrawn. Withdrawn is kept apart
            // from rejected on purpose: "the author said no" and "I changed my
            // mind" are different history, and a contributor should not carry a
            // rejection they never received.
            $table->string('state', 16)->default('open');

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('target_story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->foreign('base_release_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('fork_story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['target_story_id', 'state']);
        });

        Schema::create('votes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('release_id');

            // The level as the player knows it, not a row id: the same public
            // id appears in every release that contains that level, which is
            // exactly what lets votes be carried forward.
            $table->string('level_public_id', 64);

            $table->uuid('voter_id');
            $table->unsignedTinyInteger('rating');

            // True for votes inherited from a previous release rather than cast
            // here. A boolean thrown away at write time cannot be recovered by
            // a query, and moderation will want to ask.
            $table->boolean('carried_over')->default(false);

            $table->timestamps();

            $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('voter_id')->references('id')->on('users')->cascadeOnDelete();

            // One opinion per player per level per release.
            $table->unique(['release_id', 'level_public_id', 'voter_id']);
            $table->index(['release_id', 'level_public_id']);
        });

        Schema::create('speedrun_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('release_id');
            $table->uuid('runner_id');

            // 'level' | 'chapter' | 'story' — the three things a run can be
            // against, each with its own table of times.
            $table->string('scope', 16);

            // Null for a whole-story run; otherwise the level or chapter raced.
            $table->string('target_public_id', 64)->nullable();

            // 'any' or 'hundred'. Two different contests, never one table.
            $table->string('category', 16);

            // Ticks, not seconds. The simulation is fixed-rate, so a tick count
            // is the same number on a phone and on a workstation — which is the
            // only reason these times are comparable at all.
            $table->unsignedInteger('ticks');

            // The input log. Times are not taken on trust: the server can
            // re-run this through the same physics and check the outcome, which
            // is why runs are stored as what the player did rather than as a
            // number they reported.
            $table->jsonb('input');
            $table->unsignedBigInteger('seed');

            $table->string('rules_version', 32);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('runner_id')->references('id')->on('users')->cascadeOnDelete();

            // The shape every leaderboard query has: this release, this scope,
            // this target, this category, ordered by time.
            $table->index(['release_id', 'scope', 'target_public_id', 'category', 'ticks']);
        });

        // Who has finished how much of which release. The quorum for canon is
        // counted from this, and it is kept apart from level_completions
        // because that table is about a player's own progress through the live
        // library, while this is evidence about a frozen release.
        Schema::create('release_completions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('release_id');
            $table->uuid('player_id');

            // The player's own route, not the whole story. A branching story
            // would otherwise be unable to reach quorum: someone who finished
            // one branch in full has covered every level of their route and
            // only half the story's levels.
            $table->unsignedInteger('levels_finished');
            $table->unsignedInteger('levels_on_route');

            $table->timestamps();

            $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('player_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['release_id', 'player_id']);
            $table->index('release_id');
        });
    }

    public function down(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->dropForeign(['canonical_release_id']);
            $table->dropForeign(['forked_from_story_id']);
            $table->dropForeign(['forked_from_release_id']);
            $table->dropColumn(['canonical_release_id', 'forked_from_story_id', 'forked_from_release_id']);
        });

        Schema::dropIfExists('release_completions');
        Schema::dropIfExists('speedrun_records');
        Schema::dropIfExists('votes');
        Schema::dropIfExists('pull_requests');
        Schema::dropIfExists('edit_sessions');
        Schema::dropIfExists('fork_overrides');
        Schema::dropIfExists('releases');
    }
};
