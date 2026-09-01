<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Publishing\Application\Command\CastVote;
use Wob\Publishing\Application\Handler\CastVoteHandler;
use Wob\Publishing\Application\Handler\ReevaluateCanonHandler;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Publishing\Domain\Service\CanonPolicy;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Rating levels, and what that adds up to.
 *
 * The unit is the level, never the story: grading a story as a whole would let
 * one click stand in for levels the voter never played. Rating "the whole
 * story" in the client is one of these per finished level, not a different
 * kind of request.
 */
final readonly class VoteController
{
    public function __construct(
        private CastVoteHandler $cast,
        private ReevaluateCanonHandler $reevaluate,
        private ReleaseRepository $releases,
        private VoteRepository $votes,
        private ReleaseCompletionRepository $completions,
        private CanonPolicy $policy,
    ) {
    }

    public function store(Request $request, string $releaseId, string $levelId): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,10'],
        ]);

        ($this->cast)(new CastVote(
            (string) $request->attributes->get('ownerId'),
            $releaseId,
            $levelId,
            (int) $data['rating'],
        ));

        // Checked right after the vote rather than on a schedule, so an author
        // watching their story cross the line sees it happen.
        $becameCanon = ($this->reevaluate)(new ReleaseId($releaseId));

        return new JsonResponse([
            'levelId' => $levelId,
            'rating' => (int) $data['rating'],
            'becameCanon' => $becameCanon,
        ], 201);
    }

    /**
     * How a release is doing, and what it still needs.
     *
     * The gap is spelled out on purpose. An author who cannot see why their
     * story has not been crowned concludes the system is broken or rigged.
     */
    public function standing(string $releaseId): JsonResponse
    {
        $id = new ReleaseId($releaseId);
        $release = $this->releases->get($id);

        $players = $this->completions->countAtQuorumThreshold($id);
        $average = $this->votes->averageRating($id);

        return new JsonResponse([
            'releaseId' => $releaseId,
            'version' => $release->number,
            'votes' => $this->votes->countFor($id),
            'players' => $players,
            'averageRating' => round($average, 2),
            'quorum' => CanonPolicy::QUORUM,
            'required' => CanonPolicy::REQUIRED_AVERAGE,
            'qualifies' => $this->policy->qualifies($release->content, $players, $average),
            'missing' => $this->policy->unmetRequirements($release->content, $players, $average),
        ]);
    }
}
