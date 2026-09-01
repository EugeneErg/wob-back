<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ReleaseRepository;

/**
 * Freezing a draft, and the history of what has been frozen.
 *
 * The handler behind this existed for a long time with no way to reach it —
 * every test called it directly, so nothing noticed that an author had no way
 * to publish anything at all.
 */
final readonly class ReleaseController
{
    public function __construct(
        private PublishReleaseHandler $publish,
        private ReleaseRepository $releases,
    ) {
    }

    public function store(Request $request, string $storyId): JsonResponse
    {
        $release = ($this->publish)(new PublishRelease(
            (string) $request->attributes->get('ownerId'),
            $storyId,
        ));

        return new JsonResponse($this->present($release), 201);
    }

    /**
     * Every version, newest first.
     *
     * Authors need the history and players need to know which version they are
     * looking at, so there is no "latest only" that would let either pretend
     * the rest do not exist.
     */
    public function index(string $storyId): JsonResponse
    {
        return new JsonResponse([
            'releases' => array_map(
                fn (Release $r): array => $this->present($r),
                $this->releases->ofStory(new StoryId($storyId)),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Release $release): array
    {
        return [
            'id' => $release->id->value,
            'number' => $release->number,
            'hash' => $release->contentHash,
            'releasedAt' => $release->releasedAt->format(DATE_ATOM),
            // Until the author has finished it themselves, nobody else can
            // play it — so this is the difference between published and
            // merely frozen.
            'openToOthers' => $release->isClearedByAuthor(),
            'levels' => count($release->content->levels),
            'chapters' => count($release->content->chapters),
        ];
    }
}
