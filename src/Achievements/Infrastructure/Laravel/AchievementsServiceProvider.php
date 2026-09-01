<?php

declare(strict_types=1);

namespace Wob\Achievements\Infrastructure\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Wob\Achievements\Domain\Repository\AwardRepository;
use Wob\Achievements\Domain\Service\AchievementCatalog;
use Wob\Achievements\Infrastructure\Persistence\Database\DatabaseAwardRepository;

final class AchievementsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AchievementCatalog::class);

        $this->app->singleton(
            AwardRepository::class,
            static fn (Container $c): AwardRepository => new DatabaseAwardRepository(
                $c->make('db')->connection(),
            ),
        );
    }
}
