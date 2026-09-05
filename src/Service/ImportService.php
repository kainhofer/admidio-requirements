<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\PhpIniUtils;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Throwable;

/**
 * CSV/XLSX import of activities and their credited-period contributions (concept §12).
 *
 * The service is data-oriented, exactly as `ContactImportService` is: it takes rows and a mapping,
 * and knows nothing about a wizard, a session or a terminal. The web pages and
 * `requirements:import` are two adapters over the same three steps -
 *
 *   readFile()  the spreadsheet as an array of rows, with the §12 limits applied;
 *   analyse()   every row resolved and validated, writing **nothing**;
 *   import()    the same resolution again, each row persisted in its own transaction.
 *
 * `analyse()` is not a rehearsal of `import()` with the writes rolled back. It never constructs an
 * entity and never opens a transaction, so the preview cannot write even if something below it
 * misbehaves - which is the guarantee §12 asks for, and one a rollback-based preview cannot give:
 * `Database::rollback()` unwinds every open transaction, so a preview that relied on it would take
 * a surrounding one with it.
 *
 * `import()` resolves each row through the same method the preview used and then walks the same
 * decision, so the preview a reviewer confirmed and the import that follows cannot drift apart.
 */
final class ImportService
{
    /** @var array<string,class-string|null> */
    private const INPUT_READERS = array(
        'AUTO' => null,
        'XLSX' => Xlsx::class,
        'XLS' => Xls::class,
        'ODS' => Ods::class,
        'CSV' => Csv::class
    );

    /** @var array<int,string> */
    public const INPUT_ENCODINGS = array(
        'GUESS', 'UTF-8', 'UTF-16BE', 'UTF-16LE', 'UTF-32BE', 'UTF-32LE', 'CP1252', 'ISO-8859-1'
    );

    /** @var array<string,string> */
    public const CSV_DELIMITERS = array(',' => ',', ';' => ';', '\\t' => "\t", '|' => '|');

    /** @var array<int,string> */
    public const CSV_ENCLOSURES = array('AUTO', '"', "'");

    /**
     * The identifier columns that say which member a row belongs to. Exactly one of them may be
     * mapped, and only by an administrator: §12 assigns a normal user's rows server-side.
     *
     * A name is the pair, not one column: "Meier" identifies nobody, and splitting a single cell on
     * a space would be the fuzzy matching REQ-DEC-008 rules out. `last_name` stands for the pair
     * here, and `validateMapping()` refuses one half of it without the other.
     *
     * @var array<int,string>
     */
    public const MEMBER_FIELDS = array('user_uuid', 'user_login', 'user_id', 'last_name');

    /** @var array<int,string> */
    public const ACTIVITY_FIELDS = array(
        'title', 'description', 'url', 'location',
        'begin_date', 'begin_time', 'end_date', 'end_time', 'certificate_date',
        'attendance', 'comments', 'provider', 'status', 'source_key', 'duplicate'
    );

    /** The activity column each plain text field is copied to; the rest are parsed or resolved. */
    private const TEXT_COLUMNS = array(
        'title' => 'rqa_title',
        'description' => 'rqa_description',
        'url' => 'rqa_url',
        'location' => 'rqa_location',
        'comments' => 'rqa_comments'
    );

    /** @var array<int,string> */
    public const DUPLICATE_POLICIES = array('skip', 'create');

    /*
     * The §12 limits. A spreadsheet is parsed entirely in memory before a single row is looked at,
     * so a file that is too large has to be refused before it is loaded - the file size and the
     * worksheet count are therefore checked on the reader, and the shape of the data afterwards.
     */
    public const MAX_FILE_BYTES = 8388608;
    public const MAX_WORKSHEETS = 50;
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 200;
    public const MAX_CELLS = 250000;
    private const PARSE_TIME_LIMIT = 600;

    /** Excel day serials above this are not a date anybody imports; 60000 is in 2064. */
    private const MAX_EXCEL_SERIAL = 60000;

    /** @var array<int,array<string,mixed>>|null requirement types of the organization, read once */
    private ?array $requirementTypes = null;

    /** @var array<int,array<string,mixed>>|null providers visible to the actor, read once */
    private ?array $providers = null;

    /** @var array<string,int|null> member lookups already answered, keyed by field and value */
    private array $memberCache = array();

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int,string> */
    public static function inputFormats(): array
    {
        return array_keys(self::INPUT_READERS);
    }

    /**
     * The worksheets of a spreadsheet, without loading their cells.
     *
     * The wizard has to offer the sheet names before it can ask which one to import, and loading the
     * whole workbook to find out would pay the §12 parsing cost twice.
     *
     * @return array<int,string> empty for a CSV, which has no worksheets
     * @throws InvalidArgumentException
     */
    public function worksheets(string $filename, string $inputFormat = 'AUTO'): array
    {
        $reader = $this->reader($filename, $inputFormat);
        if ($reader instanceof Csv) {
            return array();
        }

        $names = $reader->listWorksheetNames($filename);
        if (count($names) > self::MAX_WORKSHEETS) {
            throw new InvalidArgumentException(
                'The file contains ' . count($names) . ' worksheets, more than the '
                . self::MAX_WORKSHEETS . ' this import accepts.'
            );
        }
        return $names;
    }

