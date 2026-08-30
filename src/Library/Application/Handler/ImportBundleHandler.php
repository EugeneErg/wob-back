<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use Wob\Library\Application\Command\ImportBundle;
use Wob\Library\Application\DTO\ImportResult;
use Wob\Library\Application\Import\BundleReader;
use Wob\Library\Application\Import\IdMap;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\Service\IdGenerator;
use Wob\Library\Domain\ValueObject\OwnerId;

/**
 * Import a bundle: always add, never overwrite.
 *
 * The whole thing runs in one transaction. A half-imported file is the worst
 * outcome available — chapters whose levels did not land, hot lists pointing at
 * assets that were rolled back — and it is exactly what you get if each
 * aggregate is saved as it is read.
 */
final readonly class ImportBundleHandler
{
    public function __construct(
        private StoryRepository $stories,
        private AssetRepository $assets,
        private IdGenerator $ids,
        private ConnectionInterface $db,
    ) {
    }

    public function __invoke(ImportBundle $command): ImportResult
    {
        $owner = new OwnerId($command->ownerId);

        return $this->db->transaction(function () use ($command, $owner): ImportResult {
            $map = new IdMap($this->stories->idsInUse($owner), $this->ids);
            $reader = new BundleReader($owner, $map);

            $read = $reader->read($command->bundle, $this->assets->ownedBy($owner));

            // Assets first: hot lists on stories point at them, and an author
            // who lands a story before its palette sees an empty one until the
            // transaction commits anyway — but ordering it this way keeps the
            // dependency direction obvious to anyone reading the log.
            foreach ($read['assets'] as $asset) {
                $this->assets->save($asset);
            }

            foreach ($read['stories'] as $story) {
                $this->stories->save($story);
            }

            return new ImportResult(
                array_map(
                    static fn (Story $s): array => ['id' => $s->id->value, 'title' => $s->title()],
                    $read['stories'],
                ),
                $map->all(),
                $read['warnings'],
            );
        });
    }
}
