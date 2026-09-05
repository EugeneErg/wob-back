<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\Release;
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
        private \Illuminate\Database\ConnectionInterface $db,
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

        // Прогон привязан к версии, на которой начат, поэтому версия обязана
        // быть. Играть черновик нельзя: рекорд, поставленный на содержимом,
        // которое автор ещё меняет, не с чем будет сверить — и не потому, что
        // так удобнее, а потому что сверять не с чем.
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

    /**
     * Можно ли доиграть начатое на более свежей версии.
     *
     * Прогон привязан к версии, на которой начат, и доигрывается на ней: иначе
     * содержимое поменялось бы под руками в середине истории. Но застревать на
     * старой версии навсегда тоже неверно — автор мог выпустить продолжение
     * ровно там, где игрок остановился.
     *
     * Правило перехода простое и берётся из того, что игрок уже прожил:
     * последний пройденный им уровень должен существовать и в новой версии.
     * Тогда «следующий» в новой версии — осмысленное продолжение того же пути.
     * Если этого уровня там нет, игрок оказался бы посреди истории, которой не
     * проходил, и перехода не предлагается.
     *
     * Уже пройденное засчитывается, даже если автор эти уровни переделал: игрок
     * прожил эту часть истории, и заставлять его проходить её заново из-за
     * чужой правки — наказание за то, что он играл раньше других. Рекорды при
     * этом остаются привязаны к своей версии и не смешиваются.
     */
    public function upgrade(Request $request, string $slotId): JsonResponse
    {
        $slot = $this->slots->find($slotId, $this->player($request)) ?? throw NotFound::of('Slot', $slotId);
        $offer = $this->offerFor($slot);

        if ($request->isMethod('get')) {
            return new JsonResponse($offer);
        }

        if (!$offer['available']) {
            throw InvariantViolation::because($offer['reason']);
        }

        $slot->moveTo(new ReleaseId($offer['releaseId']));
        $this->slots->save($slot);

        return new JsonResponse($this->present($slot));
    }

    /** @return array<string, mixed> */
    private function offerFor(SaveSlot $slot): array
    {
        $no = static fn (string $why): array => ['available' => false, 'reason' => $why];

        $current = $slot->releaseId() === null ? null : $this->releases->find($slot->releaseId());
        $newest = $this->releases->latestOf($slot->storyId);

        if ($newest === null || $current === null) {
            return $no('Этой истории не на что обновлять.');
        }

        if (!$newest->isClearedByAuthor()) {
            return $no('Новая версия ещё не пройдена автором, поэтому недоступна.');
        }

        if ($newest->number <= $current->number) {
            return $no('Вы уже играете самую свежую версию.');
        }

        $last = $this->lastClearedLevel($slot);

        if ($last === null) {
            // Ничего не пройдено — переносить нечего, можно просто начать заново
            // на новой версии.
            return $this->yes($newest, 'Вы ещё не начинали — можно сразу перейти на новую версию.');
        }

        if ($newest->content->level($last) === null) {
            return $no('В новой версии нет уровня, на котором вы остановились, — доиграйте эту.');
        }

        return $this->yes($newest, 'Можно продолжить со следующего уровня уже в новой версии.');
    }

    /** @return array<string, mixed> */
    private function yes(Release $release, string $why): array
    {
        return [
            'available' => true,
            'reason' => $why,
            'releaseId' => $release->id->value,
            'version' => $release->number,
        ];
    }

    /** Последний уровень, пройденный в этом прогоне. */
    private function lastClearedLevel(SaveSlot $slot): ?string
    {
        $row = $this->db->table('level_completions')
            ->where('slot_id', $slot->id)
            ->orderByDesc('last_completed_at')
            ->first();

        return $row === null ? null : (string) $row->level_public_id;
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
