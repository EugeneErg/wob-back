<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('awards', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');

            // Which achievement. A short stable code rather than a foreign key
            // to a table of definitions: what an achievement IS — its
            // condition, its tier, what it is worth — is logic, and logic
            // belongs in code where it can be read and tested rather than in
            // rows somebody edits at three in the morning.
            $table->string('code', 64);

            // What it was earned for: a story, a level, a release. Null for the
            // ones that are about the player rather than about a thing.
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 64)->nullable();

            // The points as they were at the moment of earning.
            //
            // Copied rather than looked up. If the value lived only in the
            // definition, rebalancing an achievement next year would silently
            // rewrite what everyone earned last year — a leaderboard that
            // changes retroactively for reasons no player can see is worse than
            // one that is slightly out of date.
            $table->unsignedInteger('points');

            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Earned once per thing. The subject is part of the key so that
            // "finished a story" can be earned for every story, while a
            // subjectless achievement can only ever land once.
            $table->unique(['user_id', 'code', 'subject_id']);
            $table->index(['user_id', 'awarded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awards');
    }
};
