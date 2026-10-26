<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * The activity list as a spreadsheet in the layout the importer reads (§12).
 *
 * The headers are the import field names, so a file exported here maps itself when it is imported
 * again: one row per activity, its credits in a `credit_X` / `period_X` pair per requirement, X
 * being the requirement's short name or, without one, its id. The values are written the way the
 * importer parses them - ISO dates and times as text, so no spreadsheet locale reinterprets them,
 * and a period as the value of its control ("2026", "2026-Q2", "2026-03", "2026-W12", a date).
 *
 * Two kinds of column carry what the import cannot take and are named so that it passes them
 * over: `member_name` next to `user_uuid`, because a UUID identifies a member to the importer and
 * nobody else, and an administrator's `accepted_X`, because accepting credits is the review's job
 * and not something a file may do.
 *
 * archive() adds the certificates: a ZIP with the spreadsheet and every certificate file under
 * `certificates/<activity>/`, the `certificates` column naming each activity's files by that path -
 * which is the archive the import wizard takes, so the export is also a complete backup.
 */
final class ActivityExportService
{
    /** Activities read per query, to keep the IN list of the detail queries bounded. */
    private const CHUNK = 500;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The activities the list shows for this filter, as a workbook with one worksheet.
     *
     * @param array<string,mixed> $filter the filter of ActivityService::findActivityRows()
     * @throws Exception
     */
    public function spreadsheet(array $filter): Spreadsheet
    {
        return $this->build($filter, false)[0];
    }

