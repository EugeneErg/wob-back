<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chapters get a place on the story board.
 *
 * A story is laid out on one canvas now: each chapter is an area on it, and the
 * points inside a chapter stay where they were — their x and y remain
 * percentages of their own chapter, not of the board. That is deliberate and it
 * is why this migration touches no point at all: dragging a chapter moves
 * everything inside it for free, and a percentage that meant something
 * yesterday means the same thing today.
 *
 * Existing chapters are laid out left to right in their current order, which is
 * the order they were shown in before. Nothing jumps.
 */
return new class () extends Migration {
    private const W = 420;
    private const H = 300;
    private const GAP = 80;

    public function up(): void
    {
        Schema::table('chapters', static function (Blueprint $table): void {
            $table->float('canvas_x')->default(0);
            $table->float('canvas_y')->default(0);
            $table->float('canvas_w')->default(self::W);
            $table->float('canvas_h')->default(self::H);
        });

        foreach (DB::table('stories')->orderBy('id')->get() as $story) {
            $column = 0;

            foreach (DB::table('chapters')->where('story_id', $story->id)->orderBy('position')->get() as $chapter) {
                DB::table('chapters')->where('id', $chapter->id)->update([
                    'canvas_x' => $column * (self::W + self::GAP),
                    'canvas_y' => 0,
                ]);

                $column++;
            }
        }
    }

    public function down(): void
    {
        Schema::table('chapters', static function (Blueprint $table): void {
            $table->dropColumn(['canvas_x', 'canvas_y', 'canvas_w', 'canvas_h']);
        });
    }
};
