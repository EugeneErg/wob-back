<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Wob\Media\Domain\ValueObject\MediaKind;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * What the game accepts, and what it calls the result.
 *
 * The two lists are the same decision written twice, and they drifted apart
 * exactly once: sound was added to the accepted formats and the extension table
 * three files away was not touched, so every `.ogg` landed on the disk with no
 * extension at all. Nothing failed — the bytes are served with a stored mime and
 * play fine — and that is the whole problem with it. It shows up only when a
 * human opens the media folder to see what is in there.
 *
 * So this walks the accepted list rather than naming formats one by one: a
 * format added tomorrow is covered without anybody remembering to come here.
 */
final class MediaKindTest extends TestCase
{
    public function testEveryAcceptedFormatHasAName(): void
    {
        foreach (self::accepted() as $mime) {
            $kind = MediaKind::forMime($mime);

            self::assertNotSame('', $kind->extensionFor($mime), "{$mime} is accepted but has no extension");
        }
    }

    public function testSoundIsOneOfThem(): void
    {
        self::assertSame(MediaKind::Sound, MediaKind::forMime('audio/ogg'));
        self::assertSame('ogg', MediaKind::Sound->extensionFor('audio/ogg'));
    }

    /**
     * The mime a browser announces is a claim; the one read from the bytes may
     * carry a charset after it, and it is that one this is asked about.
     */
    public function testAMimeWithParametersIsStillUnderstood(): void
    {
        self::assertSame(MediaKind::Image, MediaKind::forMime('image/png; charset=binary'));
        self::assertSame('png', MediaKind::Image->extensionFor('image/png; charset=binary'));
    }

    public function testAFormatTheGameCannotPlayIsRefused(): void
    {
        $this->expectException(InvariantViolation::class);

        MediaKind::forMime('application/zip');
    }

    /** @return list<string> */
    private static function accepted(): array
    {
        /** @var array<string, string> $mimes */
        $mimes = (new ReflectionClass(MediaKind::class))->getConstant('MIMES');

        return array_keys($mimes);
    }
}
