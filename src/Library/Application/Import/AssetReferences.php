<?php

declare(strict_types=1);

namespace Wob\Library\Application\Import;

use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Every asset a level names must exist.
 *
 * A placement may name an asset instead of spelling itself out, keeping only
 * the differences. That is sound because assets are immutable: never edited,
 * never deleted, only retired. Retired still resolves — it is hidden from the
 * shelf so nobody picks it again, not taken away from levels already built on
 * it. So a reference that does not resolve is not a case to handle gracefully;
 * it means something upstream is broken, and the level it describes cannot be
 * drawn at all.
 *
 * Catching it on the way in is the difference between one refused save and a
 * level that looks fine in the library and falls apart when someone opens it.
 * It cannot live in the Level model, which has no repository and must not grow
 * one: the model checks the shape of what it holds, and whether a name points
 * at something real is a question only the library can answer.
 *
 * Ownership is deliberately not checked. Assets may be used by authors who did
 * not make them — that is the point of a shared shelf — so resolving a
 * reference asks whether the asset exists, not whose it is.
 */
final readonly class AssetReferences
{
    public function __construct(private AssetRepository $assets)
    {
    }

    /** @param list<EntityPlacement> $entities */
    public function mustResolve(array $entities): void
    {
        $found = [];

        foreach ($entities as $entity) {
            if ($entity->asset === null || isset($found[$entity->asset])) {
                continue;
            }

            $asset = $this->assets->byId(new AssetId($entity->asset));

            if ($asset === null) {
                throw InvariantViolation::because(sprintf(
                    "Entity \"%s\" is built on asset \"%s\", which does not exist",
                    $entity->id,
                    $entity->asset,
                ));
            }

            $found[$entity->asset] = $asset;
        }

        // A group asset holds several entities, so a placement built on one has
        // to say which member it means: otherwise there is no telling what the
        // differences differ from.
        foreach ($entities as $entity) {
            if ($entity->asset === null) {
                continue;
            }

            $members = array_map(
                static fn (EntityPlacement $e): string => $e->id,
                $found[$entity->asset]->entities(),
            );

            if ($entity->member === null && count($members) > 1) {
                throw InvariantViolation::because(sprintf(
                    "Entity \"%s\" is built on asset \"%s\", which holds %d entities, so it must say which one",
                    $entity->id,
                    $entity->asset,
                    count($members),
                ));
            }

            if ($entity->member !== null && !in_array($entity->member, $members, true)) {
                throw InvariantViolation::because(sprintf(
                    "Entity \"%s\" names member \"%s\" of asset \"%s\", which is not in it",
                    $entity->id,
                    $entity->member,
                    $entity->asset,
                ));
            }
        }
    }
}
