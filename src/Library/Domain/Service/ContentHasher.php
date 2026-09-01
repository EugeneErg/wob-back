<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Service;

/**
 * Turns a piece of content into the fingerprint the client would compute for it.
 *
 * An interface in the domain because the domain genuinely depends on the idea of
 * a content fingerprint — a story knows its own version — while the byte-level
 * details of FNV-1a belong to infrastructure. Swapping the algorithm later (the
 * client comment already admits FNV is not collision-proof and only guards
 * against accident) then touches one class.
 *
 */
interface ContentHasher
{
    /**
     * @param array<string, mixed>|object $content
     *
     * @return string eight lowercase hex characters
     */
    public function hash(array|object $content): string;

    /**
     * The canonical string a hash would be taken of.
     *
     * Part of the contract rather than an implementation detail, because
     * normalising content is useful on its own: comparing two snapshots for
     * equality, or measuring how far apart two versions of a level are, both
     * need the same "key order and number formatting do not count" rule that
     * the hash relies on. Re-deriving that rule elsewhere would be a second
     * definition of what content IS, free to drift from the first.
     */
    public function canonicalise(mixed $value): string;
}
