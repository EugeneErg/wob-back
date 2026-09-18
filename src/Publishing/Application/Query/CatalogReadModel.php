<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Query;

/**
 * What there is to play.
 *
 * The frontend ships with no stories at all now — every level a player sees
 * came from here. That is what makes the gate real rather than cosmetic: there
 * is nothing in the bundle to unlock.
 */
interface CatalogReadModel
{
    /**
     * Stories in the canon, oldest crown first.
     *
     * @return list<array<string, mixed>>
     */
    public function canon(): array;

    /**
     * Published stories that have not made canon yet — playable, gathering the
     * votes that might get them there.
     *
     * @return list<array<string, mixed>>
     */
    public function published(): array;

    /**
     * The one story a signed-out visitor may see, or null when the canon is
     * empty. Deliberately singular: the taste of the game is one story, not a
     * browsable shelf.
     *
     * @return array<string, mixed>|null
     */
    public function forVisitor(): ?array;

    /**
     * A story's playable content, as the given player is allowed to have it.
     *
     * @return array<string, mixed>|null
     */
    public function play(string $storyId, ?string $playerId): ?array;

    /**
     * Один уровень со всем, что ему нужно, — когда его открывают.
     *
     * Отдельно от `play`, потому что это разные мгновения: карту смотрят до
     * выбора, уровень берут после. Возит она и сущности, и ассеты этого
     * уровня — без них его не показать.
     *
     * Null, если уровня нет или этому человеку он не полагается.
     *
     * @return array<string, mixed>|null
     */
    public function level(string $storyId, string $levelId, ?string $playerId): ?array;
}
