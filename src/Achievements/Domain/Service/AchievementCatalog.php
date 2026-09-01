<?php

declare(strict_types=1);

namespace Wob\Achievements\Domain\Service;

use Wob\Achievements\Domain\Model\Achievement;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * Everything the game recognises, and what each is worth.
 *
 * Three sources, deliberately weighted against each other rather than tuned in
 * isolation:
 *
 *  - PLAYING is the floor. Everyone can earn it, the amounts are small, and it
 *    exists so that a new player sees the system respond to them at all.
 *  - SPEEDRUNNING pays more per achievement and is much harder to repeat: a
 *    board has one first place, and holding it is the reward.
 *  - AUTHORING pays most, because it is the only one that makes something for
 *    other people — and because the alternative is a game where everybody plays
 *    and nobody builds.
 *
 * The author tiers are counted in players who actually finished the story, not
 * in views or in playthroughs started. That is the one place this could be
 * farmed, and counting finishers puts the price of faking an audience at
 * actually playing the whole story once per fake account.
 *
 * Nothing here rewards a rating. Points for being rated well would put an
 * author's earnings in the hands of people they cannot see, and would make
 * every low vote feel like theft — the canon threshold already carries the
 * quality judgement, and it is guarded by a quorum.
 */
final class AchievementCatalog
{
    public const FIRST_LEVEL = 'first-level';
    public const STORY_FINISHED = 'story-finished';
    public const STORY_HUNDRED = 'story-hundred';
    public const CANON_FINISHED = 'canon-finished';

    public const FIRST_RUN = 'first-run';
    public const TOP_TEN = 'top-ten';
    public const TOP_THREE = 'top-three';
    public const FIRST_PLACE = 'first-place';

    public const FIRST_RELEASE = 'first-release';
    public const AUDIENCE_TEN = 'audience-10';
    public const AUDIENCE_HUNDRED = 'audience-100';
    public const AUDIENCE_THOUSAND = 'audience-1000';
    public const CANONISED = 'canonised';
    public const CONTRIBUTION_ACCEPTED = 'contribution-accepted';

    /** @var array<string, Achievement>|null */
    private ?array $all = null;

    /** @return array<string, Achievement> */
    public function all(): array
    {
        return $this->all ??= $this->index([
            // --- playing -------------------------------------------------
            new Achievement(
                self::FIRST_LEVEL,
                'First steps',
                'Finish your first level.',
                5,
            ),
            new Achievement(
                self::STORY_FINISHED,
                'Saw it through',
                'Finish a story.',
                25,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::STORY_HUNDRED,
                'Every last one',
                'Finish every level of a story.',
                60,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::CANON_FINISHED,
                'Part of the canon',
                'Finish a story that made the canon.',
                40,
                Achievement::SUBJECT_STORY,
            ),

            // --- speedrunning --------------------------------------------
            new Achievement(
                self::FIRST_RUN,
                'On the clock',
                'Set your first recorded time.',
                10,
            ),
            new Achievement(
                self::TOP_TEN,
                'Top ten',
                'Reach the top ten of a leaderboard.',
                30,
                Achievement::SUBJECT_LEVEL,
            ),
            new Achievement(
                self::TOP_THREE,
                'Podium',
                'Reach the top three of a leaderboard.',
                75,
                Achievement::SUBJECT_LEVEL,
            ),
            new Achievement(
                self::FIRST_PLACE,
                'Fastest alive',
                'Hold first place on a leaderboard.',
                150,
                Achievement::SUBJECT_LEVEL,
            ),

            // --- authoring -----------------------------------------------
            new Achievement(
                self::FIRST_RELEASE,
                'Published',
                'Release a story for other people to play.',
                50,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::AUDIENCE_TEN,
                'Ten players',
                'Ten people finished something you made.',
                100,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::AUDIENCE_HUNDRED,
                'A hundred players',
                'A hundred people finished something you made.',
                400,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::AUDIENCE_THOUSAND,
                'A thousand players',
                'A thousand people finished something you made.',
                1500,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::CANONISED,
                'Canon',
                'A story you made joined the canon.',
                1000,
                Achievement::SUBJECT_STORY,
            ),
            new Achievement(
                self::CONTRIBUTION_ACCEPTED,
                'Accepted',
                'An author took your changes into their story.',
                80,
                Achievement::SUBJECT_STORY,
            ),
        ]);
    }

    public function get(string $code): Achievement
    {
        return $this->all()[$code] ?? throw NotFound::of('Achievement', $code);
    }

    /**
     * How many finishers each audience tier needs.
     *
     * @return array<string, int>
     */
    public function audienceTiers(): array
    {
        return [
            self::AUDIENCE_TEN => 10,
            self::AUDIENCE_HUNDRED => 100,
            self::AUDIENCE_THOUSAND => 1000,
        ];
    }

    /**
     * Which placing earns what. Ordered best first, so the first match wins and
     * a runner who takes first place is not also handed the top-three award for
     * the same board on the same day.
     *
     * @return array<string, int>
     */
    public function placingTiers(): array
    {
        return [
            self::FIRST_PLACE => 1,
            self::TOP_THREE => 3,
            self::TOP_TEN => 10,
        ];
    }

    /**
     * @param list<Achievement> $list
     *
     * @return array<string, Achievement>
     */
    private function index(array $list): array
    {
        $byCode = [];

        foreach ($list as $achievement) {
            $byCode[$achievement->code] = $achievement;
        }

        return $byCode;
    }
}
