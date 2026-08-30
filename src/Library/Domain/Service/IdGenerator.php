<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Service;

/**
 * Mints a fresh client-style id when an imported one is already taken.
 *
 * A port rather than a static helper, because import is the one place the
 * server invents ids, and a test that cannot predict them cannot assert what
 * the reference rewriting did. The production implementation is random; the
 * test one counts.
 */
interface IdGenerator
{
    /** @param string $prefix the family prefix the editor uses: story, ch, lvl, as */
    public function next(string $prefix): string;
}
