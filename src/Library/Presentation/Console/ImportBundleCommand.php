<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use JsonException;
use Ramsey\Uuid\Uuid;
use Throwable;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Media\Application\Command\UploadMedia;
use Wob\Media\Application\Handler\UploadMediaHandler;
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
 * It also brings the files. That half went missing when importing was split in
 * two: the old single command converted, uploaded the pictures over HTTP and
 * posted the bundle, and when the work was divided the converter took the
 * building and this command took the posting, while the uploading stayed behind
 * in the tool that was about to be deleted. A bundle on its own still imports
 * and still looks right in the database — it just refers to files by the path
 * they had in somebody else's folder, so every picture in it is a blank.
 *
 * The files arrive here rather than at the converter for the plain reason that
 * this is the half with a database. The converter needs no server at all, and
 * that is worth keeping.
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
        {--media= : media.json from the converter — the files the bundle refers to}
        {--media-root= : folder those file paths are relative to}
        {--release : publish a release and mark it cleared by its author}
        {--canon : after releasing, work out whether it takes the canon crown}
        {--dry-run : read and check the file, change nothing}';

    protected $description = 'Import a bundle into the library, optionally releasing it';

    private UploadMediaHandler $upload;

    /**
     * The files named by the converter, checked before anything is written.
     *
     * Checked all at once and up front on purpose: a missing file found halfway
     * through leaves half a library's worth of uploads on the disk and a
     * message about one path, and the usual cause is not one missing file but a
     * --media-root pointing at the wrong folder, which this way says so before
     * touching anything.
     *
     * @return array<string, string>|null relative path to absolute one, or null if something is wrong
     */
    private function filesToUpload(): ?array
    {
        $list = (string) $this->option('media');

        if ($list === '') {
            return [];
        }

        if (!is_file($list)) {
            $this->error("No such media list: {$list}");

            return null;
        }

        $root = rtrim((string) $this->option('media-root'), '/');

        if ($root === '') {
            $this->error('--media needs --media-root: the paths in it are relative to the folder they were read from');

            return null;
        }

        try {
            $named = json_decode((string) file_get_contents($list), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('That media list is not JSON: ' . $e->getMessage());

            return null;
        }

        if (!is_array($named)) {
            $this->error('A media list is an object of names to paths; this file is not.');

            return null;
        }

        $files = [];
        $absent = [];

        foreach ($named as $path) {
            if (!is_string($path) || $path === '' || isset($files[$path])) {
                continue;
            }

            $full = $root . '/' . $path;

            if (!is_file($full)) {
                $absent[] = $path;

                continue;
            }

            $files[$path] = $full;
        }

        if ($absent !== []) {
            $this->error(sprintf('%d of those files are not under %s, starting with:', count($absent), $root));

            foreach (array_slice($absent, 0, 5) as $path) {
                $this->line('  ' . $path);
            }

            return null;
        }

        return $files;
    }

    /**
     * Upload every file, then point the bundle at what came back.
     *
     * The substitution walks the whole document replacing one exact string with
     * another, and knows nothing about entities — which is not laziness but the
     * rule this server runs on. `entities` is stored untouched and its shape is
     * the client's business; a loop over "the fields that hold pictures" would
     * put a list of somebody else's field names in here and break quietly the
     * day one of them was renamed.
     *
     * A path is replaced wherever it appears, including in a field that has
     * nothing to do with media. That is fine and would still be fine if it
     * happened: the strings being replaced are paths of files that exist in the
     * source folder, so anything spelt exactly like one is one.
     *
     * @param array<string, string> $files
     */
    private function withUploadedMedia(object $bundle, OwnerId $owner, array $files): object
    {
        $urls = [];
        $done = 0;

        foreach ($files as $path => $full) {
            $urls[$path] = '/api/media/' . ($this->upload)(new UploadMedia(
                $owner,
                // Marked as a test upload, which is the only way to hand a file
                // already on disk to the same handler the HTTP route uses. The
                // alternative — writing the bytes and the row from here — would
                // be a second copy of the size limits and the format list, and
                // the two would drift.
                new UploadedFile($full, basename($path), null, null, true),
            ))->id()->value;

            $done++;

            if ($done % 100 === 0) {
                $this->line("  uploaded {$done}/" . count($files));
            }
        }

        $this->line('files uploaded: ' . count($urls));

        return $this->substitute($bundle, $urls);
    }

    /**
     * @param array<string, string> $urls
     */
    private function substitute(mixed $value, array $urls): mixed
    {
        if (is_string($value)) {
            return $urls[$value] ?? $value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->substitute($item, $urls), $value);
        }

        if (is_object($value)) {
            $out = new \stdClass();

            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = $this->substitute($item, $urls);
            }

            return $out;
        }

        return $value;
    }

    public function handle(
        ImportBundleHandler $import,
        PublishReleaseHandler $publish,
        ReevaluateCanonHandler $canon,
        ReleaseRepository $releases,
        ConnectionInterface $db,
        Clock $clock,
        UploadMediaHandler $upload,
    ): int {
        $this->upload = $upload;

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

        $files = $this->filesToUpload();

        if ($files === null) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            if ($files !== []) {
                $this->line('files to upload: ' . count($files));
            }

            $this->info('Read fine. Nothing was changed.');

            return self::SUCCESS;
        }

        $owner = $this->ownerFor($db, (string) ($this->option('user') ?: 'importer@wob.local'));

        if ($files !== []) {
            $bundle = $this->withUploadedMedia($bundle, new OwnerId($owner), $files);
        }

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
