<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\EditMap;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

/** Применяет одну мелкую правку карты — и ничего вокруг неё не трогает. */
final readonly class EditMapHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(EditMap $command): Story
    {
        $story = $this->stories->get(new OwnerId($command->ownerId), new StoryId($command->storyId));

        match ($command->kind) {
            // Фон карты приезжает в поле outro: у главы своих полей два, и
            // заводить ради второго отдельный вид команды не за что.
            "chapter" => $story->describeChapter(
                new ChapterId((string) $command->chapterId),
                (string) $command->name,
                (string) $command->image,
                (string) $command->outro,
            ),
            "node" => $this->editNode($story, $command),
            "link" => $command->linked
                ? $story->linkNodes(new NodeId((string) $command->from), new NodeId((string) $command->to))
                : $story->unlinkNodes(new NodeId((string) $command->from), new NodeId((string) $command->to)),
            default => null,
        };

        $this->stories->save($story);

        return $story;
    }

    /**
     * Переезд и подпись — две разные правки одной точки.
     *
     * Приходят они одним запросом, потому что автор делает и то и другое в
     * одной форме, но применяются раздельно: не названное остаётся как было.
     * Иначе перетаскивание стирало бы имя, а переименование — возвращало точку
     * на прежнее место.
     */
    private function editNode(Story $story, EditMap $command): void
    {
        $chapterId = new ChapterId((string) $command->chapterId);
        $nodeId = new NodeId((string) $command->nodeId);

        if ($command->x !== null && $command->y !== null) {
            $story->moveNode($chapterId, $nodeId, $command->x, $command->y);
        }

        if ($command->name !== null || $command->image !== null || $command->outro !== null) {
            $node = $story->chapter($chapterId)->node($nodeId);

            $story->describeNode(
                $chapterId,
                $nodeId,
                $command->name ?? $node?->name ?? "",
                $command->image ?? $node?->image ?? "",
                $command->outro ?? $node?->outro ?? "",
            );
        }
    }
}
