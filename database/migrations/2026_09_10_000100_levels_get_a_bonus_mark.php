<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дополнительная мера у уровня: отличие сверх прохождения.
 *
 * Считать ничего не надо — время и ходы снимаются с каждой попытки и так,
 * наравне с тиками. Не хватало только порога: «а надо было за шестнадцать
 * секунд». Он и добавляется.
 *
 * Одной колонкой, а не тремя, потому что мера одна на уровень и у неё три
 * части: чем меряем, сколько и обязательно ли. Три колонки описывали бы то же
 * самое, но позволяли бы задать порог без меры и меру без порога.
 *
 * Пусто — отличия у уровня нет, и это обычный случай: в чужом наборе мера
 * стоит у сорока шести уровней из сорока семи, но у нас своих уровней она не
 * будет почти нигде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table("levels", static function (Blueprint $table): void {
            $table->jsonb("extra")->nullable();
        });
    }

    public function down(): void
    {
        Schema::table("levels", static function (Blueprint $table): void {
            $table->dropColumn("extra");
        });
    }
};
