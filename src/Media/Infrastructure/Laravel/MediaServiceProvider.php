<?php

declare(strict_types=1);

namespace Wob\Media\Infrastructure\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Wob\Media\Domain\Port\MediaStore;
use Wob\Media\Domain\Repository\MediaRepository;
use Wob\Media\Infrastructure\Persistence\Database\DatabaseMediaRepository;
use Wob\Media\Infrastructure\Storage\DiskMediaStore;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaRepository::class, static fn (Container $c): MediaRepository => new DatabaseMediaRepository(
            $c->make('db')->connection(),
        ));

        // The one line that decides where uploads live. Pointing MEDIA_DISK at
        // an s3 disk is the whole of the move to object storage — nothing above
        // this line knows which it got.
        $this->app->singleton(MediaStore::class, static fn (Container $c): MediaStore => new DiskMediaStore(
            Storage::disk((string) config('media.disk', 'local')),
        ));
    }
}
