<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\SaveSlot;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\SaveSlotRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * The save menu for one story.
 *
 * Slots are per story rather than per player, so this is always scoped by
 * story: each story is its own game, and a run through one has nothing to do
 * with a run through another.
 */
final readonly class SlotController
{
    public function __construct(
        private SaveSlotRepository $slots,
        private ReleaseRepository $releases,
        private Clock $clock,
    ) {
    }

    public function index(Request $request, string $storyId): JsonResponse
    {
        $player = $this->player($request);
        $slots = $this->slots->forPlayer($player, new StoryId($storyId));

        return new JsonResponse([
            'slots' => array_map(fn (SaveSlot $s): array => $this->present($s), $slots),
            'max' => SaveSlot::MAX_PER_STORY,
        ]);
    }

    public function create(Request $request, string $storyId): JsonResponse
    {
        $player = $this->player($request);
        $story = new StoryId($storyId);
        $taken = array_map(static fn (SaveSlot $s): int => $s->number, $this->slots->forPlayer($player, $story));

        $number = null;

        for ($i = 1; $i <= SaveSlot::MAX_PER_STORY; $i++) {
            if (!in_array($i, $taken, true)) {
                $number = $i;

                break;
            }
        }

        if ($number === null) {
            throw InvariantViolation::because(
                sprintf('All %d slots for this story are in use — erase one first', SaveSlot::MAX_PER_STORY),
            );
        }

        // A run is pinned to the version it starts on, so there has to be one.
        $release = $this->releases->latestOf($story) ?? throw NotFound::of('Release of story', $storyId);

        $slot = SaveSlot::start(
            Uuid::uuid4()->toString(),
            $player,
            $story,
            $number,
            $release->id,
            $this->clock->now(),
        );

        $this->slots->save($slot);

        return new JsonResponse($this->present($slot), 201);
    }

    public function update(Request $request, string $slotId): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'releaseId' => ['nullable', 'string'],
        ]);

        $slot = $this->slots->find($slotId, $this->player($request)) ?? throw NotFound::of('Slot', $slotId);

        if (array_key_exists('label', $data)) {
            $slot->rename($data['label']);
        }

        // Moving to a newer version is the player's decision, never automatic:
        // following the story's latest release by itself would change what
        // someone is playing without them asking.
        if (!empty($data['releaseId'])) {
            $slot->moveTo(new ReleaseId($data['releaseId']));
        }

        $this->slots->save($slot);

        return new JsonResponse($this->present($slot));
    }

    /** Start this run over, keeping its place on the shelf. */
    public function erase(Request $request, string $slotId): JsonResponse
    {
        $slot = $this->slots->find($slotId, $this->player($request)) ?? throw NotFound::of('Slot', $slotId);
        $this->slots->clearProgress($slot->id);

        return new JsonResponse($this->present($slot));
    }

    public function destroy(Request $request, string $slotId): JsonResponse
    {
        $slot = $this->slots->find($slotId, $this->player($request)) ?? throw NotFound::of('Slot', $slotId);
        $this->slots->remove($slot->id);

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(SaveSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'number' => $slot->number,
            'label' => $slot->label(),
            'storyId' => $slot->storyId->value,
            'releaseId' => $slot->releaseId()?->value,
            'lastPlayedAt' => $slot->lastPlayedAt()?->format(DATE_ATOM),
            'completed' => $this->slots->completedLevelIds($slot->id),
        ];
    }

    private function player(Request $request): string
    {
        return (string) $request->attributes->get('ownerId');
    }
}
