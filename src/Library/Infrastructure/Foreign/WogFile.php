<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

use RuntimeException;

/**
 * Reading the original game's files.
 *
 * This is the bottom layer of the converter: decryption, the XML dialect the
 * game uses, the resource manifest, and the binary animation format. Everything
 * else in the conversion is built on it, and none of it knows anything about
 * our entities — its whole job is to turn someone else's bytes into arrays.
 *
 * It is a port of what already worked in Node, and it is checked against it:
 * the same file read by both sides must give the same structure. That is the
 * only honest way to move a converter — the format is not specified anywhere we
 * control, and "looks about right" has already been wrong three times.
 */
final class WogFile
{
    /**
     * The key the game ships with. Not a secret and never was: it is in every
     * copy of the game, and the files it protects are the game's own art.
     */
    private const KEY = "\x0D\x06\x07\x07\x0C\x01\x08\x05\x06\x09\x09\x04\x06\x0D\x03\x0F\x03\x06\x0E\x01\x0E\x02\x07\x0B";

    /**
     * Decrypt one of the game's .bin files.
     *
     * AES-192-CBC with a zero IV and no padding of its own. The plaintext ends
     * at a 0xFD byte somewhere in the last block — the game writes it as a
     * terminator — and everything after it is leftover. Cutting at the wrong
     * place leaves rubbish glued to the last tag, which the XML reader then
     * silently ignores, so the mistake would show up much later as a missing
     * attribute rather than as a decryption error.
     */
    public static function decrypt(string $bytes): string
    {
        $out = openssl_decrypt($bytes, 'aes-192-cbc', self::KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));

        if ($out === false) {
            throw new RuntimeException('Could not decrypt: not a file from this game, or it is damaged');
        }

        $from = max(0, strlen($out) - 16);
        $cut = strpos($out, "\xFD", $from);

        return $cut === false ? $out : substr($out, 0, $cut);
    }

    /**
     * The game's XML, as much of it as exists.
     *
     * A very narrow dialect: elements with attributes, nesting, comments. No
     * text inside nodes, no CDATA. Hand-read rather than handed to a parser for
     * the same reason as on the other side — the whole grammar is three regular
     * expressions, and a dependency would be larger than the thing it replaces.
     *
     * @return array<string, mixed>
     */
    public static function parseXml(string $src): array
    {
        $clean = preg_replace(['/<\?[\s\S]*?\?>/', '/<!--[\s\S]*?-->/'], '', $src) ?? '';

        $root = ['tag' => '#root', 'attrs' => [], 'children' => []];
        $stack = [&$root];

        preg_match_all(
            '/<(\/?)([\w.:-]+)((?:\s+[\w.:-]+\s*=\s*"[^"]*")*)\s*(\/?)>/',
            $clean,
            $found,
            PREG_SET_ORDER,
        );

        foreach ($found as [, $close, $tag, $attrText, $self]) {
            if ($close !== '') {
                if (count($stack) > 1) {
                    array_pop($stack);
                }

                continue;
            }

            $node = ['tag' => $tag, 'attrs' => self::attrsOf($attrText), 'children' => []];

            $top = count($stack) - 1;
            $stack[$top]['children'][] = $node;

            if ($self === '') {
                // A reference to the copy that is actually in the tree, so that
                // children added later land in the tree and not in a discarded
                // duplicate. PHP arrays are values, and forgetting this is the
                // classic way to build a tree that comes out flat.
                $last = count($stack[$top]['children']) - 1;
                $stack[] = &$stack[$top]['children'][$last];
            }
        }

        // Пустой документ отдаём корнем: у него тот же вид, и звать читающего
        // разбираться с null незачем — ни одного тега значит ни одного тега.
        /** @var list<array<string, mixed>> $children */
        $children = $root['children'];

        return count($children) === 0 ? $root : $children[0];
    }

    /** @return array<string, string> */
    private static function attrsOf(string $text): array
    {
        $out = [];
        preg_match_all('/([\w.:-]+)\s*=\s*"([^"]*)"/', $text, $found, PREG_SET_ORDER);

        foreach ($found as [, $name, $value]) {
            $out[$name] = strtr($value, [
                '&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'",
            ]);
        }

        return $out;
    }

    /**
     * The tree as JSON, matching the other side byte for byte.
     *
     * PHP encodes an empty array as `[]`, and an element with no attributes is
     * exactly that. On a real manifest this was the single place the port
     * disagreed with the original: same structure, same length, `"attrs":[]`
     * against `"attrs":{}`. The same document to a human, a different one to a
     * content hash.
     *
     * Fixing it in the tree itself would mean attributes are sometimes an array
     * and sometimes an object, and every reader would have to know which. So
     * the tree stays plain and the difference is handled once, here, where the
     * bytes are actually produced.
     */
    /** @param array<string, mixed> $node */
    public static function toJson(array $node): string
    {
        return (string) json_encode(
            self::objectifyAttrs($node),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function objectifyAttrs(array $node): array
    {
        $node['attrs'] = $node['attrs'] === [] ? new \stdClass() : $node['attrs'];
        /** @var list<array<string, mixed>> $children */
        $children = $node['children'] ?? [];
        $node['children'] = array_map(self::objectifyAttrs(...), $children);

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    public static function kids(array $node, string $tag): array
    {
        /** @var list<array<string, mixed>> $children */
        $children = $node['children'] ?? [];

        return array_values(array_filter(
            $children,
            static fn (array $c): bool => ($c['tag'] ?? '') === $tag,
        ));
    }

    /**
     * The width and height of a PNG, from its header alone.
     *
     * Twenty-four bytes is enough and reading further would mean decoding the
     * image. The size matters because a part is drawn at its picture's
     * proportions: without them a 200×50 body comes out square, which is a
     * circle where a bar should be.
     *
     * @return array{w: int, h: int}|null
     */
    public static function pngSize(string $file): ?array
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 24);
        fclose($handle);

        if (strlen($head) < 24 || substr($head, 1, 3) !== 'PNG') {
            return null;
        }

        /** @var array{w: int, h: int} $size */
        $size = unpack('Nw/Nh', substr($head, 16, 8));

        return $size;
    }
}
