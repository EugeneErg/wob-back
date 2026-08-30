<?php

declare(strict_types=1);

namespace Wob\Progress\Application\Listener;

use Wob\Library\Domain\Event\LevelsDiscarded;
use Wob\Library\Domain\Event\StoryDeleted;

/**
 * Completions of levels that no longer exist are dead weight.
 *
 * Today the foreign key already cascades, so this listener has nothing to do —
 * and it is written anyway, because the cascade is an accident of both contexts
 * happening to share a database. The moment Progress moves to its own store the
 * rule has to live somewhere, and it should be here, where it can be read.
 */
final readonly class ForgetProgressForDiscardedLevels
{
    public function handle(StoryDeleted|LevelsDiscarded $event): void
    {
        // Intentionally empty while the FK cascade covers it. Left in place so
        // the subscription, and the reason for it, are visible.
    }
}
