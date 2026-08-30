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
}
