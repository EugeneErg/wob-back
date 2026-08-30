<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Wob\Library\Application\Query\LibraryReadModel;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\Service\ContentHasher;
use Wob\Library\Domain\Service\IdGenerator;
use Wob\Library\Infrastructure\Hashing\Fnv1aContentHasher;
use Wob\Library\Infrastructure\Id\RandomIdGenerator;
use Wob\Library\Infrastructure\Persistence\Database\DatabaseAssetRepository;
use Wob\Library\Infrastructure\Persistence\Database\DatabaseLibraryReadModel;
use Wob\Library\Infrastructure\Persistence\Database\DatabaseStoryRepository;
use Wob\Library\Infrastructure\Persistence\Database\StoryMapper;

/**
 * One provider per bounded context, holding its own wiring.
 *
 * A single central provider listing every binding in the application would be
 * the first thing to make the contexts stop being separable: to move Library
 * into its own service you would have to unpick it from a file everybody edits.
 */
final class LibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContentHasher::class, Fnv1aContentHasher::class);
        $this->app->singleton(IdGenerator::class, RandomIdGenerator::class);
        $this->app->singleton(StoryMapper::class);

        $this->app->singleton(StoryRepository::class, static fn (Container $c): StoryRepository => new DatabaseStoryRepository(
            $c->make("db")->connection(),
            $c->make(StoryMapper::class),
            $c->make(ContentHasher::class),
        ));

        $this->app->singleton(AssetRepository::class, static fn (Container $c): AssetRepository => new DatabaseAssetRepository(
            $c->make("db")->connection(),
        ));

        $this->app->singleton(LibraryReadModel::class, static fn (Container $c): LibraryReadModel => new DatabaseLibraryReadModel(
            $c->make("db")->connection(),
        ));
    }
}
