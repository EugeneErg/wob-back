<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Id;

use Random\RandomException;
use Wob\Library\Domain\Service\IdGenerator;

/**
 * The same shape the editor produces — `lvl-k3f9a2` — so an id minted here is
 * indistinguishable from one minted in the browser. That matters because the
 * two live in the same namespace and neither side should be able to tell, or
 * care, where a given id was born.
 */
final class RandomIdGenerator implements IdGenerator
{
    public function next(string $prefix): string
    {
        try {
            $suffix = substr(bin2hex(random_bytes(4)), 0, 7);
        } catch (RandomException) {
            // Without a working random source, quietly falling back to something
            // guessable would be worse than stopping: ids are how imports avoid
            // colliding with content that is already there.
            throw new \RuntimeException('No source of randomness available to mint an id');
        }

        return $prefix . '-' . $suffix;
    }
}
