<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use stdClass;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\AggregateRoot;

/**
 * A saved entity setting: a type plus the data that entity calls its own.
 *
 * An aggregate root of its own, not a part of Story, because the shelf is
 * per-author and shared across their stories — createAsset() on the client
 * pushes into one global list, and stories merely mark some of them "hot". A
 * hot list is therefore a reference across an aggregate boundary, by id, which
 * is exactly how such references are supposed to look.
 *
 * The consequence is deliberate: a hot id may outlive the asset it names. The
 * client already tolerates this (it filters missing assets out when building the
 * palette), and the alternative — cascading a delete into every story to keep
 * the lists tidy — would make deleting one asset a write across the whole
 * library. A dangling id costs nothing; a fan-out write costs correctness.
 */
final class Asset extends AggregateRoot
{
    public function __construct(
        public readonly AssetId $id,
        public readonly OwnerId $ownerId,
        public readonly string $type,
        private string $title,
        private stdClass $data,
    ) {
        if (preg_match("/^[a-z0-9-]{1,64}$/", $type) !== 1) {
            throw InvariantViolation::because(sprintf("Asset type \"%s\" is not a valid type name", $type));
        }

        $this->rename($title);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function data(): stdClass
    {
        return $this->data;
    }

    public function rename(string $title): void
    {
        $title = trim($title);

        if ($title === "" || mb_strlen($title) > 200) {
            throw InvariantViolation::because("Asset title must be 1-200 characters");
        }

        $this->title = $title;
    }

    public function replaceData(stdClass $data): void
    {
        $this->data = $data;
    }
}
