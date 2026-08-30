<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

use stdClass;

/**
 * Take in a story file exported from the game.
 *
 * The bundle arrives as a decoded stdClass rather than an array, for the same
 * reason level entities do: PHP cannot tell a decoded `{}` from a decoded `[]`,
 * and an entity whose data is an empty object would come back with a different
 * content hash than the one it left with. An import that silently changes the
 * fingerprint of a level is an import that invalidates every record on it.
 */
final readonly class ImportBundle
{
    public function __construct(public string $ownerId, public stdClass $bundle)
    {
    }
}
