<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create("level_completions", static function (Blueprint $table): void {
            $table->uuid("id")->primary();
            $table->uuid("user_id");
            $table->uuid("level_id");

            // Facts, not conclusions. Whether a level is UNLOCKED is a question
            // about a chapter graph plus these facts, and it is answered where
            // the graph is: on the client today, and in a query later. Storing a
            // computed "unlocked" flag would go stale the moment an author
            // redraws a path.
            $table->timestamp("first_completed_at");
            $table->timestamp("last_completed_at");
            $table->unsignedInteger("completions")->default(1);

            $table->foreign("user_id")->references("id")->on("users")->cascadeOnDelete();
            $table->foreign("level_id")->references("id")->on("levels")->cascadeOnDelete();
            $table->unique(["user_id", "level_id"]);
            $table->index("user_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("level_completions");
    }
};
