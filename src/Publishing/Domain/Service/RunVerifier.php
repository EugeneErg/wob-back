<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use Wob\Publishing\Domain\Model\SpeedrunRecord;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

/**
 * Replays a run and reports what actually happened.
 *
 * A port, and this is the one place in the codebase where that word is earning
 * its keep. A run is a log of input, not a number, so checking a time means
 * playing it — and only the game's own solver can do that. A difference of one
 * particle gives a different outcome, so a second implementation in PHP would
 * be a second physics obliged to agree with the first to the last bit, and it
 * would not: every edit to the game would quietly drift the check away from
 * reality.
 *
 * So the check lives next to the game, in Node, and speaks HTTP. Behind this
 * interface, nothing in the application knows that a second language exists.
 */
interface RunVerifier
{
    public function verify(SpeedrunRecord $record, ContentSnapshot $content): VerificationResult;
}
