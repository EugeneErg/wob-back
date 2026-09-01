<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Command;

use stdClass;

/**
 * One change to someone else's released story.
 *
 * Content of null is a deletion, and it has to be said out loud rather than
 * implied by absence: in a fork, "no row" already means "not touched, read the
 * base", so an unmarked delete would come back on the next read.
 */
final readonly class EditForeignStory
{
    public function __construct(
        public string $editorId,
        public string $baseReleaseId,
        /** 'level' or 'chapter' — the granularity of what actually changed. */
        public string $kind,
        public string $publicId,
        public ?stdClass $content,
    ) {
    }
}
