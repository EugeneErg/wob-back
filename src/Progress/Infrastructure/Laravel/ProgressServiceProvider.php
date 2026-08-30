<?php

declare(strict_types=1);

namespace Wob\Progress\Infrastructure\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Wob\Library\Domain\Event\LevelsDiscarded;
use Wob\Library\Domain\Event\StoryDeleted;
use Wob\Progress\Application\Listener\ForgetProgressForDiscardedLevels;
use Wob\Progress\Domain\Repository\ProgressRepository;
use Wob\Progress\Infrastructure\Persistence\Database\DatabaseProgressRepository;

final class ProgressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProgressRepository::class, static fn (Container $c): ProgressRepository => new DatabaseProgressRepository(
            $c->make("db")->connection(),
        ));
    }

    public function boot(): void
    {
        // Progress subscribes to Library; Library has never heard of Progress.
        // The arrow points one way, which is what lets either be moved out later.
        $this->app->make("events")->listen(StoryDeleted::class, ForgetProgressForDiscardedLevels::class);
        $this->app->make("events")->listen(LevelsDiscarded::class, ForgetProgressForDiscardedLevels::class);
    }
}
