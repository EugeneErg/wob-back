<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Wob\Library\Domain\Service\ContentHasher;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\EditSessionRepository;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\Repository\PullRequestRepository;
use Wob\Publishing\Domain\Repository\SaveSlotRepository;
use Wob\Publishing\Domain\Repository\SpeedrunRecordRepository;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Publishing\Application\Query\CatalogReadModel;
use Wob\Publishing\Domain\Service\CanonPolicy;
use Wob\Publishing\Domain\Service\ContentGate;
use Wob\Publishing\Domain\Service\LevelClearance;
use Wob\Publishing\Domain\Service\LevelSimilarity;
use Wob\Publishing\Domain\Service\RunVerifier;
use Wob\Publishing\Domain\Service\VoteCarryOver;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseCatalogReadModel;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseLevelClearance;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseReleaseCompletionRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseReleaseRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseEditSessionRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseForkFactory;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseForkOverrideRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabasePullRequestRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseSaveSlotRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseSpeedrunRecordRepository;
use Wob\Publishing\Infrastructure\Persistence\Database\DatabaseVoteRepository;
use Wob\Publishing\Infrastructure\Similarity\LevenshteinLevelSimilarity;
use Wob\Publishing\Infrastructure\Verification\HttpRunVerifier;

final class PublishingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([\Wob\Publishing\Presentation\Console\VerifyRuns::class]);
        }
    }

    public function register(): void
    {
        $this->app->singleton(CanonPolicy::class);

        // One port, one adapter. Everything above this line is unaware that the
        // physics lives in another language.
        $this->app->singleton(
            RunVerifier::class,
            static fn (Container $c): RunVerifier => new HttpRunVerifier(
                (string) config('wob.verifier.endpoint'),
                $c->make(\Psr\Http\Client\ClientInterface::class),
                $c->make(\Psr\Http\Message\RequestFactoryInterface::class),
                $c->make(\GuzzleHttp\Psr7\HttpFactory::class),
            ),
        );
        $this->app->singleton(ContentGate::class);
        $this->app->singleton(\Wob\Publishing\Domain\Service\RouteProgress::class);

        $this->app->singleton(
            CatalogReadModel::class,
            static fn (Container $c): CatalogReadModel => new DatabaseCatalogReadModel(
                $c->make('db')->connection(),
                $c->make(ContentGate::class),
            ),
        );

        $this->app->singleton(
            LevelSimilarity::class,
            static fn (Container $c): LevelSimilarity => new LevenshteinLevelSimilarity(
                $c->make(ContentHasher::class),
            ),
        );

        $this->app->singleton(
            VoteCarryOver::class,
            static fn (Container $c): VoteCarryOver => new VoteCarryOver($c->make(LevelSimilarity::class)),
        );

        $this->app->singleton(
            ReleaseRepository::class,
            static fn (Container $c): ReleaseRepository => new DatabaseReleaseRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            EditSessionRepository::class,
            static fn (Container $c): EditSessionRepository => new DatabaseEditSessionRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            ForkOverrideRepository::class,
            static fn (Container $c): ForkOverrideRepository => new DatabaseForkOverrideRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            PullRequestRepository::class,
            static fn (Container $c): PullRequestRepository => new DatabasePullRequestRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            \Wob\Publishing\Domain\Service\ForkFactory::class,
            static fn (Container $c): \Wob\Publishing\Domain\Service\ForkFactory => new DatabaseForkFactory(
                $c->make(\Wob\Library\Domain\Repository\StoryRepository::class),
                $c->make(ForkOverrideRepository::class),
                $c->make(\Wob\Library\Domain\Service\IdGenerator::class),
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            SaveSlotRepository::class,
            static fn (Container $c): SaveSlotRepository => new DatabaseSaveSlotRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            SpeedrunRecordRepository::class,
            static fn (Container $c): SpeedrunRecordRepository => new DatabaseSpeedrunRecordRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            VoteRepository::class,
            static fn (Container $c): VoteRepository => new DatabaseVoteRepository($c->make('db')->connection()),
        );

        $this->app->singleton(
            ReleaseCompletionRepository::class,
            static fn (Container $c): ReleaseCompletionRepository => new DatabaseReleaseCompletionRepository(
                $c->make('db')->connection(),
            ),
        );

        $this->app->singleton(
            LevelClearance::class,
            static fn (Container $c): LevelClearance => new DatabaseLevelClearance($c->make('db')->connection()),
        );
    }
}
