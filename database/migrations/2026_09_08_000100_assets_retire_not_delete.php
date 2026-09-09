<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An asset is never edited and never deleted; it can only go out of fashion.
 *
 * A level does not copy the asset it uses, it names it. That reference is only
 * safe because the asset behind it cannot change: resolve it today or in a
 * year, the level looks the same either way. Editing an asset would silently
 * rewrite every level that ever used it, including levels by other authors, and
 * including released ones whose records are tied to their content hash.
 *
 * So improving an asset means publishing a new one. What the old one gets
 * instead is retirement: hidden from the palette so nobody picks it again,
 * still resolvable so everything already built on it keeps working.
 *
 * Deletion is not offered at all. There is no way to know who is depending on
 * it — assets may be used by other authors — and a missing asset is not a case
 * to handle gracefully, it is a level that cannot be drawn.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('assets', static function (Blueprint $table): void {
            $table->timestampTz('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', static function (Blueprint $table): void {
            $table->dropColumn('retired_at');
        });
    }
};
