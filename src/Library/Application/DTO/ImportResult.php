<?php

declare(strict_types=1);

namespace Wob\Library\Application\DTO;

/**
 * What the import did.
 *
 * The id map is returned, not merely kept, because the client is still holding
 * the file it just sent and its ids may no longer be the ids the content lives
 * under. Without the map, the editor would have to reload the whole library to
 * find out what became of the story it imported.
 *
 * Warnings are kept separate from failure on purpose. A file missing one level
 * is still worth importing, and saying nothing about the dropped node would
 * leave the author wondering where the level went.
 */
final readonly class ImportResult
{
    /**
     * @param list<array{id: string, title: string}> $stories
     * @param array<string, string>                  $idMap
     * @param list<string>                           $warnings
     */
    public function __construct(
        public array $stories,
        public array $idMap,
        public array $warnings,
    ) {
    }
}
