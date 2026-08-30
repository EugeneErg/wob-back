<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create("assets", static function (Blueprint $table): void {
            $table->uuid("id")->primary();
            $table->uuid("owner_id");
            $table->string("public_id", 64);
            $table->string("type", 64);
            $table->string("title", 200);
            $table->jsonb("data");
            $table->timestamps();

            $table->foreign("owner_id")->references("id")->on("users")->cascadeOnDelete();
            $table->unique(["owner_id", "public_id"]);
        });

        Schema::create("stories", static function (Blueprint $table): void {
            $table->uuid("id")->primary();
            $table->uuid("owner_id");

            // The id the editor minted. Unique per author, not globally: two
            // people may both import the same shared story and each keeps the
            // ids the file came with.
            $table->string("public_id", 64);

            $table->string("title", 200);
            $table->string("cover", 2000);
            $table->jsonb("hot")->default("[]");

            // Fingerprint of the content, recomputed on every write. Cheap to
            // store, and it saves the client from downloading a story it already
            // has — and later decides whether a record still counts.
            $table->char("content_hash", 8);

            // Optimistic lock. The editor works offline, so two devices can hold
            // the same story; a write carries the version it was based on.
            $table->unsignedBigInteger("version")->default(1);

            $table->timestamps();

            $table->foreign("owner_id")->references("id")->on("users")->cascadeOnDelete();
            $table->unique(["owner_id", "public_id"]);
        });

        Schema::create("chapters", static function (Blueprint $table): void {
            $table->uuid("id")->primary();
            $table->uuid("story_id");
            $table->string("public_id", 64);
            $table->string("title", 200);
            $table->string("image", 2000);

            // The map is a graph, and it is only ever read and written whole,
            // with the chapter. A join table would buy queries nobody makes and
            // cost a transaction on every drag of a node.
            $table->jsonb("nodes")->default("[]");
            $table->jsonb("edges")->default("[]");
            $table->jsonb("hot")->default("[]");

            // Chapter order is unlock order, so it is data, not a display whim.
            $table->unsignedInteger("position");
            $table->char("content_hash", 8);
            $table->timestamps();

            $table->foreign("story_id")->references("id")->on("stories")->cascadeOnDelete();
            $table->unique(["story_id", "public_id"]);
            $table->index(["story_id", "position"]);
        });

        Schema::create("levels", static function (Blueprint $table): void {
            $table->uuid("id")->primary();

            // Levels hang off the story, not the chapter: one level may be
            // pinned to two chapter maps at once.
            $table->uuid("story_id");

            $table->string("public_id", 64);
            $table->string("name", 200);
            $table->unsignedInteger("width");
            $table->unsignedInteger("height");
            $table->jsonb("gravity");
            $table->unsignedInteger("goal");

            // Opaque on purpose. The server does not know what a motor is, and
            // the day an entity type ships that this deployment has never heard
            // of, it still has to store the level and hand it back untouched.
            $table->jsonb("entities");

            $table->jsonb("hot")->default("[]");
            $table->char("content_hash", 8);
            $table->timestamps();

            $table->foreign("story_id")->references("id")->on("stories")->cascadeOnDelete();
            $table->unique(["story_id", "public_id"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("levels");
        Schema::dropIfExists("chapters");
        Schema::dropIfExists("stories");
        Schema::dropIfExists("assets");
    }
};