    /**
     * Read a supported import file into rows of cell values.
     *
     * Formulas are deliberately **not** calculated. §12 keeps a spreadsheet formula as data, and an
     * import that evaluated one would both change what the file says and hand an untrusted document
     * a calculation engine.
     *
     * @return array<int,array<int,mixed>>
     * @throws InvalidArgumentException
     */
    public function readFile(
        string $filename,
        string $inputFormat = 'AUTO',
        string $encoding = '',
        string $delimiter = '',
        string $enclosure = 'AUTO',
        string $worksheet = ''
    ): array {
        $reader = $this->reader($filename, $inputFormat);

        if ($reader instanceof Csv) {
            if ($encoding !== '' && !in_array($encoding, self::INPUT_ENCODINGS, true)) {
                throw new InvalidArgumentException('Unsupported CSV input encoding "' . $encoding . '".');
            }
            if ($delimiter !== '' && !array_key_exists($delimiter, self::CSV_DELIMITERS)) {
                throw new InvalidArgumentException('Unsupported CSV delimiter.');
            }
            if ($enclosure !== '' && !in_array($enclosure, self::CSV_ENCLOSURES, true)) {
                throw new InvalidArgumentException('Unsupported CSV enclosure.');
            }

            if ($encoding === 'GUESS') {
                $encoding = Csv::guessEncoding($filename);
            } elseif ($encoding === '') {
                $encoding = 'UTF-8';
            }
            $reader->setInputEncoding($encoding);

            if ($delimiter !== '') {
                $reader->setDelimiter(self::CSV_DELIMITERS[$delimiter]);
            }
            if ($enclosure !== 'AUTO') {
                $reader->setEnclosure($enclosure);
            }
        } elseif ($worksheet !== '' && !ctype_digit($worksheet)) {
            // Only the named sheet is parsed, so a workbook of fifty sheets costs one.
            $reader->setLoadSheetsOnly($worksheet);
        }

        // Only the values are wanted, so the styles, charts and drawings of an XLSX need not be
        // built at all - which is most of the parsing cost of a large workbook.
        $reader->setReadDataOnly(true);
        PhpIniUtils::startNewExecutionTimeLimit(self::PARSE_TIME_LIMIT);

        $spreadsheet = $reader->load($filename);
        if ($worksheet !== '') {
            $sheet = ctype_digit($worksheet)
                ? $spreadsheet->getSheet((int) $worksheet)
                : $spreadsheet->getSheetByName($worksheet);
        } else {
            $sheet = $spreadsheet->getActiveSheet();
        }
        if ($sheet === null) {
            throw new InvalidArgumentException('The worksheet "' . $worksheet . '" does not exist in this file.');
        }

        $rows = $sheet->toArray(null, false, false);
        $spreadsheet->disconnectWorksheets();

        return $this->assertWithinLimits($rows);
    }

    /**
     * The column titles of the first row, for a wizard that has to offer them.
     *
     * @param array<int,array<int,mixed>> $rows
     * @return array<int,string>
     */
    public static function headers(array $rows): array
    {
        $headers = array();
        foreach ($rows[0] ?? array() as $index => $header) {
            $headers[(int) $index] = trim((string) $header);
        }
        return $headers;
    }

    /**
     * Resolve FIELD=COLUMN assignments into FIELD => column index.
     *
     * FIELD is one of the fixed names, or `credit_<REQUIREMENT>` / `period_<REQUIREMENT>` where
     * REQUIREMENT is a requirement type's short name or id. COLUMN is a zero-based index or, with a
     * header row, a column title. A header that spells a field name maps itself, so a file exported
     * for this import needs no assignments at all; an explicit assignment overrides the header.
     *
     * @param array<int,array<int,mixed>> $rows
     * @param array<string,string> $assignments
     * @param bool $mayAssignMembers whether the caller may map the fields of isRestrictedField().
     *        A header naming one is passed over when they may not; an explicit assignment to one
     *        is refused, because there the operator asked for something they do not have.
     * @return array<string,int>
     * @throws Exception|InvalidArgumentException
     */
    public function resolveMapping(
        array $rows,
        array $assignments = array(),
        bool $firstRowHeader = true,
        bool $mayAssignMembers = true
    ): array {
        if (count($rows) === 0) {
            throw new InvalidArgumentException('The import file contains no rows.');
        }

        $headers = array();
        if ($firstRowHeader) {
            foreach (self::headers($rows) as $index => $header) {
                if ($header !== '') {
                    $headers[$header] = $index;
                }
            }

            foreach ($headers as $header => $index) {
                $field = self::canonicalField($header);
                if ($field === null) {
                    continue;
                }
                // A header is a suggestion, not an instruction, so one naming a field this actor may
                // not use is passed over rather than turned into a refusal. A member exporting their
                // own attendance from a course provider very plausibly gets a file with their name
                // in it, and refusing the whole file over a column that would have been ignored
                // anyway helps nobody. An assignment made by hand still fails below - there the
                // operator asked for something they may not have, and should be told so.
                if (!$mayAssignMembers && self::isRestrictedField($field)) {
                    continue;
                }
                $assignments[$field] ??= (string) $index;
            }
        }

        $mapping = array();
        foreach ($assignments as $field => $column) {
            if ((string) $column === '') {
                continue;
            }
            if (!$mayAssignMembers && self::isRestrictedField((string) $field)) {
                // §12 assigns a normal user's rows server-side, and only an administrator may set a
                // status; both would be refused per row further down, which for a whole file is a
                // worse answer than one that names the column.
                throw new Exception('SYS_NO_RIGHTS');
            }
            if (self::canonicalField((string) $field) !== $field) {
                throw new InvalidArgumentException(
                    'Unknown import field "' . $field . '". Use one of ' . implode(', ', self::ACTIVITY_FIELDS)
                    . ', ' . implode(', ', self::MEMBER_FIELDS) . ', first_name, or credit_/period_'
                    . ' followed by a requirement short name.'
                );
            }

            if (ctype_digit((string) $column)) {
                $columnIndex = (int) $column;
            } elseif ($firstRowHeader && array_key_exists((string) $column, $headers)) {
                $columnIndex = $headers[(string) $column];
            } else {
                throw new InvalidArgumentException(
                    'The import column "' . $column . '" for field "' . $field . '" was not found.'
                );
            }

            if (!array_key_exists($columnIndex, $rows[0])) {
                throw new InvalidArgumentException(
                    'The import column index ' . $columnIndex . ' is outside the input data.'
                );
            }
            $mapping[$field] = $columnIndex;
        }

        return $this->validateMapping($mapping);
    }

