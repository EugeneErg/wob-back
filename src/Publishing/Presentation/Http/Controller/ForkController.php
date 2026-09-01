<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Wob\Publishing\Application\Command\DecidePullRequest;
use Wob\Publishing\Application\Command\EditForeignStory;
use Wob\Publishing\Application\Command\OpenPullRequest;
use Wob\Publishing\Application\Handler\DecidePullRequestHandler;
use Wob\Publishing\Application\Handler\EditForeignStoryHandler;
use Wob\Publishing\Application\Handler\OpenPullRequestHandler;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\PullRequest;
use Wob\Publishing\Domain\Repository\PullRequestRepository;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Editing someone else's story, and offering the result back.
 */
final readonly class ForkController
{
    public function __construct(
        private EditForeignStoryHandler $edit,
        private OpenPullRequestHandler $open,
        private DecidePullRequestHandler $decide,
        private PullRequestRepository $pullRequests,
    ) {
    }

    /**
     * Change one level or chapter of a released story.
     *
     * The fork is created here if it does not exist — the first change is what
     * brings it into being, and looking around beforehand costs nothing.
     */
    public function edit(Request $request, string $releaseId): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:level,chapter'],
            'id' => ['required', 'string', 'max:64'],
        ]);

        // Read from the raw body rather than the validated array: Laravel hands
        // back associative arrays, and an entity whose data is an empty object
        // would arrive as an empty array — a difference the content hash can
        // see and PHP cannot.
        $body = $this->raw($request);
        $content = $body->content ?? null;

        if ($content !== null && !$content instanceof stdClass) {
            throw InvariantViolation::because('Content must be an object, or null to delete');
        }

        $fork = ($this->edit)(new EditForeignStory(
            (string) $request->attributes->get('ownerId'),
            $releaseId,
            $data['kind'],
            $data['id'],
            $content,
        ));

        return new JsonResponse(['forkStoryId' => $fork->value], 201);
    }

    public function propose(Request $request, string $forkStoryId): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);

        $pr = ($this->open)(new OpenPullRequest(
            (string) $request->attributes->get('ownerId'),
            $forkStoryId,
            $data['title'],
        ));

        return new JsonResponse($this->present($pr), 201);
    }

    public function index(Request $request, string $storyId): JsonResponse
    {
        $state = $request->query('state');

        return new JsonResponse([
            'pullRequests' => array_map(
                fn (PullRequest $pr): array => $this->present($pr),
                $this->pullRequests->forStory(new StoryId($storyId), is_string($state) ? $state : null),
            ),
        ]);
    }

    public function decide(Request $request, string $pullRequestId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:accept,reject,withdraw'],
        ]);

        $draft = ($this->decide)(new DecidePullRequest(
            (string) $request->attributes->get('ownerId'),
            $pullRequestId,
            $data['decision'],
        ));

        return new JsonResponse([
            'decision' => $data['decision'],
            // Accepting produces a new draft rather than touching the one the
            // author is working in — so the response says where it went, or
            // there would be no way to find it.
            'draftStoryId' => $draft?->value,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(PullRequest $pr): array
    {
        return [
            'id' => $pr->id->value,
            'title' => $pr->title,
            'state' => $pr->state(),
            'targetStoryId' => $pr->targetStoryId->value,
            'forkStoryId' => $pr->forkStoryId->value,
            'openedAt' => $pr->openedAt->format(DATE_ATOM),
            'closedAt' => $pr->closedAt()?->format(DATE_ATOM),
        ];
    }

    private function raw(Request $request): stdClass
    {
        try {
            $decoded = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvariantViolation::because('That is not valid JSON');
        }

        return $decoded instanceof stdClass ? $decoded : new stdClass();
    }
}
