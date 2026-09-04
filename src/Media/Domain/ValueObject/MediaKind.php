<?php

declare(strict_types=1);

namespace Wob\Media\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * What a file is for, and therefore where it may be used.
 *
 * The accepted formats live here rather than in the controller because this is
 * a rule about the game, not about HTTP: a cover slot takes an image and an
 * intro takes a video, and that stays true however the bytes arrive.
 *
 * The lists are short on purpose. Every extra format is one more decoder that
 * has to work in every browser the game runs in, and a file the player cannot
 * play is worse than one the author could not upload — the author finds out at
 * once, the player finds out in the middle of a story.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';

    private const MIMES = [
        'image/png' => 'image',
        'image/jpeg' => 'image',
        'image/webp' => 'image',
        'image/gif' => 'image',
        'video/mp4' => 'video',
        'video/webm' => 'video',
    ];

    public static function forMime(string $mime): self
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $kind = self::MIMES[$mime] ?? null;

        if ($kind === null) {
            throw InvariantViolation::because(sprintf('"%s" is not a format this game can play', $mime));
        }

        return self::from($kind);
    }

    /** The ceiling for this kind, in bytes. */
    public function maxBytes(): int
    {
        // A cover is decoration and a still frame; past a few megabytes it is
        // an unscaled camera photo rather than a deliberate choice.
        //
        // The video ceiling is the interesting one. An intro plays before a
        // level, over a network the player did not pick, and every megabyte is
        // time the game sits still. Sixty is generous for the length these are
        // meant to be, and low enough that a feature-length upload fails at the
        // door instead of after a four-minute wait.
        return match ($this) {
            self::Image => 4 * 1024 * 1024,
            self::Video => 60 * 1024 * 1024,
        };
    }
}