    /**
     * The same list with its certificates, written as a ZIP to $zipPath.
     *
     * @param array<string,mixed> $filter the filter of ActivityService::findActivityRows()
     * @throws Exception|\PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function archive(array $filter, string $zipPath): void
    {
        [$spreadsheet, $files] = $this->build($filter, true);

        // ZipArchive reads the files it is given only when it is closed, so the workbook has to
        // stay on disk until then.
        $workbook = tempnam(sys_get_temp_dir(), 'reqexport');
        try {
            (new Xlsx($spreadsheet))->save($workbook);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('SYS_FILE_NOT_EXIST');
            }
            $zip->addFile($workbook, 'activities.xlsx');
            foreach ($files as $entry => $path) {
                $zip->addFile($path, $entry);
                // A PDF or a JPEG is compressed already; deflating it again only costs time.
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
            if (!$zip->close()) {
                throw new Exception('SYS_FILE_NOT_EXIST');
            }
        } finally {
            @unlink($workbook);
        }
    }

    /**
     * @param array<string,mixed> $filter
     * @return array{0:Spreadsheet,1:array<string,string>} the workbook and, with certificates,
     *     every certificate as its path in the archive => the stored file
     * @throws Exception
     */
    private function build(array $filter, bool $withCertificates): array
    {
        $admin = Component::isAdministrable(RequirementsPlugin::ID);

        // The list decides which activities this user may see and which the filter keeps; the
        // export only reads the rest of what those activities hold.
        $listed = (new ActivityService($this->db))->findActivityRows($filter, '', array(array('date', 'desc')));
        $activityIds = array_values(array_unique(array_map('intval', array_column($listed['rows'], 'rqa_id'))));

        $activities = array();
        $credits = array();
        $files = array();
        $withCertificates = $withCertificates && ActivityFileService::isEnabled();
        $fileService = new ActivityFileService($this->db);
        foreach (array_chunk($activityIds, self::CHUNK) as $chunk) {
            if ($withCertificates) {
                $files += $fileService->listForActivities($chunk);
            }
            foreach ($this->activities($chunk) as $row) {
                $activities[(int) $row['rqa_id']] = $row;
            }
            foreach ($this->credits($chunk) as $row) {
                $credits[(int) $row['rac_activity_id']][] = $row;
            }
        }

        // The requirement columns: every requirement one of these activities is credited to, in the
        // order the requirements are listed everywhere else.
        $requirements = array();
        foreach ($credits as $activityCredits) {
            foreach ($activityCredits as $credit) {
                $requirements[(int) $credit['rqt_id']] = $credit;
            }
        }
        uasort($requirements, static fn(array $a, array $b): int =>
            array((int) $a['rqt_sequence'], (string) $a['rqt_name']) <=> array((int) $b['rqt_sequence'], (string) $b['rqt_name']));

        $headers = $admin ? array('user_uuid', 'member_name') : array();
        array_push(
            $headers,
            'title', 'begin_date', 'begin_time', 'end_date', 'end_time', 'certificate_date', 'attendance',
            'provider', 'url', 'location', 'description', 'comments'
        );
        if ($admin) {
            $headers[] = 'status';
        }
        $headers[] = 'source_key';
        if ($withCertificates) {
            $headers[] = 'certificates';
        }
        foreach ($requirements as $requirement) {
            $reference = self::reference($requirement);
            $headers[] = 'credit_' . $reference;
            $headers[] = 'period_' . $reference;
            if ($admin) {
                $headers[] = 'accepted_' . $reference;
            }
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('activities');
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1) . '1', $header, DataType::TYPE_STRING);
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $archiveFiles = array();
        $rowNumber = 1;
        foreach ($activityIds as $activityId) {
            if (!isset($activities[$activityId])) {
                continue;
            }
            $activity = $activities[$activityId];
            ++$rowNumber;

            $values = array();
            if ($admin) {
                $values['user_uuid'] = (string) $activity['user_uuid'];
                $values['member_name'] = trim($activity['last_name'] . ', ' . $activity['first_name'], ', ');
            }
            $provider = (string) ($activity['rqa_provider_short_name'] ?? '');
            $values += array(
                'title' => (string) $activity['rqa_title'],
                'begin_date' => (string) $activity['rqa_begin_date'],
                'begin_time' => substr((string) $activity['rqa_begin_time'], 0, 5),
                'end_date' => (string) $activity['rqa_end_date'],
                'end_time' => substr((string) $activity['rqa_end_time'], 0, 5),
                'certificate_date' => (string) $activity['rqa_certificate_date'],
                'attendance' => $activity['rqa_attendance'] === null ? null : (float) $activity['rqa_attendance'],
                'provider' => $provider !== '' ? $provider : (string) $activity['rqa_provider_name'],
                'url' => (string) $activity['rqa_url'],
                'location' => (string) $activity['rqa_location'],
                'description' => (string) $activity['rqa_description'],
                'comments' => (string) $activity['rqa_comments']
            );
            if ($admin) {
                $values['status'] = (string) $activity['rqa_status'];
            }
            $values['source_key'] = (string) $activity['rqa_source_key'];
            if ($withCertificates && isset($files[$activityId])) {
                $entries = $this->certificateEntries($activity, $files[$activityId], $fileService);
                $values['certificates'] = implode('; ', array_keys($entries));
                $archiveFiles += $entries;
            }

            foreach ($credits[$activityId] ?? array() as $credit) {
                $reference = self::reference($credit);
                $kind = FulfilmentService::periodControlKindFor(
                    (string) $credit['rqt_period_anchor'],
                    (string) $credit['rqt_period_unit']
                );
                $period = FulfilmentService::periodControlValue($kind, (string) $credit['rac_period_reference']);
                $values['credit_' . $reference] = (float) $credit['rac_credits_claimed'];
                $values['period_' . $reference] = $period['quarter'] !== ''
                    ? $period['value'] . '-Q' . $period['quarter']
                    : $period['value'];
                if ($admin && $credit['rac_credits_accepted'] !== null) {
                    $values['accepted_' . $reference] = (float) $credit['rac_credits_accepted'];
                }
            }

            foreach ($headers as $index => $header) {
                $value = $values[$header] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $coordinate = Coordinate::stringFromColumnIndex($index + 1) . $rowNumber;
                if (is_float($value)) {
                    $sheet->setCellValue($coordinate, $value);
                } else {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                }
            }
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        return array($spreadsheet, $archiveFiles);
    }

