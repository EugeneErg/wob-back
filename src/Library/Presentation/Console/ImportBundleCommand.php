<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Ramsey\Uuid\Uuid;
use Throwable;
use Wob\Library\Application\Command\ImportBundle;
use Wob\Library\Application\DTO\ImportResult;
use Wob\Library\Application\Handler\ImportBundleHandler;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Application\Handler\ReevaluateCanonHandler;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Shared\Domain\Clock;

/**
 * Load a bundle straight into the library.
 *
 * The other half of importing — turning someone else's file format into ours —
 * is a separate command and stays separate. That split is the whole point: it
 * puts a file between the two halves, and a file can be opened and looked at.
 * When an import used to go wrong there was nothing to inspect, only a report
 * saying how many things did not make it.
 *
 * This half talks to the database directly rather than through HTTP. It is not
 * a shortcut: publishing a release and moving the canon crown are not offered
 * over the API at all, and for good reason — they are decisions, not edits.
 * Offering them here, named and visible in --help, is the honest way to let an
 * import make them.
 *
 * On the release gate. A release stays shut to everyone but its author until
 * the author has cleared it themselves, and that rule is deliberate: a story
 * its own maker cannot finish is not ready for anyone else. --release opens
 * that gate on purpose and says so out loud. It is not a way around the rule;
 * it is the rule being applied by someone who has decided the import counts.
 */
final class ImportBundleCommand extends Command
{
    protected $signature = 'wob:import
        {file : bundle.json produced by the converter}
        {--user= : email of the author to import as; created if unknown}
        {--release : publish a release and mark it cleared by its author}
        {--canon : after releasing, work out whether it takes the canon crown}
        {--dry-run : read and check the file, change nothing}';

    protected $description = 'Import a bundle into the library, optionally releasing it';

    public function handle(
        ImportBundleHandler $import,
        PublishReleaseHandler $publish,
        ReevaluateCanonHandler $canon,
        ReleaseRepository $releases,
        ConnectionInterface $db,
        Clock $clock,
    ): int {
        $path = (string) $this->argument('file');

        if (!is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        try {
            $bundle = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('That file is not JSON: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (!is_object($bundle)) {
            $this->error('A bundle is an object with stories in it; this file is not.');

            return self::FAILURE;
        }

        $stories = is_array($bundle->stories ?? null) ? count($bundle->stories) : 0;
        $assets = is_array($bundle->assets ?? null) ? count($bundle->assets) : 0;
        // Уровни и главы лежат в пакете плоскими списками рядом с историями, а
        // не внутри них: история называет свои главы, глава — свои уровни. Я
        // сперва считал их у истории и получал ноль на полном пакете — цифра
        // «уровней 0» рядом с успешным импортом пятидесяти восьми выглядела бы
        // как поломка, которой нет.
        $levels = is_array($bundle->levels ?? null) ? count($bundle->levels) : 0;
        $chapters = is_array($bundle->chapters ?? null) ? count($bundle->chapters) : 0;

        $this->line("stories {$stories}, chapters {$chapters}, levels {$levels}, assets {$assets}");

        if ($this->option('dry-run')) {
            $this->info('Read fine. Nothing was changed.');

            return self::SUCCESS;
        }

        $owner = $this->ownerFor($db, (string) ($this->option('user') ?: 'importer@wob.local'));

        try {
            // One transaction for the lot. A half-imported story is worse than
            // no story: it looks present in the library and falls apart when
            // somebody opens it.
            $result = $db->transaction(fn (): ImportResult => ($import)(new ImportBundle($owner, $bundle)));
        } catch (Throwable $e) {
            $this->error('Import refused: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($result->warnings as $warning) {
            $this->warn('  ! ' . $warning);
        }

        $this->info('Imported ' . count($result->stories) . ' story(ies).');

        if (!$this->option('release')) {
            return self::SUCCESS;
        }

        foreach ($result->stories as $story) {
            $storyId = $story['id'];
            $release = ($publish)(new PublishRelease($owner, (string) $storyId));

            // The gate, opened deliberately and named. Without this the release
            // exists but nobody except its author can see it, which for an
            // import is almost always not what was wanted — and silently
            // leaving it shut would look like the import had failed.
            $release->clearedByAuthor($clock->now());
            $releases->save($release);

            $this->info("  released {$storyId}");

            if ($this->option('canon')) {
                $crowned = ($canon)($release->id);
                $this->line('  canon: ' . ($crowned ? 'yes' : 'not yet'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Whose library this lands in.
     *
     * Created if unknown, because the alternative is telling somebody setting
     * up a fresh machine to go and make a user first, by hand, in a way the
     * application does not otherwise ask for.
     */
    private function ownerFor(ConnectionInterface $db, string $email): string
    {
        $found = $db->table('users')->where('email', $email)->value('id');

        if ($found !== null) {
            $this->line("author: {$email}");

            return (string) $found;
        }

        $id = Uuid::uuid4()->toString();
        $db->table('users')->insert([
            'id' => $id,
            'google_sub' => 'import-' . md5($email),
            'email' => $email,
            'display_name' => explode('@', $email)[0],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("author: {$email} (new)");

        return $id;
    }
}
