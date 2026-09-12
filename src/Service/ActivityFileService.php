<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\Infrastructure\Utils\PhpIniUtils;
use Admidio\Hooks\Hooks;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use Throwable;

/**
 * The certificate files of an activity: upload, removal, download and the sweep that reconciles the
 * two places a file lives.
 *
 * The files are the plugin's own, under `adm_my_files/requirements/`, and not in the Documents
 * module. The Documents permission model is role-based, and what this needs is owner-based - "the
 * member whose activity it is, plus the requirements administrators" - which is `Activity` already
 * knows and a folder role cannot express without a role per activity. Putting them there would also
 * surface one member's evidence in another member's file browser.
 *
 * Two rules run through everything here:
 *
 * - **the activity decides.** `isVisible()` gates every read and `isEditable()` every write. This
 *   class adds the file-specific checks - type, size, count, quota - and nothing about who;
 * - **the row is the truth, the file follows it.** A write saves the row and moves the file inside
 *   one transaction; a delete removes the row first and unlinks afterwards. A crash in between
 *   therefore leaves an orphaned *file*, never an orphaned row: a file nothing points at is
 *   invisible and sweepable, whereas a row whose file is gone is a broken download link.
 */
final class ActivityFileService
{
    public const SETTING_ENABLED = 'requirements_files_enabled';
    public const SETTING_MAX_COUNT = 'requirements_files_max_count';
    public const SETTING_MAX_SIZE = 'requirements_files_max_size';
    public const SETTING_TOTAL_QUOTA = 'requirements_files_total_quota';
    public const SETTING_ALLOWED_TYPES = 'requirements_files_allowed_types';

    private const BYTES_PER_MIB = 1048576;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Whether the feature is switched on for this organization at all.
     *
     * @throws Exception
     */
    public static function isEnabled(): bool
    {
        return RequirementsPlugin::isUsableByCurrentUser()
            && (bool) RequirementsPlugin::setting(self::SETTING_ENABLED);
    }

    /**
     * Attach an uploaded file to an activity.
     *
     * @param array{name:string,tmp_name:string,size:int,error?:int} $upload one entry of $_FILES,
     *        or the same shape built by the CLI around a local path
     * @throws Exception
     */
    public function add(Activity $activity, array $upload): ActivityFile
    {
        if (!self::isEnabled()) {
            throw new Exception('PLG_REQUIREMENTS_FILES_DISABLED');
        }
        if ((int) $activity->getValue('rqa_id') === 0 || !$activity->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->assertUploadSucceeded($upload);

        $source = (string) $upload['tmp_name'];
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || !is_file($source)) {
            throw new Exception('SYS_FILE_NOT_EXIST');
        }

        $this->assertWithinSize($size);
        $this->assertWithinCount((int) $activity->getValue('rqa_id'));
        $this->assertWithinQuota($size);

        // The sniffed type, not the browser's Content-Type and not the extension: both are supplied
        // by whoever is uploading, and an executable named "certificate.pdf" would pass either.
        $mimeType = $this->sniffMimeType($source);
        $allowed = self::allowedMimeTypes();
        if (!isset($allowed[$mimeType])) {
            throw new Exception('PLG_REQUIREMENTS_FILES_TYPE_REJECTED', array(
                $mimeType,
                implode(', ', $allowed)
            ));
        }

        $this->assertPassesInspection($source, $mimeType, (string) $upload['name']);

        $file = new ActivityFile($this->db);
        $file->setValue('raf_activity_id', (int) $activity->getValue('rqa_id'));
        // The name is kept only to give the download a sensible filename; it never reaches a path.
        $file->setValue('raf_name', $this->safeDisplayName((string) $upload['name']));
        $file->setValue('raf_mime_type', $mimeType);
        $file->setValue('raf_size', $size);

        $directory = $this->directoryFor($activity);
        $moved = false;
        $target = '';

        $this->db->startTransaction();
        try {
            // save() first, because the row is what mints the UUID the file is stored under.
            $file->save();
            $target = self::join($directory, $file->getStoredName());

            FileSystemUtils::createDirectoryIfNotExists($directory, array('mode' => 0700));
            $moved = is_uploaded_file($source)
                ? move_uploaded_file($source, $target)
                : copy($source, $target);
            if (!$moved) {
                throw new Exception('SYS_FILE_NOT_EXIST');
            }
            // Only where the mode means something. chmodFile() refuses outright on a system
            // without POSIX, and a Windows host would fail the upload over a permission bit that
            // does not exist there - the directory is outside the document root either way.
            if (FileSystemUtils::isUnixWithPosix()) {
                FileSystemUtils::chmodFile($target, 0600);
            }

            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            if ($moved && $target !== '' && is_file($target)) {
                @unlink($target);
            }
            throw $exception;
        }

        return $file;
    }