    /**
     * Validate every row and report what an import would do, without writing anything.
     *
     * @param array<int,array<int,mixed>> $rows
     * @param array<string,int> $mapping
     * @param array{firstRowHeader?:bool,duplicates?:string,userId?:int} $options
     * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>}
     * @throws Exception|InvalidArgumentException
     */
    public function analyse(array $rows, array $mapping, array $options = array()): array
    {
        return $this->run($rows, $mapping, $options, false);
    }

    /**
     * Import the rows. Each row is one transaction: the activity and all of its credits land
     * together or not at all, and a row that fails leaves the rows before it standing.
     *
     * @param array<int,array<int,mixed>> $rows
     * @param array<string,int> $mapping
     * @param array{firstRowHeader?:bool,duplicates?:string,userId?:int} $options
     * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>}
     * @throws Exception|InvalidArgumentException
     */
    public function import(array $rows, array $mapping, array $options = array()): array
    {
        return $this->run($rows, $mapping, $options, true);
    }

    /**
     * @param array<int,array<int,mixed>> $rows
     * @param array<string,int> $mapping
     * @param array<string,mixed> $options
     * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>}
     * @throws Exception|InvalidArgumentException
     */
    private function run(array $rows, array $mapping, array $options, bool $write): array
    {
        global $gCurrentUser;

        if (!RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $mapping = $this->validateMapping($mapping);
        $policy = (string) ($options['duplicates'] ?? '');
        if (!in_array($policy, self::DUPLICATE_POLICIES, true)) {
            // REQ-DEC-008 leaves this without a default on purpose. Whether a second copy of an
            // activity is a correction or a real second attendance is not an importer's decision.
            throw new InvalidArgumentException(
                'The duplicate policy must be given explicitly as one of: '
                . implode(', ', self::DUPLICATE_POLICIES) . '.'
            );
        }

        $admin = Component::isAdministrable(RequirementsPlugin::ID);
        if (!$admin) {
            // §12: a normal user's rows are assigned server-side, never from the file.
            // resolveMapping() has already passed over these columns, so a mapping that still
            // carries one was built by hand rather than by the wizard - this is the backstop for
            // that, not the message an operator is meant to meet.
            $targetUserId = (int) $gCurrentUser->getValue('usr_id');
            if ($this->mappedMemberField($mapping) !== null || isset($mapping['status'])) {
                throw new Exception('SYS_NO_RIGHTS');
            }
        } else {
            $targetUserId = isset($options['userId']) ? (int) $options['userId'] : null;
            $memberColumn = $this->mappedMemberField($mapping);

            if ($targetUserId === null && $memberColumn === null) {
                throw new InvalidArgumentException(
                    'The import needs a member: map one of ' . implode(', ', self::MEMBER_FIELDS)
                    . ' or name the member the whole file belongs to.'
                );
            }
            if ($targetUserId !== null && $memberColumn !== null) {
                // Naming a member for the whole file is the alternative to a column that names one
                // per row, not an addition to it. With both, one of the two answers would be
                // silently ignored - and which one is exactly what nobody would think to check.
                throw new InvalidArgumentException(
                    'The member was given for the whole file and by the "' . $memberColumn
                    . '" column; use one or the other.'
                );
            }
        }

        $firstRowHeader = (bool) ($options['firstRowHeader'] ?? true);
        $result = array(
            'summary' => array('rows' => 0, 'create' => 0, 'skip' => 0, 'error' => 0, 'credits' => 0),
            'rows' => array()
        );

        for ($index = $firstRowHeader ? 1 : 0, $count = count($rows); $index < $count; ++$index) {
            $row = $rows[$index];
            if ($this->isBlankRow($row)) {
                continue;
            }
            ++$result['summary']['rows'];

            $report = array(
                'row' => $index + 1,
                'status' => 'create',
                'member' => '',
                'title' => '',
                'begin' => '',
                'credits' => array(),
                'duplicates' => array(),
                'messages' => array()
            );

            try {
                $resolved = $this->resolveRow($row, $mapping, $targetUserId, $policy, $admin);
                $report['member'] = $resolved['memberName'];
                $report['title'] = (string) $resolved['values']['rqa_title'];
                $report['begin'] = (string) $resolved['values']['rqa_begin_date'];
                $report['credits'] = $resolved['credits'];
                $report['duplicates'] = $resolved['duplicates'];
                $report['messages'] = $resolved['messages'];
                $report['status'] = $resolved['skip'] ? 'skip' : 'create';

                if ($write && !$resolved['skip']) {
                    $report['uuid'] = $this->persist($resolved);
                    $report['status'] = 'imported';
                }
            } catch (Throwable $exception) {
                $report['status'] = 'error';
                $report['messages'][] = $exception->getMessage();
            }

            if ($report['status'] === 'error') {
                ++$result['summary']['error'];
            } elseif ($report['status'] === 'skip') {
                ++$result['summary']['skip'];
            } else {
                ++$result['summary']['create'];
                $result['summary']['credits'] += count($report['credits']);
            }
            $result['rows'][] = $report;
        }

        return $result;
    }

    /**
     * One row turned into the values a save would be given, with everything it refers to resolved.
     *
     * Nothing here writes, and nothing here constructs an `Activity`: `Entity::setValue()` coerces,
     * so a date validated only after it reached an entity has already become something else. The
     * values leave this method in the shape `ActivityService::save()` expects and are checked again
     * there - this is the preview's copy of the answer, not a replacement for the real gate.
     *
     * @param array<int,mixed> $row
     * @param array<string,int> $mapping
     * @return array{values:array<string,mixed>,userId:?int,memberName:string,
     *               credits:array<int,array<string,mixed>>,duplicates:array<int,string>,
     *               skip:bool,messages:array<int,string>}
     * @throws Exception|InvalidArgumentException
     */
    private function resolveRow(
        array $row,
        array $mapping,
        ?int $targetUserId,
        string $policy,
        bool $admin
    ): array {
        $messages = array();

        $userId = $targetUserId;
        $memberName = '';
        if ($admin && $this->mappedMemberField($mapping) !== null) {
            [$userId, $memberName] = $this->resolveMember($row, $mapping);
        }

        $values = array();
        foreach (self::TEXT_COLUMNS as $field => $column) {
            if (isset($mapping[$field])) {
                $values[$column] = $this->cell($row, $mapping[$field]);
            }
        }

        $title = trim((string) ($values['rqa_title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('The row has no activity title.');
        }
        $values['rqa_title'] = $title;

        foreach (array(
            'begin_date' => 'rqa_begin_date',
            'end_date' => 'rqa_end_date',
            'certificate_date' => 'rqa_certificate_date'
        ) as $field => $column) {
            if (isset($mapping[$field])) {
                $values[$column] = $this->parseDate($this->cell($row, $mapping[$field]), $field);
            }
        }
        if (($values['rqa_begin_date'] ?? null) === null) {
            throw new InvalidArgumentException('The row has no begin date.');
        }

        foreach (array('begin_time' => 'rqa_begin_time', 'end_time' => 'rqa_end_time') as $field => $column) {
            if (isset($mapping[$field])) {
                $values[$column] = $this->parseTime($this->cell($row, $mapping[$field]), $field);
            }
        }

        if (isset($mapping['attendance'])) {
            $attendance = $this->cell($row, $mapping['attendance']);
            $values['rqa_attendance'] = $attendance === '' ? null : $this->parseNumber($attendance, 'attendance');
        }
        if (isset($mapping['provider'])) {
            $values['rqa_provider_id'] = $this->resolveProvider($this->cell($row, $mapping['provider']));
        }
        if (isset($mapping['status'])) {
            $status = strtolower($this->cell($row, $mapping['status']));
            if ($status !== '') {
                if (!in_array($status, ActivityService::STATUSES, true)) {
                    throw new InvalidArgumentException(
                        'The status "' . $status . '" is not one of '
                        . implode(', ', ActivityService::STATUSES) . '.'
                    );
                }
                $values['rqa_status'] = $status;
            }
        }

        $sourceKey = isset($mapping['source_key']) ? $this->cell($row, $mapping['source_key']) : '';
        $values['rqa_source_key'] = $sourceKey === '' ? null : $sourceKey;

        $credits = $this->resolveCredits($row, $mapping, (string) $values['rqa_begin_date']);

        // A row may overrule the import-level answer, which is what makes a preview worth reading:
        // a reviewer who finds one genuine repeat among fifty corrections marks that one row.
        if (isset($mapping['duplicate'])) {
            $rowPolicy = strtolower($this->cell($row, $mapping['duplicate']));
            if ($rowPolicy !== '') {
                if (!in_array($rowPolicy, self::DUPLICATE_POLICIES, true)) {
                    throw new InvalidArgumentException(
                        'The duplicate column must be empty or one of: '
                        . implode(', ', self::DUPLICATE_POLICIES) . '.'
                    );
                }
                $policy = $rowPolicy;
            }
        }

        $duplicates = $userId === null
            ? array()
            : $this->findDuplicates($userId, $title, (string) $values['rqa_begin_date'], $sourceKey);
        $skip = false;
        if ($duplicates !== array()) {
            $skip = $policy === 'skip';
            $messages[] = $sourceKey === ''
                ? 'An activity with the same title and begin date already exists for this member ('
                    . count($duplicates) . ').'
                : 'An activity with the source key "' . $sourceKey . '" already exists ('
                    . count($duplicates) . ').';
        }

        return array(
            'values' => $values,
            'userId' => $userId,
            'memberName' => $memberName,
            'credits' => $credits,
            'duplicates' => $duplicates,
            'skip' => $skip,
            'messages' => $messages
        );
    }

    /**
     * Write one resolved row: the activity and every credit of it, in one transaction.
     *
     * The writes go through `ActivityService`, so the permission checks, the date validation and the
     * changelog entries are the ones the activity form produces. The transaction around them has the
     * same shape as `saveWithCredits()`; it is repeated rather than reused because the periods here
     * are already resolved dates rather than the raw values of a period control.
     *
     * @param array<string,mixed> $resolved
     * @throws Exception
     */
    private function persist(array $resolved): string
    {
        $service = new ActivityService($this->db);
        $activity = new Activity($this->db);

        // The source key is the import's own bookkeeping rather than something a member may edit,
        // so ActivityService::save() does not accept it. Setting it on the record beforehand lets
        // that one save() write it, instead of a second save producing a second changelog entry
        // for what is one creation.
        if (($resolved['values']['rqa_source_key'] ?? null) !== null) {
            $activity->setValue('rqa_source_key', $resolved['values']['rqa_source_key']);
        }

        $this->db->startTransaction();
        try {
            $service->save($activity, $resolved['values'], $resolved['userId']);

            foreach ($resolved['credits'] as $credit) {
                $service->syncCredit(
                    $activity,
                    new RequirementType($this->db, (int) $credit['typeId']),
                    (string) $credit['period'],
                    (float) $credit['claimed'],
                    null
                );
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return (string) $activity->getValue('rqa_uuid');
    }

    /**
     * The credited-period contributions of one row.
     *
     * A `credit_X` column with no value contributes nothing - a file that carries a column per
     * requirement is the normal case, and an empty cell there means "not this one", not zero. The
     * matching `period_X` column is optional; without it the contribution is credited to the period
     * the activity falls in, which is what the activity form defaults to.
     *
     * @param array<int,mixed> $row
     * @param array<string,int> $mapping
     * @return array<int,array{typeId:int,requirement:string,period:string,claimed:float}>
     * @throws Exception|InvalidArgumentException
     */
    private function resolveCredits(array $row, array $mapping, string $beginDate): array
    {
        $credits = array();
        foreach ($mapping as $field => $columnIndex) {
            if (!str_starts_with($field, 'credit_')) {
                continue;
            }
            $reference = substr($field, strlen('credit_'));
            $raw = $this->cell($row, $columnIndex);
            if ($raw === '') {
                continue;
            }

            $type = $this->requirementByReference($reference);
            $claimed = $this->parseNumber($raw, $field);
            if ($claimed < 0.0) {
                throw new InvalidArgumentException('The credits in "' . $field . '" are negative.');
            }

            $period = $beginDate;
            if (isset($mapping['period_' . $reference])) {
                $rawPeriod = $this->cell($row, $mapping['period_' . $reference]);
                if ($rawPeriod !== '') {
                    $period = $this->parsePeriod($rawPeriod, $type, 'period_' . $reference);
                }
            }

            $typeId = (int) $type['rqt_id'];
            if (isset($credits[$typeId])) {
                throw new InvalidArgumentException(
                    'The row credits the requirement "' . $type['rqt_name'] . '" twice.'
                );
            }
            $credits[$typeId] = array(
                'typeId' => $typeId,
                'requirement' => (string) $type['rqt_name'],
                'period' => $period,
                'claimed' => $claimed
            );
        }

        return array_values($credits);
    }

    /**
     * Existing activities of this member that the row would repeat.
     *
     * With a source key the question is answered by the key alone: an import source that provides
     * one has said which record this is. Without one it is an exact match on title and begin date -
     * never a fuzzy one (REQ-DEC-008), so what comes back is a candidate a human decides about,
     * not a verdict.
     *
     * @return array<int,string> UUIDs of the matching activities
     * @throws Exception
     */
    private function findDuplicates(int $userId, string $title, string $beginDate, string $sourceKey): array
    {
        global $gCurrentOrgId;

        if ($sourceKey !== '') {
            $sql = 'SELECT rqa_uuid
                      FROM ' . RequirementsTables::activities() . '
                     WHERE rqa_org_id = ? AND rqa_source_key = ?';
            $params = array((int) $gCurrentOrgId, $sourceKey);
        } else {
            $sql = 'SELECT rqa_uuid
                      FROM ' . RequirementsTables::activities() . '
                     WHERE rqa_org_id = ? AND rqa_user_id = ? AND rqa_title = ? AND rqa_begin_date = ?';
            $params = array((int) $gCurrentOrgId, $userId, $title, $beginDate);
        }

        return array_map('strval', $this->db->queryPrepared($sql, $params)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The member a row names, and the name to print for them.
     *
     * @param array<int,mixed> $row
     * @param array<string,int> $mapping
     * @return array{0:int,1:string}
     * @throws Exception|InvalidArgumentException
     */
    private function resolveMember(array $row, array $mapping): array
    {
        $field = (string) $this->mappedMemberField($mapping);

        if ($field === 'last_name') {
            $first = $this->cell($row, $mapping['first_name']);
            $last = $this->cell($row, $mapping['last_name']);
            if ($first === '' || $last === '') {
                throw new InvalidArgumentException('The row has no first and last name.');
            }
            return array($this->memberByName($first, $last), trim($last . ', ' . $first));
        }

        $value = $this->cell($row, $mapping[$field]);
        if ($value === '') {
            throw new InvalidArgumentException('The row has no value in the "' . $field . '" column.');
        }

        $cacheKey = $field . chr(0) . $value;
        if (!array_key_exists($cacheKey, $this->memberCache)) {
            $column = match ($field) {
                'user_uuid' => 'usr_uuid',
                'user_login' => 'usr_login_name',
                default => 'usr_id'
            };
            $ids = $this->db->queryPrepared(
                'SELECT usr_id FROM ' . TBL_USERS . ' WHERE ' . $column . ' = ?',
                array($field === 'user_id' ? (int) $value : $value)
            )->fetchAll(PDO::FETCH_COLUMN);

            if (count($ids) > 1) {
                // Impossible on usr_id or usr_uuid; possible on a login name in a broken database.
                throw new InvalidArgumentException('The member "' . $value . '" is ambiguous.');
            }
            $this->memberCache[$cacheKey] = count($ids) === 1 ? (int) $ids[0] : null;
        }

        $userId = $this->memberCache[$cacheKey];
        if ($userId === null) {
            throw new InvalidArgumentException('The member "' . $value . '" was not found.');
        }
        $this->assertMember($userId, $value);

        return array($userId, $value);
    }

    /**
     * The one member of this organization with exactly this first and last name.
     *
     * Two members of the same name are not resolved to the first of them: §12 refuses to select an
     * ambiguous user silently, and a namesake is exactly the case where getting it wrong is
     * invisible afterwards.
     *
     * @throws Exception|InvalidArgumentException
     */
    private function memberByName(string $first, string $last): int
    {
        global $gCurrentOrgId, $gProfileFields;

        $cacheKey = 'name' . chr(0) . $first . chr(0) . $last;
        if (!array_key_exists($cacheKey, $this->memberCache)) {
            $ids = $this->db->queryPrepared(
                'SELECT DISTINCT usr.usr_id
                   FROM ' . TBL_USERS . ' AS usr
             INNER JOIN ' . TBL_USER_DATA . ' AS fn
                     ON fn.usd_usr_id = usr.usr_id AND fn.usd_usf_id = ? AND fn.usd_value = ?
             INNER JOIN ' . TBL_USER_DATA . ' AS ln
                     ON ln.usd_usr_id = usr.usr_id AND ln.usd_usf_id = ? AND ln.usd_value = ?
             INNER JOIN ' . TBL_MEMBERS . ' AS mem ON mem.mem_usr_id = usr.usr_id
             INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id
             INNER JOIN ' . TBL_CATEGORIES . ' AS cat
                     ON cat.cat_id = rol.rol_cat_id AND cat.cat_org_id = ?
                  WHERE usr.usr_valid = true',
                array(
                    (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
                    $first,
                    (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
                    $last,
                    (int) $gCurrentOrgId
                )
            )->fetchAll(PDO::FETCH_COLUMN);

            if (count($ids) > 1) {
                throw new InvalidArgumentException(
                    'Several members of this organization are called "' . $first . ' ' . $last . '".'
                );
            }
            $this->memberCache[$cacheKey] = count($ids) === 1 ? (int) $ids[0] : null;
        }

        $userId = $this->memberCache[$cacheKey];
        if ($userId === null) {
            throw new InvalidArgumentException(
                'No member of this organization is called "' . $first . ' ' . $last . '".'
            );
        }
        return $userId;
    }

    /**
     * @throws Exception|InvalidArgumentException
     */
    private function assertMember(int $userId, string $label): void
    {
        global $gCurrentOrgId;

        $cacheKey = 'member' . chr(0) . $userId;
        if (!array_key_exists($cacheKey, $this->memberCache)) {
            $user = new User($this->db, null, $userId);
            $user->setOrganization((int) $gCurrentOrgId);
            $this->memberCache[$cacheKey] = ((int) $user->getValue('usr_id') !== 0
                && $user->isMemberOfOrganization()) ? $userId : null;
        }

        if ($this->memberCache[$cacheKey] === null) {
            throw new InvalidArgumentException(
                'The member "' . $label . '" is not a member of this organization.'
            );
        }
    }

    /**
     * The provider a cell names, by short name or by name. An unknown provider is an error rather
     * than a silently created one: a typo would otherwise fill the provider list with near-copies.
     *
     * @throws Exception|InvalidArgumentException
     */
    private function resolveProvider(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $this->providers ??= (new ProviderService($this->db))->findVisible();

        $matches = array();
        foreach (array('rqp_short_name', 'rqp_name') as $column) {
            foreach ($this->providers as $provider) {
                if (strcasecmp(trim((string) $provider[$column]), $value) === 0) {
                    $matches[(int) $provider['rqp_id']] = true;
                }
            }
            // A short name is the more specific handle: once it has matched, a provider whose
            // *name* happens to be the same string is not a second candidate.
            if ($matches !== array()) {
                break;
            }
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException('The provider "' . $value . '" is ambiguous.');
        }
        if ($matches === array()) {
            throw new InvalidArgumentException('The provider "' . $value . '" was not found.');
        }
        return (int) array_key_first($matches);
    }

    /**
     * The requirement type a `credit_X` / `period_X` suffix names: its short name or its id.
     *
     * @return array<string,mixed>
     * @throws Exception|InvalidArgumentException
     */
    private function requirementByReference(string $reference): array
    {
        $this->requirementTypes ??= (new RequirementTypeService($this->db))->findTypes();

        $matches = array();
        foreach ($this->requirementTypes as $type) {
            $shortName = trim((string) $type['rqt_short_name']);
            if (($shortName !== '' && strcasecmp($shortName, $reference) === 0)
                || (ctype_digit($reference) && (int) $type['rqt_id'] === (int) $reference)) {
                $matches[] = $type;
            }
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException('The requirement "' . $reference . '" is ambiguous.');
        }
        if ($matches === array()) {
            throw new InvalidArgumentException('The requirement "' . $reference . '" was not found.');
        }
        if (!(bool) $matches[0]['rqt_active']) {
            throw new InvalidArgumentException(
                'The requirement "' . $matches[0]['rqt_name'] . '" is not active and takes no new credits.'
            );
        }
        return $matches[0];
    }

    /**
     * A credited period as the file wrote it, snapped to the period the requirement counts in.
     *
     * The period control of the activity form and this share `periodInputToDateForKind()`, so a file
     * may write "2026", "2026-03", "2026-W12" or a date, whichever the requirement's period is - and
     * a yearly requirement given a date still credits the year that date falls in.
     *
     * @param array<string,mixed> $type
     * @throws Exception|InvalidArgumentException
     */
    private function parsePeriod(string $raw, array $type, string $field): string
    {
        $entity = new RequirementType($this->db);
        $entity->setArray($type);
        $kind = FulfilmentService::periodControlKind($entity);

        // A quarter is written the way a file can write it - "2026-Q2" - and split into the two
        // values the control uses, because a spreadsheet has no pair of cells for one period.
        $quarter = '';
        if ($kind === 'quarter' && preg_match('/^(\d{4})-?Q([1-4])$/i', $raw, $match) === 1) {
            $raw = $match[1];
            $quarter = $match[2];
        }

        try {
            return FulfilmentService::normalizePeriodReference(
                $entity,
                FulfilmentService::periodInputToDateForKind($kind, $raw, $quarter)
            );
        } catch (Exception) {
            // A plain date is acceptable whatever the period unit is: it says which period.
            $date = $this->parseDate($raw, $field);
            if ($date === null) {
                throw new InvalidArgumentException(
                    'The value "' . $raw . '" in "' . $field . '" is not a period.'
                );
            }
            return FulfilmentService::normalizePeriodReference($entity, $date);
        }
    }

    /**
     * A date cell as Y-m-d, or null when it is empty.
     *
     * Three notations reach an import: ISO, the organization's own display format, and the number a
     * spreadsheet stores a date as. All three are accepted; nothing else is guessed, because a
     * silently misread date lands the activity in the wrong credited period and nothing downstream
     * can notice that it did.
     *
     * @throws InvalidArgumentException
     */
    private function parseDate(string $raw, string $field): ?string
    {
        global $gSettingsManager;

        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw) && (float) $raw > 0 && (float) $raw <= self::MAX_EXCEL_SERIAL) {
            return date('Y-m-d', SpreadsheetDate::excelToTimestamp((float) $raw));
        }

        $formats = array('!Y-m-d');
        if (isset($gSettingsManager)) {
            $formats[] = '!' . $gSettingsManager->getString('system_date');
        }
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] + $errors['error_count']) === 0)) {
                return $date->format('Y-m-d');
            }
        }

        throw new InvalidArgumentException('The value "' . $raw . '" in "' . $field . '" is not a date.');
    }

    /**
     * A time cell as HH:MM:SS, or null when it is empty. A spreadsheet stores a time as the fraction
     * of a day it is, which is why a bare "0.5" has to mean noon here.
     *
     * @throws InvalidArgumentException
     */
    private function parseTime(string $raw, string $field): ?string
    {
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw) && (float) $raw >= 0 && (float) $raw < 1) {
            return gmdate('H:i:s', (int) round((float) $raw * 86400));
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw) === 1) {
            return strlen($raw) === 5 ? $raw . ':00' : $raw;
        }

