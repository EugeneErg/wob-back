<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create("users", static function (Blueprint $table): void {
            $table->uuid("id")->primary();

            // Google calls it "sub": stable for the lifetime of the account and
            // the only field safe to identify by. Email is not — people change
            // it, and Google recycles nothing but promises nothing either.
            $table->string("google_sub", 255)->unique();

            $table->string("email", 320);
            $table->string("display_name", 200);
            $table->string("avatar_url", 1000)->nullable();
            $table->timestamp("last_seen_at")->nullable();
            $table->timestamps();

            $table->index("email");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("users");
    }
};
