<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use ZipArchive;

/**
 * A ZIP upload for the import wizard: the spreadsheet of activities together with the certificate
 * files its `certificates` column names.
 *
 * The archive is unpacked once, when it is uploaded, into a directory of its own under
 * `adm_my_files/requirements/import/`, which the later wizard steps read from and the import or a
 * new run deletes again. Nothing in it is ever stored under the name the archive gave it: every
 * entry is written to a numbered file and only the map from its path in the archive to that file
 * is kept, so no entry name - `../../config.php` included - ever reaches a path. The entries are
 * copied as streams with the bytes counted, because the sizes an archive declares are written by
 * whoever made it.
 */
final class ImportArchive
{
    /** Entries an archive may have, directories included. */
    public const MAX_ENTRIES = 1000;

    /** What all entries together may unpack to. */
    public const MAX_TOTAL_BYTES = 268435456;

    /** The extensions the spreadsheet of an archive is recognised by. */
    private const SPREADSHEET_EXTENSIONS = array('csv', 'xlsx', 'xls', 'ods');

    /** An unpacked archive older than this belongs to a wizard nobody finished. */
    private const STALE_SECONDS = 86400;

    /**
     * Whether an upload is to be treated as an archive. Decided by the name the operator gave it:
     * an .xlsx or .ods file is a ZIP container as well, so the content cannot tell the two apart.
     */
    public static function isArchiveName(string $name): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
    }

    /**
     * Unpack an uploaded archive.
     *
     * @return array{directory:string,spreadsheet:string,files:array<string,string>} the directory
     *     to delete afterwards, the unpacked spreadsheet, and every other entry as its path in the
     *     archive => the unpacked file
     * @throws Exception
     */
    public static function extract(string $zipPath): array
    {
        self::sweepStale();

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new Exception('PLG_REQUIREMENTS_IMPORT_ARCHIVE_UNREADABLE');
        }

        $directory = self::baseDirectory() . '/' . bin2hex(random_bytes(16));
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new Exception('PLG_REQUIREMENTS_IMPORT_ARCHIVE_TOO_LARGE');
            }
            FileSystemUtils::createDirectoryIfNotExists($directory, array('mode' => 0700));

            $spreadsheets = array();
            $files = array();
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = self::entryName((string) $zip->getNameIndex($index));
                if ($entry === null) {
                    continue;
                }

                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                $isSpreadsheet = in_array($extension, self::SPREADSHEET_EXTENSIONS, true);
                // The spreadsheet keeps its extension, which is how the reader tells CSV from XLSX.
                $target = $directory . '/' . $index . ($isSpreadsheet ? '.' . $extension : '');
                $total += self::copyEntry($zip, $index, $target, self::MAX_TOTAL_BYTES - $total);

                if ($isSpreadsheet) {
                    $spreadsheets[$entry] = $target;
                } else {
                    $files[$entry] = $target;
                }
            }

            if (count($spreadsheets) !== 1) {
                throw new Exception(
                    $spreadsheets === array()
                        ? 'PLG_REQUIREMENTS_IMPORT_ARCHIVE_NO_SPREADSHEET'
                        : 'PLG_REQUIREMENTS_IMPORT_ARCHIVE_SEVERAL_SPREADSHEETS'
                );
            }
        } catch (\Throwable $exception) {
            $zip->close();
            self::remove($directory);
            throw $exception;
        }
        $zip->close();

        return array('directory' => $directory, 'spreadsheet' => reset($spreadsheets), 'files' => $files);
    }

    /**
     * The unpacked file a `certificates` cell names: its path in the archive, or - because people
     * write what they see in their file manager - just its file name, as long as only one entry of
     * the archive has that name. Letter case does not matter for the file name alone.
     *
     * @param array<string,string> $files as extract() returned them
     * @return array{0:string,1:string} the entry's path in the archive and the unpacked file
     * @throws Exception
     */
    public static function find(array $files, string $name): array
    {
        $wanted = self::entryName($name) ?? '';
        if (isset($files[$wanted])) {
            return array($wanted, $files[$wanted]);
        }

        $matches = array();
        foreach ($files as $entry => $path) {
            if (strcasecmp(basename($entry), basename($wanted)) === 0) {
                $matches[$entry] = $path;
            }
        }
        if (count($matches) === 1) {
            return array((string) array_key_first($matches), reset($matches));
        }

        throw new Exception(
            $matches === array()
                ? 'PLG_REQUIREMENTS_IMPORT_CERTIFICATE_NOT_FOUND'
                : 'PLG_REQUIREMENTS_IMPORT_CERTIFICATE_AMBIGUOUS',
            array($name)
        );
    }

    /**
     * Delete an unpacked archive. Only a directory below the import directory is touched, whatever
     * the session handed in.
     */
    public static function remove(string $directory): void
    {
        $base = self::baseDirectory();
        $directory = FileSystemUtils::getNormalizedPath($directory);
        if (!str_starts_with($directory, $base . '/') || !is_dir($directory)) {
            return;
        }
        try {
            FileSystemUtils::deleteDirectoryIfExists($directory, true);
        } catch (\Throwable) {
            // Left for sweepStale(): a temporary directory nobody points at is no reason to fail.
        }
    }

    /**
     * An entry name made comparable - forward slashes, no leading "./" - or null for an entry that
     * is not a file to import: a directory, the resource forks a Mac adds, a hidden file. An
     * entry that tries to leave the archive is refused outright rather than skipped, because a file
     * built that way is not one somebody exported by accident.
     *
     * @throws Exception
     */
    private static function entryName(string $name): ?string
    {
        $name = trim(str_replace('\\', '/', $name));
        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }
        if ($name === '' || str_ends_with($name, '/')) {
            return null;
        }
        if (str_starts_with($name, '/') || str_contains($name, ':') || in_array('..', explode('/', $name), true)) {
            throw new Exception('PLG_REQUIREMENTS_IMPORT_ARCHIVE_UNSAFE', array($name));
        }
        if (str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '.')) {
            return null;
        }
        return $name;
    }

    /**
     * Copy one entry out of the archive, refusing it as soon as it is larger than what is left of
     * the total.
     *
     * @return int the bytes written
     * @throws Exception
     */
    private static function copyEntry(ZipArchive $zip, int $index, string $target, int $remaining): int
    {
        $in = $zip->getStreamIndex($index);
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new Exception('PLG_REQUIREMENTS_IMPORT_ARCHIVE_UNREADABLE');
        }

        $written = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new Exception('PLG_REQUIREMENTS_IMPORT_ARCHIVE_UNREADABLE');
                }
                $written += strlen($chunk);
                if ($written > $remaining) {
                    throw new Exception('PLG_REQUIREMENTS_IMPORT_ARCHIVE_TOO_LARGE');
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $written;
    }

    /**
     * Delete what wizards that were never finished left behind.
     */
    private static function sweepStale(): void
    {
        $base = self::baseDirectory();
        if (!is_dir($base)) {
            return;
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: array() as $directory) {
            if (filemtime($directory) < time() - self::STALE_SECONDS) {
                self::remove($directory);
            }
        }
    }

    /**
     * Next to, not inside, the organizations' certificate storage, so files-gc never sees an
     * archive that is still being imported.
     */
    private static function baseDirectory(): string
    {
        return FileSystemUtils::getNormalizedPath(ADMIDIO_PATH . FOLDER_DATA . '/requirements/import');
    }
}