        throw new InvalidArgumentException('The value "' . $raw . '" in "' . $field . '" is not a time.');
    }

    /**
     * A number cell, accepting the decimal comma a European spreadsheet writes.
     *
     * @throws InvalidArgumentException
     */
    private function parseNumber(string $raw, string $field): float
    {
        if (!str_contains($raw, '.') && substr_count($raw, ',') === 1) {
            $raw = str_replace(',', '.', $raw);
        }
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException('The value "' . $raw . '" in "' . $field . '" is not a number.');
        }
        return (float) $raw;
    }

    /**
     * The canonical field name a header or an assignment key spells, or null if it is none.
     */
    public static function canonicalField(string $name): ?string
    {
        $name = strtolower(trim($name));

        if (in_array($name, self::ACTIVITY_FIELDS, true)
            || in_array($name, self::MEMBER_FIELDS, true)
            || $name === 'first_name') {
            return $name;
        }
        foreach (array('credit_', 'period_') as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * @param array<string,int> $mapping
     * @return array<string,int>
     * @throws InvalidArgumentException
     */
    private function validateMapping(array $mapping): array
    {
        foreach (array('title', 'begin_date') as $required) {
            if (!isset($mapping[$required])) {
                throw new InvalidArgumentException(
                    'The import mapping must include a "' . $required . '" column.'
                );
            }
        }

        $memberFields = array_values(array_filter(
            self::MEMBER_FIELDS,
            static fn(string $field): bool => isset($mapping[$field])
        ));
        if (count($memberFields) > 1) {
            throw new InvalidArgumentException(
                'Only one member identifier may be mapped, but ' . implode(' and ', $memberFields) . ' are.'
            );
        }
        if (isset($mapping['last_name']) !== isset($mapping['first_name'])) {
            throw new InvalidArgumentException(
                'Identifying a member by name needs both a first_name and a last_name column.'
            );
        }

        // A period column without the credits it belongs to is a mapping the operator did not
        // finish, and ignoring it silently would drop the periods they meant to import.
        foreach (array_keys($mapping) as $field) {
            if (str_starts_with($field, 'period_')
                && !isset($mapping['credit_' . substr($field, strlen('period_'))])) {
                throw new InvalidArgumentException(
                    'The column "' . $field . '" has no matching "credit_'
                    . substr($field, strlen('period_')) . '" column.'
                );
            }
        }

        return $mapping;
    }

    /**
     * @param array<int,mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,mixed> $row
     */
    private function cell(array $row, int $index): string
    {
        return array_key_exists($index, $row) ? trim((string) $row[$index]) : '';
    }

    /**
     * The fields only an administrator may map: which member a row belongs to, and what status an
     * activity arrives in. For everybody else the import assigns the rows to them and lets the
     * activity settings decide the status, exactly as the activity form does.
     *
     * The rule is a static one so that `resolveMapping()` stays free of the runtime: which columns
     * a file may carry is column policy, and §16 keeps that on the side of the seam that can be
     * tested without a database. Who is allowed to map them is the caller's answer, passed in.
     */
    public static function isRestrictedField(string $field): bool
    {
        return in_array($field, self::MEMBER_FIELDS, true)
            || $field === 'first_name'
            || $field === 'status';
    }

    /**
     * @param array<string,int> $mapping
     */
    private function mappedMemberField(array $mapping): ?string
    {
        foreach (self::MEMBER_FIELDS as $field) {
            if (isset($mapping[$field])) {
                return $field;
            }
        }
        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function reader(string $filename, string $inputFormat): IReader
    {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new InvalidArgumentException('The import file "' . $filename . '" is not readable.');
        }

        $size = (int) filesize($filename);
        if ($size === 0) {
            throw new InvalidArgumentException('The import file is empty.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException(
                'The import file is ' . $size . ' bytes, more than the ' . self::MAX_FILE_BYTES
                . ' bytes this import accepts.'
            );
        }

        $inputFormat = strtoupper($inputFormat);
        if (!array_key_exists($inputFormat, self::INPUT_READERS)) {
            throw new InvalidArgumentException('Unsupported import format "' . $inputFormat . '".');
        }

        if ($inputFormat === 'AUTO') {
            return IOFactory::createReaderForFile($filename);
        }
        $readerClass = self::INPUT_READERS[$inputFormat];
        return new $readerClass();
    }

    /**
     * @param array<int,array<int,mixed>> $rows
     * @return array<int,array<int,mixed>>
     * @throws InvalidArgumentException
     */
    private function assertWithinLimits(array $rows): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(
                'The worksheet has ' . count($rows) . ' rows, more than the ' . self::MAX_ROWS
                . ' this import accepts.'
            );
        }

        $columns = 0;
        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }
        if ($columns > self::MAX_COLUMNS) {
            throw new InvalidArgumentException(
                'The worksheet has ' . $columns . ' columns, more than the ' . self::MAX_COLUMNS
                . ' this import accepts.'
            );
        }
        if ($columns * count($rows) > self::MAX_CELLS) {
            throw new InvalidArgumentException(
                'The worksheet has more than the ' . self::MAX_CELLS . ' cells this import accepts.'
            );
        }

        return $rows;
    }
}
