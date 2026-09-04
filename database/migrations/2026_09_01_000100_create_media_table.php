<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('media', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');

            // 'image' or 'video'. Kept as a short string rather than inferred
            // from the mime type on read: what a file is FOR decides where it
            // may be used — a cover slot must refuse a video — and that
            // decision should not depend on re-parsing 'video/quicktime'
            // correctly every time somebody asks.
            $table->string('kind', 16);

            $table->string('mime', 128);
            $table->unsignedBigInteger('bytes');

            // Where the bytes actually are, relative to the disk. The disk
            // itself is not recorded, on purpose: it is deployment
            // configuration, and writing 'local' into every row would make
            // moving to object storage a data migration instead of a config
            // change.
            $table->string('path', 512);

            // The name the author's file had. Shown back to them so a list of
            // uploads is recognisable; never used to build the stored path,
            // because a name that came from outside has no business deciding
            // where bytes land on a disk.
            $table->string('original_name', 255);

            $table->timestamp('created_at');

            // Everything is asked for by owner: "my uploads", newest first.
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
