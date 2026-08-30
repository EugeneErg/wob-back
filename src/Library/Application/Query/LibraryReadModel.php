<?php

declare(strict_types=1);

namespace Wob\Library\Application\Query;

/**
 * The read side, separate from the aggregate on purpose.
 *
 * Reads have no invariants to protect. Rebuilding a Story out of rows, with
 * every value object validated, only to immediately flatten it back into JSON,
 * is work that buys nothing — and it forces the wire format to mirror the model,
 * so the two can never evolve apart.
 *
 * The shape returned here is the one the client already speaks: the flat
 * stories / chapters / levels / assets lists of library.json. That is not a leak
 * of the client model into the server; it is a wire format, and it stays stable
 * even as the aggregate behind it changes.
 */
interface LibraryReadModel
{
    /** @return array{stories: list<array<string, mixed>>, assets: list<array<string, mixed>>} */
    public function shelfOf(string $ownerId): array;

    /**
     * One story with everything needed to play or edit it.
     *
     * @return array<string, mixed>|null
     */
    public function story(string $storyId, string $ownerId): ?array;

    /**
     * A story as a file, in the exact shape importBundle() reads.
     *
     * Built on the read side rather than from the aggregate because an export is
     * a projection, not a behaviour: nothing about it needs the invariants, and
     * routing it through the model would force the file format to follow the
     * model forever. The file format is a contract with every copy of the game
     * ever shipped; the model is ours to change.
     *
     * @return array<string, mixed>|null
     */
    public function storyBundle(string $storyId, string $ownerId): ?array;

    /**
     * The whole shelf as one file.
     *
     * @return array<string, mixed>
     */
    public function libraryBundle(string $ownerId): array;

    /**
     * A single level by content hash, with no story around it.
     *
     * This is what core/content.js asks for when it resolves a recording: the
     * fingerprint identifies the exact bytes that were played, so the lookup is
     * by hash and not by id. Content-addressed means the answer cannot be the
     * wrong version — a level edited since is simply a different hash.
     *
     * @return array<string, mixed>|null
     */
    public function levelByHash(string $hash, string $ownerId): ?array;
}
