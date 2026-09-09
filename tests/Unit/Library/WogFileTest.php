<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wob\Library\Infrastructure\Foreign\WogFile;

/**
 * Reading the original game's files, checked against real ones.
 *
 * The fixtures are actual files from the game, not something written here to
 * be convenient. That matters more than usual: this format is not specified
 * anywhere we control, and every time it was guessed at rather than read, the
 * guess was wrong — about how parts stretch, about what the ranges mean, about
 * how the random variation is applied. A made-up fixture would agree with
 * whatever the reader happens to do.
 */
final class WogFileTest extends TestCase
{
    public function testAnEncryptedFileComesOutAsItsXml(): void
    {
        $xml = WogFile::decrypt($this->fixture('level.bin'));

        self::assertStringStartsWith('<level', ltrim($xml));

        // The plaintext ends at a 0xFD terminator inside the last block, and
        // everything after it is leftover. Cutting in the wrong place leaves
        // rubbish glued to the last tag, which the reader then ignores in
        // silence — the mistake would surface much later as a missing
        // attribute rather than as a failure to decrypt.
        self::assertStringNotContainsString("\xFD", $xml);
        self::assertStringEndsWith('>', rtrim($xml));
    }

    public function testRubbishIsRefusedRatherThanReadAsGarbage(): void
    {
        $this->expectException(RuntimeException::class);

        WogFile::decrypt('not a file from this game');
    }

    public function testTheDocumentComesOutAsATree(): void
    {
        $level = WogFile::parseXml(WogFile::decrypt($this->fixture('level.bin')));

        self::assertSame('level', $level['tag']);
        self::assertSame('4', $level['attrs']['ballsrequired']);

        // Nesting is the part that is easy to get wrong in PHP: arrays are
        // values, so a tree built without references comes out flat and every
        // child ends up on the root.
        $balls = WogFile::kids($level, 'BallInstance');
        self::assertNotEmpty($balls);
        self::assertArrayHasKey('type', $balls[0]['attrs']);
    }

    public function testAttributesAreUnescaped(): void
    {
        $node = WogFile::parseXml('<a t="one &amp; two &lt;three&gt; &quot;four&quot;"/>');

        self::assertSame('one & two <three> "four"', $node['attrs']['t']);
    }

    /**
     * An element with no attributes must stay an object, not become a list.
     *
     * This is the one place the port disagreed with the original on a real
     * file. The structure was identical and so was the length; only PHP's
     * empty array encoded as `[]` where the other side had `{}`. Same document
     * to a human, different document to a content hash.
     */
    public function testAnElementWithoutAttributesIsStillAnObject(): void
    {
        $node = WogFile::parseXml('<a><b/></a>');

        self::assertSame(
            '{"tag":"a","attrs":{},"children":[{"tag":"b","attrs":{},"children":[]}]}',
            WogFile::toJson($node),
        );
    }

    public function testCommentsAndPrologueAreDropped(): void
    {
        $node = WogFile::parseXml('<?xml version="1.0"?><!-- note --><a><!-- <b/> --><c/></a>');

        self::assertSame('a', $node['tag']);
        self::assertCount(1, $node['children']);
        self::assertSame('c', $node['children'][0]['tag']);
    }

    public function testABallDefinitionReadsItsPartsAndAnimation(): void
    {
        $ball = WogFile::parseXml(WogFile::decrypt($this->fixture('balls.bin')));

        self::assertSame('ball', $ball['tag']);
        self::assertNotEmpty(WogFile::kids($ball, 'part'));

        // Variance wraps a group of sines rather than sitting beside them, and
        // that nesting is load-bearing: one random offset for the whole group
        // keeps a walk in step. A reader that flattened it would lose the
        // grouping without losing any values, which is the kind of break
        // nothing else would notice.
        $variance = WogFile::kids($ball, 'sinvariance');
        self::assertNotEmpty($variance);
        self::assertNotEmpty(WogFile::kids($variance[0], 'sinanim'));
    }

    public function testAPngGivesUpItsSizeWithoutBeingDecoded(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'wob');
        file_put_contents($png, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 8) . pack('NN', 200, 50));

        self::assertSame(['w' => 200, 'h' => 50], WogFile::pngSize($png));
        self::assertNull(WogFile::pngSize($png . '-missing'));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/' . $name);
    }
}
