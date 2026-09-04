<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One link type instead of two, and no story left open all at once.
 *
 * Chapters used to carry edges between the levels on their own map, while a map
 * node carried an exit to a whole other chapter. Two mechanisms for "what comes
 * after this", drawn at two scales. Points now link to points and a link may
 * cross a chapter boundary, so both collapse into one list per point.
 *
 * The conversion below matters more than the schema change. Unlocking used to
 * have two regimes: a story with no exits anywhere was walked in chapter order,
 * and one with any exit was walked as a graph. Under a single graph an
 * unconverted story would have no links at all — every point an entry, every
 * chapter open from the first minute, every ending reachable immediately. So
 * the old order is written out as explicit links here, and what was implicit
 * becomes something an author can see and edit.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->string('start_node_id', 64)->nullable();
        });

        $this->convert();

        Schema::table('stories', static function (Blueprint $table): void {
            $table->dropColumn('start_chapter_id');
        });

        Schema::table('chapters', static function (Blueprint $table): void {
            $table->dropColumn('edges');
        });
    }

    public function down(): void
    {
        Schema::table('stories', static function (Blueprint $table): void {
            $table->string('start_chapter_id', 64)->nullable();
            $table->dropColumn('start_node_id');
        });

        Schema::table('chapters', static function (Blueprint $table): void {
            $table->json('edges')->default('[]');
        });
    }

    private function convert(): void
    {
        foreach (DB::table('stories')->orderBy('id')->get() as $story) {
            $chapters = DB::table('chapters')
                ->where('story_id', $story->id)
                ->orderBy('position')
                ->get();

            /** @var array<string, list<array<string, mixed>>> $byChapter */
            $byChapter = [];
            $firstNodeOf = [];
            $lastNodesOf = [];

            foreach ($chapters as $chapter) {
                $nodes = json_decode((string) $chapter->nodes, true) ?: [];
                $edges = json_decode((string) $chapter->edges, true) ?: [];

                // Points were named by their level until now.
                foreach ($nodes as $i => $node) {
                    $nodes[$i]['id'] = $node['id'] ?? 'nd-' . $node['levelId'];
                }

                $idOfLevel = [];

                foreach ($nodes as $node) {
                    $idOfLevel[$node['levelId']] ??= $node['id'];
                }

                $children = [];

                // An edge said "finishing this level opens that one", which is
                // exactly what a link says now.
                foreach ($edges as $edge) {
                    $from = $idOfLevel[$edge['from']] ?? null;
                    $to = $idOfLevel[$edge['to']] ?? null;

                    if ($from !== null && $to !== null) {
                        $children[$from][] = $to;
                    }
                }

                foreach ($nodes as $i => $node) {
                    // The old 'next' named a chapter. Park it before the key is
                    // reused for the child list; joinChapters() resolves it to
                    // that chapter's entry point and drops it.
                    $nodes[$i]['next_chapter'] = is_string($node['next'] ?? null) ? $node['next'] : null;
                    $nodes[$i]['next'] = array_values(array_unique($children[$node['id']] ?? []));
                }

                $byChapter[$chapter->id] = $nodes;
                $firstNodeOf[$chapter->id] = $this->entryOf($nodes);

                // Where the chapter can be left from: the points that finish it.
                $lastNodesOf[$chapter->id] = array_values(array_map(
                    static fn (array $n): string => $n['id'],
                    array_filter(
                        $nodes,
                        static fn (array $n): bool => $n['next'] === [] && $n['next_chapter'] === null,
                    ),
                ));
            }

            $this->joinChapters($chapters, $byChapter, $firstNodeOf, $lastNodesOf);

            foreach ($byChapter as $chapterId => $nodes) {
                DB::table('chapters')->where('id', $chapterId)->update([
                    'nodes' => json_encode(array_values($nodes)),
                ]);
            }

            $start = null;

            foreach ($chapters as $chapter) {
                if ($story->start_chapter_id !== null && $chapter->public_id === $story->start_chapter_id) {
                    $start = $firstNodeOf[$chapter->id];

                    break;
                }
            }

            $start ??= $firstNodeOf[$chapters->first()->id ?? ''] ?? null;

            DB::table('stories')->where('id', $story->id)->update(['start_node_id' => $start]);
        }
    }

    /**
     * Stitch consecutive chapters together.
     *
     * An old exit named a chapter, not a place inside it, so the link lands on
     * that chapter's entry point. Where there was no exit at all the chapters
     * were walked in order, so consecutive ones are joined the same way — that
     * is the behaviour being preserved, just written down.
     *
     * @param array<string, list<array<string, mixed>>> $byChapter
     * @param array<string, string|null> $firstNodeOf
     * @param array<string, list<string>> $lastNodesOf
     */
    private function joinChapters(
        iterable $chapters,
        array &$byChapter,
        array $firstNodeOf,
        array $lastNodesOf,
    ): void {
        $publicToId = [];

        foreach ($chapters as $chapter) {
            $publicToId[$chapter->public_id] = $chapter->id;
        }

        $previous = null;

        foreach ($chapters as $chapter) {
            $exitsTaken = false;

            foreach ($byChapter[$chapter->id] as $i => $node) {
                $exit = $node['next_chapter'] ?? null;
                unset($byChapter[$chapter->id][$i]['next_chapter']);

                $target = $exit === null ? null : ($publicToId[$exit] ?? null);
                $entry = $target === null ? null : ($firstNodeOf[$target] ?? null);

                if ($entry !== null) {
                    $byChapter[$chapter->id][$i]['next'][] = $entry;
                    $exitsTaken = true;
                }
            }

            // No exit anywhere: the story ran in chapter order, so the points
            // that ended the previous chapter now open this one.
            if (!$exitsTaken && $previous !== null && $firstNodeOf[$chapter->id] !== null) {
                foreach ($lastNodesOf[$previous] as $tail) {
                    foreach ($byChapter[$previous] as $i => $node) {
                        if ($node['id'] === $tail) {
                            $byChapter[$previous][$i]['next'][] = $firstNodeOf[$chapter->id];
                        }
                    }
                }
            }

            $previous = $chapter->id;
        }
    }

    /** @param list<array<string, mixed>> $nodes */
    private function entryOf(array $nodes): ?string
    {
        $targeted = [];

        foreach ($nodes as $node) {
            foreach ($node['next'] ?? [] as $child) {
                $targeted[$child] = true;
            }
        }

        foreach ($nodes as $node) {
            if (!isset($targeted[$node['id']])) {
                return $node['id'];
            }
        }

        return $nodes[0]['id'] ?? null;
    }
};
