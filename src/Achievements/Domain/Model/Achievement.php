<?php

declare(strict_types=1);

namespace Wob\Achievements\Domain\Model;

/**
 * One thing worth being recognised for.
 *
 * A definition, not a record: this says what the achievement is and what it is
 * worth, and an Award is somebody having earned it. Definitions live in code
 * rather than in a table because a condition is logic — it has to be read,
 * argued about, and covered by a test, none of which a row supports.
 */
final readonly class Achievement
{
    public const SUBJECT_STORY = 'story';
    public const SUBJECT_LEVEL = 'level';
    public const SUBJECT_RELEASE = 'release';

    public function __construct(
        public string $code,
        public string $title,
        public string $description,
        public int $points,
        /**
         * What this is earned against, or null when it is about the player
         * rather than about any particular thing.
         *
         * It decides how often the achievement can land: one per story, or once
         * ever.
         */
        public ?string $subjectType = null,
    ) {
    }

    public function isRepeatable(): bool
    {
        return $this->subjectType !== null;
    }
}
