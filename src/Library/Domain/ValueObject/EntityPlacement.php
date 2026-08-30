<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use JsonSerializable;
use stdClass;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * One entity placed in a level: an id, a type, and a blob of data.
 *
 * The server does not know what a "terrain" or a "motor" is, and must never
 * learn. That is not laziness, it is the same knowledge boundary the game
 * itself is built on: the world knows nothing about any concrete entity, and an
 * entity knows nothing about any other. Entity folders are meant to become the
 * unit of delivery, loaded at runtime — the day a level arrives holding a type
 * that shipped after this backend was deployed, the backend has to store it and
 * hand it back untouched. A server that validated entity data would reject that
 * level and become the one thing standing between the game and new content.
 *
 * So the envelope is checked and the contents are not. Data is kept as stdClass
 * rather than an associative array on purpose: an empty JSON object and an
 * empty JSON array both decode to [] in PHP, and telling them apart is the
 * difference between a matching content hash and a broken one.
 */
final readonly class EntityPlacement implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $type,
        public stdClass $data,
        public ?string $parent = null,
    ) {
        if ($id === "" || mb_strlen($id) > 64) {
            throw InvariantViolation::because("Entity id must be 1-64 characters");
        }
        if (preg_match("/^[a-z0-9-]{1,64}$/", $type) !== 1) {
            throw InvariantViolation::because(sprintf("Entity type \"%s\" is not a valid type name", $type));
        }
    }

    public static function fromObject(stdClass $raw): self
    {
        $data = $raw->data ?? new stdClass();

        if (!$data instanceof stdClass) {
            throw InvariantViolation::because("Entity data must be an object");
        }

        return new self(
            (string) ($raw->id ?? ""),
            (string) ($raw->type ?? ""),
            $data,
            isset($raw->parent) ? (string) $raw->parent : null,
        );
    }

    public function jsonSerialize(): stdClass
    {
        $out = new stdClass();
        $out->id = $this->id;
        $out->type = $this->type;
        $out->data = $this->data;

        if ($this->parent !== null) {
            $out->parent = $this->parent;
        }

        return $out;
    }
}