    /**
     * Turn one `$_FILES` entry into a list of uploads, whether the field was single or multiple.
     *
     * PHP transposes a multi-file field into parallel arrays - `name` is a list, `tmp_name` is a
     * list - which is the shape nothing else wants. Entries the browser sent empty are dropped, so
     * a form submitted with no file chosen produces an empty list rather than one blank upload.
     *
     * @param array<string,mixed> $field one entry of $_FILES
     * @return array<int,array{name:string,tmp_name:string,size:int,error:int}>
     */
    public static function uploadsFrom(array $field): array
    {
        if (!isset($field['name'])) {
            return array();
        }

        $names = is_array($field['name']) ? $field['name'] : array($field['name']);
        $uploads = array();

        foreach (array_keys($names) as $index) {
            $pick = static function (string $key) use ($field, $index): mixed {
                $value = $field[$key] ?? null;
                return is_array($value) ? ($value[$index] ?? null) : $value;
            };

            $error = (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($pick('tmp_name') ?? '');
            if ($error === UPLOAD_ERR_NO_FILE || ($error === UPLOAD_ERR_OK && $tmpName === '')) {
                continue;
            }

            $uploads[] = array(
                'name' => (string) ($pick('name') ?? ''),
                'tmp_name' => $tmpName,
                'size' => (int) ($pick('size') ?? 0),
                'error' => $error
            );
        }

        return $uploads;
    }

    /**
     * Remove one file: its row, then the file itself.
     *
     * @throws Exception
     */
    public function delete(ActivityFile $file): void
    {
        $activity = $this->activityOf($file);
        if (!$activity->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $path = $this->pathFor($file, $activity);

        $this->db->startTransaction();
        try {
            $file->delete();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        // After the commit, so a failed unlink cannot undo a delete the user was already told about.
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The files of one activity, oldest first.
     *
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    public function listForActivity(int $activityId): array
    {
        return $this->db->getArrayFromSql(
            'SELECT raf_id, raf_uuid, raf_name, raf_mime_type, raf_size, raf_timestamp_create
               FROM ' . RequirementsTables::activityFiles() . '
              WHERE raf_activity_id = ?
           ORDER BY raf_timestamp_create, raf_id',
            array($activityId)
        );
    }

    /**
     * Send one file to the browser.
     *
     * This is the only code that opens an evidence file for reading, and it re-asks the permission
     * question itself rather than trusting the caller to have asked it.
     *
     * @throws Exception
     */
    public function streamDownload(ActivityFile $file): void
    {
        $activity = $this->activityOf($file);
        if (!$activity->isVisible()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $path = $this->pathFor($file, $activity);
        if (!is_file($path)) {
            throw new Exception('SYS_FILE_NOT_EXIST');
        }

        // Always an attachment, never inline: a crafted file that a browser decides to render would
        // otherwise execute in Admidio's own origin, with the session cookie attached.
        header('Content-Type: ' . $file->getValue('raf_mime_type'));
        header('Content-Disposition: attachment; filename="' . $this->headerName($file) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        readfile($path);
        exit();
    }

    /**
     * Reconcile the two places a file lives: rows whose file is gone, and files no row points at.
     *
     * Both are the debris of an interrupted write, and neither is reachable through the UI - which
     * is why nothing else would ever find them.
     *
     * @return array{missingFiles:array<int,string>,orphanFiles:array<int,string>}
     * @throws Exception
     */
    public function collectGarbage(bool $dryRun = true): array
    {
        global $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $result = array('missingFiles' => array(), 'orphanFiles' => array());

        // Rows of this organization whose file is not on disk.
        $rows = $this->db->getArrayFromSql(
            'SELECT raf.raf_id, raf.raf_uuid, raf.raf_mime_type, rqa.rqa_uuid
               FROM ' . RequirementsTables::activityFiles() . ' AS raf
         INNER JOIN ' . RequirementsTables::activities() . ' AS rqa ON rqa.rqa_id = raf.raf_activity_id
              WHERE rqa.rqa_org_id = ?',
            array((int) $gCurrentOrgId)
        );

        $known = array();
        foreach ($rows as $row) {
            $extension = ActivityFile::extensionForMimeType((string) $row['raf_mime_type']);
            $stored = (string) $row['raf_uuid'] . ($extension === '' ? '' : '.' . $extension);
            $path = self::join($this->organizationDirectory(), (string) $row['rqa_uuid'], $stored);
            $known[$path] = true;

            if (!is_file($path)) {
                $result['missingFiles'][] = (string) $row['raf_uuid'];
                if (!$dryRun) {
                    (new ActivityFile($this->db, (int) $row['raf_id']))->delete();
                }
            }
        }

        // Files on disk that no row of this organization claims.
        //
        // The tree is walked one level at a time rather than with getDirectoryContent()'s recursive
        // mode, which nests its result - a directory maps to an array of its entries, not to a type
        // - so a flat loop over it would take each activity directory for a file. The layout is
        // exactly two levels deep, so saying so is clearer than flattening.
        foreach ($this->storedFiles() as $path) {
            if (isset($known[$path])) {
                continue;
            }
            $result['orphanFiles'][] = basename($path);
            if (!$dryRun) {
                @unlink($path);
            }
        }

        return $result;
    }

    /**
     * Every file under this organization's storage: the ones in an activity directory, and any that
     * ended up directly in the organization directory.
     *
     * @return array<int,string> normalized absolute paths
     */
    private function storedFiles(): array
    {
        $base = $this->organizationDirectory();
        if (!is_dir($base)) {
            return array();
        }

        $files = array();
        foreach ($this->entriesOf($base, FileSystemUtils::CONTENT_TYPE_FILE) as $path) {
            $files[] = $path;
        }
        foreach ($this->entriesOf($base, FileSystemUtils::CONTENT_TYPE_DIRECTORY) as $directory) {
            foreach ($this->entriesOf($directory, FileSystemUtils::CONTENT_TYPE_FILE) as $path) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<int,string> normalized absolute paths of the entries of one type
     */
    private function entriesOf(string $directory, string $type): array
    {
        if (!is_dir($directory)) {
            return array();
        }

        $entries = FileSystemUtils::getDirectoryContent($directory, false, true, array($type));

        return array_map(
            static fn(string $path): string => FileSystemUtils::getNormalizedPath($path),
            array_keys($entries)
        );
    }

    /**
     * The absolute path of one file.
     *
     * Every segment is a value this code produced - the organization id, the activity UUID and the
     * file UUID - so nothing a member typed reaches the path. The result is checked against the
     * plugin's own directory anyway, because a path built from trusted parts is only trusted until
     * somebody adds a part.
     *
     * @throws Exception
     */
    public function pathFor(ActivityFile $file, ?Activity $activity = null): string
    {
        $activity ??= $this->activityOf($file);

        $path = self::join(
            $this->organizationDirectory(),
            (string) $activity->getValue('rqa_uuid'),
            $file->getStoredName()
        );

        // getNormalizedPath() joins with DIRECTORY_SEPARATOR, which is a backslash on Windows, so
        // the separator has to come from there too - comparing against a hard-coded "/" passes on
        // Linux and refuses every path on Windows.
        if (!str_starts_with($path, $this->organizationDirectory() . DIRECTORY_SEPARATOR)) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        return $path;
    }

    /**
     * The directory holding the evidence of one activity.
     *
     * @throws Exception
     */
    private function directoryFor(Activity $activity): string
    {
        return self::join($this->organizationDirectory(), (string) $activity->getValue('rqa_uuid'));
    }

    /**
     * Join path segments into one normalized absolute path.
     *
     * Every path in this class goes through here, so that the separator, and therefore any
     * comparison between two of them, is the same on every platform.
     */
    private static function join(string ...$segments): string
    {
        return FileSystemUtils::getNormalizedPath(implode('/', $segments));
    }

    /**
     * The plugin's storage root for the current organization.
     *
     * It lives under `adm_my_files/`, which a correct installation keeps out of the document root
     * and which carries its own `.htaccess` - so no file here is reachable by URL, guessable or
     * otherwise, and every download goes through `streamDownload()`.
     */
    public function organizationDirectory(): string
    {
        global $gCurrentOrgId;

        return FileSystemUtils::getNormalizedPath(
            ADMIDIO_PATH . FOLDER_DATA . '/requirements/' . (int) $gCurrentOrgId
        );
    }

    /**
     * @throws Exception
     */
    private function activityOf(ActivityFile $file): Activity
    {
        if ((int) $file->getValue('raf_id') === 0) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        // OrganizationScope answers a file of another organization with an empty record, so this is
        // reached only for a file of this one.
        $activity = new Activity($this->db, (int) $file->getValue('raf_activity_id'));
        if ((int) $activity->getValue('rqa_id') === 0) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        return $activity;
    }

    /**
     * @param array<string,mixed> $upload
     * @throws Exception
     */
    private function assertUploadSucceeded(array $upload): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_OK) {
            return;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception('SYS_FILE_TO_LARGE_SERVER', array(ini_get('upload_max_filesize')));
        }
        throw new Exception('SYS_FILE_NOT_EXIST');
    }

    /**
     * @throws Exception
     */
    private function assertWithinSize(int $size): void
    {
        $limit = (int) RequirementsPlugin::setting(self::SETTING_MAX_SIZE) * self::BYTES_PER_MIB;
        if ($limit > 0 && $size > $limit) {
            throw new Exception('SYS_FILE_TO_LARGE', array((int) ($limit / self::BYTES_PER_MIB)));
        }

        // The PHP limit is the one the upload has already survived, but a CLI add has not.
        $phpLimit = PhpIniUtils::getFileUploadMaxFileSize();
        if ($phpLimit > 0 && $size > $phpLimit) {
            throw new Exception('SYS_FILE_TO_LARGE_SERVER', array(ini_get('upload_max_filesize')));
        }
    }

    /**
     * @throws Exception
     */
    private function assertWithinCount(int $activityId): void
    {
        $limit = (int) RequirementsPlugin::setting(self::SETTING_MAX_COUNT);
        if ($limit <= 0) {
            return;
        }

        $count = (int) $this->db->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::activityFiles() . ' WHERE raf_activity_id = ?',
            array($activityId)
        )->fetchColumn();

        if ($count >= $limit) {
            throw new Exception('PLG_REQUIREMENTS_FILES_TOO_MANY', array($limit));
        }
    }

    /**
     * The organization's total evidence storage, so one organization cannot fill the disk.
     *
     * @throws Exception
     */
    private function assertWithinQuota(int $size): void
    {
        global $gCurrentOrgId;

        $quota = (int) RequirementsPlugin::setting(self::SETTING_TOTAL_QUOTA) * self::BYTES_PER_MIB;
        if ($quota <= 0) {
            return;
        }

        $used = (int) $this->db->queryPrepared(
            'SELECT COALESCE(SUM(raf.raf_size), 0)
               FROM ' . RequirementsTables::activityFiles() . ' AS raf
         INNER JOIN ' . RequirementsTables::activities() . ' AS rqa ON rqa.rqa_id = raf.raf_activity_id
              WHERE rqa.rqa_org_id = ?',
            array((int) $gCurrentOrgId)
        )->fetchColumn();

        if ($used + $size > $quota) {
            throw new Exception('PLG_REQUIREMENTS_FILES_QUOTA_EXCEEDED', array(
                FileSystemUtils::getHumanReadableBytes($quota)
            ));
        }
    }

    /**
     * The types this organization accepts: what the plugin can store, narrowed by the setting.
     *
     * An empty setting means every supported type, which is what a fresh installation has. A token
     * the plugin cannot store is ignored rather than refused - the setting is a list of what to
     * allow, and an administrator who writes "docx" has expressed a wish, not created an error that
     * should stop every upload.
     *
     * @return array<string,string> sniffed MIME type => stored extension
     * @throws Exception
     */
    public static function allowedMimeTypes(): array
    {
        $configured = (string) RequirementsPlugin::setting(self::SETTING_ALLOWED_TYPES);

        $extensions = array();
        foreach (explode(',', $configured) as $token) {
            $extension = ActivityFile::canonicalExtension($token);
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
        }
        if ($extensions === array()) {
            return ActivityFile::mimeTypes();
        }

        return array_filter(
            ActivityFile::mimeTypes(),
            static fn(string $extension): bool => isset($extensions[$extension])
        );
    }

    /**
     * Let anything that wants to inspect the file say no.
     *
     * The plugin does not scan uploads itself, and neither does Admidio - a certificate upload
     * behaves like a profile photo today. But a site that runs a scanner needs somewhere to put it,
     * and the only useful place is here: after the type is known and before the file is moved
     * anywhere, while it is still a temporary file nothing points at.
     *
     * A listener returns the reason it refuses the file, or '' to let it through. The filter is
     * typed, so a listener that answers with something other than a string is a loud error rather
     * than a value that happens to be truthy - and a listener that throws aborts the upload, which
     * is the right way round for a scanner that cannot do its job.
     *
     * @throws Exception
     */
    private function assertPassesInspection(string $path, string $mimeType, string $name): void
    {
        $rejection = (string) Hooks::applyTypedFilters(
            'requirements_file_upload_rejection',
            '',
            $path,
            $mimeType,
            $name
        );

        if (trim($rejection) !== '') {
            throw new Exception('PLG_REQUIREMENTS_FILES_INSPECTION_REJECTED', array(trim($rejection)));
        }
    }

    /**
     * The MIME type of what is actually in the file.
     *
     * @throws Exception
     */
    private function sniffMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $sniffed = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($sniffed) && $sniffed !== '') {
                    return strtolower(explode(';', $sniffed)[0]);
                }
            }
        }

        // Without fileinfo nothing here can tell a PDF from a PHP script, so the upload is refused
        // rather than trusted. Guessing from the extension would be worse than not offering it.
        throw new Exception('PLG_REQUIREMENTS_FILES_TYPE_UNKNOWN');
    }

    /**
     * The original name, reduced to something safe to store and to print.
     */
    private function safeDisplayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return $name === '' ? 'certificate' : mb_substr($name, 0, 255);
    }

    /**
     * The file name for the Content-Disposition header, with the quoting characters removed.
     */
    private function headerName(ActivityFile $file): string
    {
        $name = str_replace(array('"', "\r", "\n"), '', (string) $file->getValue('raf_name'));

        return $name === '' ? $file->getStoredName() : $name;
    }
}
