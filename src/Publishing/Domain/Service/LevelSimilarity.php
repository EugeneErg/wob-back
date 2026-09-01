<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use Wob\Publishing\Domain\ValueObject\Similarity;

/**
 * How different two versions of the same level are.
 *
 * An interface because the domain genuinely needs the idea of "how much did
 * this change" — VoteCarryOver depends on it — while the specific algorithm
 * (edit distance over the canonical JSON, today) is an implementation choice
 * infrastructure is free to swap.
 */
interface LevelSimilarity
{
    public function between(object $before, object $after): Similarity;
}
