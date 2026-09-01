<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * A vote now carries a weight instead of being thrown away.
     *
     * The first design discarded a fraction of the votes when a level changed —
     * edit it by 30%, lose 30% of the opinions, chosen by a deterministic
     * sample. It worked, and it was wrong in a way that only shows up from the
     * voter's side: their opinion either survived intact or vanished, decided
     * by a hash of their own id. Nothing they could see, nothing they could
     * change.
     *
     * Weight says the same thing without throwing anything out. An edited level
     * makes every old opinion count for less, in proportion to how much it
     * changed, and the vote stays where it is. And it heals: someone who plays
     * the new version and rates it again is back to full weight, because they
     * have now actually seen what they are rating.
     */
    public function up(): void
    {
        Schema::table('votes', static function (Blueprint $table): void {
            // 1.0 is an opinion about content this voter actually played.
            // Anything less is an opinion carried forward across an edit.
            $table->decimal('weight', 5, 4)->default(1.0);
        });
    }

    public function down(): void
    {
        Schema::table('votes', static function (Blueprint $table): void {
            $table->dropColumn('weight');
        });
    }
};
