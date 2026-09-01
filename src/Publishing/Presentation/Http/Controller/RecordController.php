<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Publishing\Application\Command\SubmitRun;
use Wob\Publishing\Application\Handler\SubmitRunHandler;
use Wob\Publishing\Domain\Model\SpeedrunRecord;
use Wob\Publishing\Domain\Repository\SpeedrunRecordRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Leaderboards, and the runs that fill them.
 *
 * Three boards rather than one, because a run can be against a level, a chapter
 * or a whole story, and those are different contests. Each is scoped to a
 * release: times are only comparable within one frozen version of the content,
 * so a single board spanning versions would rank people against different
 * puzzles.
 */
final readonly class RecordController
{
    public function __construct(
        private SpeedrunRecordRepository $records,
        private SubmitRunHandler $submit,
    ) {
    }

    public function index(Request $request, string $releaseId): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:level,chapter,story'],
            'target' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'in:any,hundred'],
            // Which physics. Omitted means "all of them", which is right for a
            // board nobody has split yet and wrong the moment the solver
            // changes — at which point the client starts asking for one.
            'rules' => ['nullable', 'string', 'max:32'],
        ]);

        $release = new ReleaseId($releaseId);
        $category = $data['category'] ?? SpeedrunRecord::ANY_PERCENT;
        $target = $data['scope'] === SpeedrunRecord::SCOPE_STORY ? null : ($data['target'] ?? null);

        $playerId = $this->player($request);

        $board = $this->records->leaderboard(
            $release,
            $data['scope'],
            $target,
            $category,
            50,
            $data['rules'] ?? null,
            $playerId,
        );

        $mine = null;

        if ($playerId !== null) {
            $best = $this->records->personalBest($release, $data['scope'], $target, $category, $playerId);
            $mine = $best === null ? null : ['ticks' => $best->ticks, 'verified' => $best->isVerified()];
        }

        return new JsonResponse([
            'scope' => $data['scope'],
            'target' => $target,
            'category' => $category,
            'board' => $board,
            // Shown next to the board so someone outside the top fifty can
            // still see where they stand.
            'personalBest' => $mine,
        ]);
    }

    public function store(Request $request, string $releaseId): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:level,chapter,story'],
            'target' => ['nullable', 'string', 'max:64'],
            'category' => ['required', 'in:any,hundred'],
            'ticks' => ['required', 'integer', 'min:1'],
            'seed' => ['required', 'integer'],
            'rulesVersion' => ['required', 'string', 'max:32'],
            'input' => ['present', 'array'],
            'input.*' => ['integer'],
        ]);

        $record = ($this->submit)(new SubmitRun(
            (string) $request->attributes->get('ownerId'),
            $releaseId,
            $data['scope'],
            $data['scope'] === SpeedrunRecord::SCOPE_STORY ? null : ($data['target'] ?? null),
            $data['category'],
            (int) $data['ticks'],
            array_map(intval(...), $data['input']),
            (int) $data['seed'],
            $data['rulesVersion'],
        ));

        return new JsonResponse([
            'id' => $record->id,
            'ticks' => $record->ticks,
            'verified' => $record->isVerified(),
        ], 201);
    }

    private function player(Request $request): ?string
    {
        $id = $request->attributes->get('ownerId');

        return $id === null ? null : (string) $id;
    }
}
