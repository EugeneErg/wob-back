<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

/**
 * The lookup tables a level leans on but does not contain.
 *
 * A level names things instead of describing them: a picture by resource id, a
 * surface by material, a sign by a string key. All four tables live beside the
 * levels and are read once for the whole set.
 *
 * Like the reader beneath it, this knows nothing about our entities. It turns
 * the game's tables into plain arrays, and the conversion above decides what
 * any of it means.
 */
final class WogTables
{
    /**
     * Resource ids to file paths.
     *
     * `SetDefaults` sets a path and id prefix for the entries that follow, and
     * it applies until the next one — so the entries cannot be read out of
     * order, and a prefix left over from the previous group would point half
     * the pictures at the wrong folder.
     *
     * Sounds are read here too. They were not, for a long time: the reader only
     * ever looked at `Image`, so the sound table stayed empty and every level
     * reported its music as "no such file in the set". The file was there;
     * nobody was looking for it.
     *
     * @return array{images: array<string, string>, sounds: array<string, string>}
     */
    public static function resources(string $root): array
    {
        $images = [];
        $sounds = [];

        foreach (self::manifests($root) as $file) {
            $doc = WogFile::parseXml(WogFile::decrypt((string) file_get_contents($file)));

            foreach (WogFile::kids($doc, 'Resources') as $group) {
                $prefixPath = '';
                $prefixId = '';

                foreach ($group['children'] ?? [] as $item) {
                    $attrs = $item['attrs'];

                    if ($item['tag'] === 'SetDefaults') {
                        $prefixPath = ($attrs['path'] ?? '') === './' ? '' : ($attrs['path'] ?? '');
                        $prefixId = $attrs['idprefix'] ?? '';

                        continue;
                    }

                    if (!isset($attrs['path'], $attrs['id'])) {
                        continue;
                    }

                    $path = $attrs['path'];
                    $rel = preg_replace(
                        '#^res/#',
                        '',
                        str_starts_with($path, 'res/') ? $path : $prefixPath . $path,
                    ) ?? '';

                    if ($item['tag'] === 'Image') {
                        $images[$prefixId . $attrs['id']] = $rel;
                    } elseif ($item['tag'] === 'Sound') {
                        $sounds[$prefixId . $attrs['id']] = $rel;
                    }
                }
            }
        }

        return ['images' => $images, 'sounds' => $sounds];
    }

    /** @return list<string> */
    private static function manifests(string $root): array
    {
        $found = [];
        $walk = static function (string $dir) use (&$walk, &$found): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $dir . '/' . $name;

                if (is_dir($path)) {
                    $walk($path);
                } elseif (str_ends_with($name, '.resrc.bin') || $name === 'resources.xml.bin') {
                    $found[] = $path;
                }
            }
        };
        $walk($root);
        sort($found);

        return $found;
    }

    /**
     * Materials, as two knobs instead of the original's several.
     *
     * Friction runs 0…100 there and smoothness runs 0…1 here, and the two are
     * inverse: ice has friction 0.5, grass has 100. The curve is not a guess at
     * the original's physics — the two engines do not share one — it is chosen
     * so that the ends land in the right place and nothing in between is
     * surprising.
     *
     * @return array<string, array{smoothness: float, restitution: float, stickiness: float}>
     */
    public static function materials(string $root): array
    {
        $out = [];
        $doc = WogFile::parseXml(WogFile::decrypt(
            (string) file_get_contents($root . '/properties/materials.xml.bin'),
        ));

        foreach (WogFile::kids($doc, 'material') as $m) {
            $attrs = $m['attrs'];
            $out[$attrs['id'] ?? ''] = [
                'smoothness' => round(1 / (1 + (float) ($attrs['friction'] ?? 0) / 2), 3),
                'restitution' => round((float) ($attrs['bounce'] ?? 0), 3),
                'stickiness' => (float) ($attrs['stickiness'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Particle effects by name.
     *
     * An effect can be declared twice — once on its own and once as ambient —
     * and the ambient one is the same effect placed differently, not a second
     * effect with the same name. Reading them as two would give the level two
     * emitters where the original has one.
     *
     * @return array<string, array{attrs: array<string, string>, ambient: bool, parts: list<array<string, mixed>>}>
     */
    public static function effects(string $root): array
    {
        $doc = WogFile::parseXml(WogFile::decrypt(
            (string) file_get_contents($root . '/properties/fx.xml.bin'),
        ));

        $out = [];

        foreach (['particleeffect', 'ambientparticleeffect'] as $tag) {
            foreach (WogFile::kids($doc, $tag) as $e) {
                $name = $e['attrs']['name'] ?? '';
                $out[$name] ??= [
                    'attrs' => $e['attrs'],
                    'ambient' => false,
                    'parts' => WogFile::kids($e, 'particle'),
                ];
            }
        }

        foreach (WogFile::kids($doc, 'ambientparticleeffect') as $e) {
            $name = $e['attrs']['name'] ?? '';

            if (isset($out[$name])) {
                $out[$name]['ambient'] = true;
            }
        }

        return $out;
    }

    /**
     * Sign and label text, by key.
     *
     * This one file is not encrypted, which is why it is read differently from
     * everything else here. Translations are separated by a vertical bar; the
     * first is the set's own language.
     *
     * @return array<string, string>
     */
    public static function strings(string $root): array
    {
        $file = $root . '/properties/Text.xml';

        if (!is_file($file)) {
            return [];
        }

        $src = (string) file_get_contents($file);
        $out = [];

        preg_match_all('/<string\s+id="([^"]+)"([\s\S]*?)\/>/', $src, $found, PREG_SET_ORDER);

        foreach ($found as [, $id, $rest]) {
            if (preg_match('/\stext="([^"]*)"/', $rest, $text) === 1) {
                $out[$id] = str_replace('|', "\n", strtr($text[1], [
                    '&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'",
                ]));
            }
        }

        return $out;
    }
}