    /**
     * The certificates of one activity as their path in the archive => the stored file.
     *
     * Each activity gets a folder named by its date and title, so the archive can be read by a
     * person as well, with the start of its UUID to keep two seminars of the same day apart. A file
     * name is made into one the import reads back as written: no semicolon, which separates the
     * names in the cell, no colon or backslash, which the import refuses in a path, and no leading
     * dot, which marks a file the import passes over.
     *
     * @param array<string,mixed> $activity
     * @param array<int,array<string,mixed>> $files
     * @return array<string,string>
     * @throws Exception
     */
    private function certificateEntries(array $activity, array $files, ActivityFileService $fileService): array
    {
        $entity = new Activity($this->db);
        $entity->setArray($activity);

        $folder = 'certificates/' . self::safeName(
            $activity['rqa_begin_date'] . ' ' . mb_substr((string) $activity['rqa_title'], 0, 60)
        ) . '_' . substr((string) $activity['rqa_uuid'], 0, 8);

        $entries = array();
        foreach ($files as $file) {
            $stored = new ActivityFile($this->db);
            $stored->setArray($file);
            $path = $fileService->pathFor($stored, $entity);
            // A row whose file is gone is files-gc's to report; the export leaves it out rather
            // than fail the whole archive over it.
            if (!is_file($path)) {
                continue;
            }

            $name = ltrim(str_replace(array(';', ':', '\\'), array(',', '_', '_'), (string) $file['raf_name']), '.');
            $name = $name === '' ? $stored->getStoredName() : $name;
            $entry = $folder . '/' . $name;
            for ($copy = 2; isset($entries[$entry]); ++$copy) {
                $entry = $folder . '/' . pathinfo($name, PATHINFO_FILENAME) . ' (' . $copy . ')'
                    . (pathinfo($name, PATHINFO_EXTENSION) === '' ? '' : '.' . pathinfo($name, PATHINFO_EXTENSION));
            }
            $entries[$entry] = $path;
        }
        return $entries;
    }

    /**
     * A folder name that every file system and every unzip tool takes as it is.
     */
    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $name) ?? '';
        return trim(preg_replace('/\s+/', ' ', $name) ?? '', ' ._') ?: 'activity';
    }

    /**
     * The name a requirement goes by in a column header: its short name, which the importer looks
     * up first, or its id when it has none.
     */
    private static function reference(array $requirement): string
    {
        $shortName = trim((string) ($requirement['rqt_short_name'] ?? ''));
        return $shortName !== '' ? $shortName : (string) $requirement['rqt_id'];
    }

    /**
     * @param array<int,int> $activityIds
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    private function activities(array $activityIds): array
    {
        global $gProfileFields;

        return $this->db->getArrayFromSql(
            'SELECT rqa.*, usr.usr_uuid AS user_uuid,
                    first_name.usd_value AS first_name, last_name.usd_value AS last_name
               FROM ' . RequirementsTables::activities() . ' AS rqa
         INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = rqa.rqa_user_id
          LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                 ON first_name.usd_usr_id = rqa.rqa_user_id AND first_name.usd_usf_id = ?
          LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                 ON last_name.usd_usr_id = rqa.rqa_user_id AND last_name.usd_usf_id = ?
              WHERE rqa.rqa_id IN (' . implode(', ', array_fill(0, count($activityIds), '?')) . ')',
            array_merge(
                array(
                    (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
                    (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id')
                ),
                $activityIds
            )
        );
    }

    /**
     * @param array<int,int> $activityIds
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    private function credits(array $activityIds): array
    {
        return $this->db->getArrayFromSql(
            'SELECT rac.rac_activity_id, rac.rac_period_reference, rac.rac_credits_claimed, rac.rac_credits_accepted,
                    rqt.rqt_id, rqt.rqt_name, rqt.rqt_short_name, rqt.rqt_sequence,
                    rqt.rqt_period_anchor, rqt.rqt_period_unit
               FROM ' . RequirementsTables::activityCredits() . ' AS rac
         INNER JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt ON rqt.rqt_id = rac.rac_requirement_type_id
              WHERE rac.rac_activity_id IN (' . implode(', ', array_fill(0, count($activityIds), '?')) . ')
           ORDER BY rac.rac_activity_id, rqt.rqt_sequence, rac.rac_period_reference',
            $activityIds
        );
    }
}
