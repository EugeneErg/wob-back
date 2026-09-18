<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * У истории появляется задник — картинка позади её глав.
 *
 * Обложка у истории уже была, и заманчиво было обойтись ею. Не выходит: обложку
 * растягивают под карточку в списке, и где что на ней — всё равно. А на заднике
 * главы стоят, и стоят в его же единицах доски. В оригинале это планета, по
 * ободу которой лежат пять островов; промах на десяток единиц виден сразу —
 * остров повисает в пустоте.
 *
 * Поэтому рамка идёт вместе с картинкой, теми же четырьмя числами, что и у
 * главы. Одно без другого бессмысленно: картинка без места — вопрос «куда её
 * класть», место без картинки — пустая рамка.
 *
 * ПОЧЕМУ ОДНА КАРТИНКА, А НЕ СЛОИ
 *
 * В оригинале задник карты мира нарисован двумя десятками слоёв, и заголовок
 * среди них — три отдельных куска: WORLD, OF и GOO, каждый со своим углом,
 * поставленные вокруг планеты. Завести «слои экрана» значило бы принести к себе
 * устройство чужого движка. Конвертер сводит их в один PNG при переносе, и
 * наружу выходит то, что видно.
 *
 * Оформление, как обложка и заставка: в содержимое истории задник не входит и
 * на её хеш не влияет. Перерисовал автор планету — играется то же самое.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            // Ссылкой на носитель, как обложка истории и картинка главы.
            $table->string('backdrop', 2000)->default('');
            $table->float('backdrop_x')->default(0);
            $table->float('backdrop_y')->default(0);
            $table->float('backdrop_w')->default(0);
            $table->float('backdrop_h')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->dropColumn(['backdrop', 'backdrop_x', 'backdrop_y', 'backdrop_w', 'backdrop_h']);
        });
    }
};
