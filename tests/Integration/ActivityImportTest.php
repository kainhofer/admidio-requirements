<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Service\ImportService;
use AdmidioPlugin\Requirements\Service\ProviderService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The CSV/XLSX import of concept §12, and the test list of §16.
 *
 * Two properties are worth more than the rest and are asserted directly rather than inferred:
 *
 * - **the preview writes nothing.** Not "writes nothing that survives" - nothing at all. The row
 *   counts of every plugin table are compared before and after, so a preview that wrote and rolled
 *   back would still pass, but one that opened a transaction at all is caught by the rollback test
 *   below, which runs outside the per-test transaction;
 * - **a row is one unit.** A row whose credits cannot be written leaves no activity behind either,
 *   and the rows before it stay.
 *
 * Everything else here is about refusing to guess: an ambiguous member, provider or requirement is
 * an error on that row and never a silent pick (REQ-DEC-008).
 */
final class ActivityImportTest extends RequirementsDatabaseTestCase
{
    /** @var array<int,string> files written by a test, removed afterwards */
    private array $temporaryFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = array();

        parent::tearDown();
    }

    public function testACsvFileBecomesActivitiesWithTheirCredits(): void
    {
        $member = $this->createMember('req_imp_csv');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $short = (string) $type->getValue('rqt_short_name');

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'end_date', 'credit_' . $short),
            array('req_imp_csv', 'First aid', '2026-03-01', '2026-03-02', '8'),
            array('req_imp_csv', 'Rescue swimming', '2026-05-10', '', '4.5')
        ));

        $result = $this->import($file);

        $this->assertSame(2, $result['summary']['create']);
        $this->assertSame(0, $result['summary']['error'], $this->messages($result));
        $this->assertSame(2, $result['summary']['credits']);

        $rows = $this->getDatabase()->getArrayFromSql(
            'SELECT rqa_title, rqa_begin_date, rqa_end_date, rac_credits_claimed, rac_period_reference
               FROM ' . RequirementsTables::activities() . '
         INNER JOIN ' . RequirementsTables::activityCredits() . ' ON rac_activity_id = rqa_id
              WHERE rqa_user_id = ?
           ORDER BY rqa_begin_date',
            array($member['usr_id'])
        );

        $this->assertCount(2, $rows);
        $this->assertSame('First aid', $rows[0]['rqa_title']);
        $this->assertSame('2026-03-02', $rows[0]['rqa_end_date']);
        $this->assertSame(8.0, (float) $rows[0]['rac_credits_claimed']);
        // No period column, so the contribution is credited to the period the activity falls in -
        // a yearly requirement, so the first day of 2026.
        $this->assertSame('2026-01-01', $rows[0]['rac_period_reference']);
        $this->assertSame(4.5, (float) $rows[1]['rac_credits_claimed']);
    }

    /**
     * §16 asks for actual XLSX parsing rather than a CSV renamed: the reader, the date serials and
     * the worksheet handling of a real workbook are what an operator meets, and none of them are
     * exercised by a comma-separated file.
     */
    public function testAnXlsxWorkbookIsParsedIncludingItsDateSerials(): void
    {
        $member = $this->createMember('req_imp_xlsx');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $short = (string) $type->getValue('rqt_short_name');

        $file = $this->xlsx(array(
            'Courses' => array(
                array('user_login', 'title', 'begin_date', 'credit_' . $short),
                // A spreadsheet stores a date as the day serial it is, and a file exported from one
                // arrives carrying them rather than a formatted date.
                array('req_imp_xlsx', 'Refresher', SpreadsheetDate::stringToExcel('2026-02-01'), 6)
            )
        ));

        $result = $this->import($file, array(), array(), 'Courses');

        $this->assertSame(0, $result['summary']['error'], $this->messages($result));
        $this->assertSame('2026-02-01', $result['rows'][0]['begin']);
        $this->assertSame('2026-02-01', $this->getDatabase()->queryPrepared(
            'SELECT rqa_begin_date FROM ' . RequirementsTables::activities() . ' WHERE rqa_user_id = ?',
            array($member['usr_id'])
        )->fetchColumn());
    }

    public function testTheRequestedWorksheetIsTheOneThatIsImported(): void
    {
        $member = $this->createMember('req_imp_sheet');
        $this->createRequirement('CPD', $member['rol_id'], 20.0);

        $file = $this->xlsx(array(
            'Ignore me' => array(
                array('user_login', 'title', 'begin_date'),
                array('req_imp_sheet', 'Wrong sheet', '2026-01-01')
            ),
            'Import me' => array(
                array('user_login', 'title', 'begin_date'),
                array('req_imp_sheet', 'Right sheet', '2026-04-01')
            )
        ));

        $service = new ImportService($this->getDatabase());
        $this->assertSame(array('Ignore me', 'Import me'), $service->worksheets($file));

        $result = $this->import($file, array(), array(), 'Import me');

        $this->assertSame(1, $result['summary']['create']);
        $this->assertSame('Right sheet', $result['rows'][0]['title']);
    }

    /**
     * A formula is data, not something the import evaluates: an untrusted document must not be
     * handed a calculation engine, and what the file says is what the operator reviewed.
     */
    public function testASpreadsheetFormulaIsImportedAsItsOwnText(): void
    {
        $member = $this->createMember('req_imp_formula');
        $this->createRequirement('CPD', $member['rol_id'], 20.0);

        $file = $this->xlsx(array(
            'Sheet1' => array(
                array('user_login', 'title', 'begin_date'),
                array('req_imp_formula', '=1+1', '2026-04-01')
            )
        ));

        $result = $this->import($file);

        $this->assertSame(0, $result['summary']['error'], $this->messages($result));
        $this->assertSame('=1+1', $result['rows'][0]['title']);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function unusableFiles(): array
    {
        return array(
            'a file that is not there' => array('missing', 'is not readable'),
            'an empty file' => array('empty', 'is empty'),
            // ImportService::MAX_ROWS, spelled out: a data provider is evaluated while the test
            // class is collected, before setUpBeforeClass() has registered the plugin autoloader.
            'more rows than the limit' => array('huge', 'more than the 5000')
        );
    }

    /**
     * @dataProvider unusableFiles
     */
    public function testAnUnusableFileIsRefusedBeforeAnythingIsRead(string $kind, string $message): void
    {
        $file = match ($kind) {
            'missing' => sys_get_temp_dir() . '/req_import_does_not_exist_' . uniqid() . '.csv',
            'empty' => $this->write('', 'csv'),
            default => $this->write(
                str_repeat("a,b\n", ImportService::MAX_ROWS + 10),
                'csv'
            )
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');

        (new ImportService($this->getDatabase()))->readFile($file);
    }

    public function testAWorkbookWithTooManyWorksheetsIsRefused(): void
    {
        $sheets = array();
        for ($index = 0; $index <= ImportService::MAX_WORKSHEETS; ++$index) {
            $sheets['Sheet' . $index] = array(array('title'), array('x'));
        }
        $file = $this->xlsx($sheets);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/more than the ' . ImportService::MAX_WORKSHEETS . '/');

        (new ImportService($this->getDatabase()))->worksheets($file);
    }

    public function testAnAmbiguousMemberNameIsAnErrorOnThatRowAndNotAPick(): void
    {
        $orgId = (int) $GLOBALS['gCurrentOrgId'];
        $first = $this->createMember('req_imp_twin_a');
        $second = $this->fixture->createAndSaveUser('req_imp_twin_b', 'req_imp_twin_b@example.local', $orgId);
        $this->fixture->assignUserToRolePeriod((int) $second['usr_id'], $first['rol_id'], '1990-01-01', '9999-12-31');

        foreach (array($first['usr_id'], (int) $second['usr_id']) as $userId) {
            $this->nameMember($userId, 'Alex', 'Namesake');
        }

        $file = $this->csv(array(
            array('first_name', 'last_name', 'title', 'begin_date'),
            array('Alex', 'Namesake', 'First aid', '2026-03-01')
        ));

        $result = $this->import($file);

        $this->assertSame(1, $result['summary']['error']);
        $this->assertStringContainsString('Several members', $result['rows'][0]['messages'][0]);
        $this->assertSame(0, $this->countActivities());
    }

    public function testAnUnknownProviderIsAnErrorRatherThanANewProvider(): void
    {
        $member = $this->createMember('req_imp_prov');

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'provider'),
            array('req_imp_prov', 'First aid', '2026-03-01', 'Nobody Ltd')
        ));

        $result = $this->import($file);

        $this->assertSame(1, $result['summary']['error']);
        $this->assertStringContainsString('"Nobody Ltd" was not found', $result['rows'][0]['messages'][0]);
        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::providers(),
            array()
        )->fetchColumn());
    }

    public function testAProviderIsMatchedByItsShortNameOrItsName(): void
    {
        $member = $this->createMember('req_imp_prov_ok');
        $provider = new Provider($this->getDatabase());
        (new ProviderService($this->getDatabase()))->save($provider, array(
            'rqp_name' => 'Red Cross Academy',
            'rqp_short_name' => 'RCA'
        ));

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'provider'),
            array('req_imp_prov_ok', 'By short name', '2026-03-01', 'RCA'),
            array('req_imp_prov_ok', 'By full name', '2026-03-02', 'Red Cross Academy')
        ));

        $result = $this->import($file);

        $this->assertSame(0, $result['summary']['error'], $this->messages($result));
        $providerId = (int) $provider->getValue('rqp_id');
        $this->assertSame(array($providerId, $providerId), array_map('intval', $this->getDatabase()->queryPrepared(
            'SELECT rqa_provider_id FROM ' . RequirementsTables::activities()
            . ' WHERE rqa_user_id = ? ORDER BY rqa_begin_date',
            array($member['usr_id'])
        )->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testAnUnknownRequirementIsAnErrorOnThatRow(): void
    {
        $this->createMember('req_imp_req');

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'credit_NOSUCH'),
            array('req_imp_req', 'First aid', '2026-03-01', '8')
        ));

        $result = $this->import($file);

        $this->assertSame(1, $result['summary']['error']);
        $this->assertStringContainsString('"nosuch" was not found', $result['rows'][0]['messages'][0]);
    }

    /**
     * @return array<string,array{0:string,1:int,2:int}>
     */
    public static function duplicatePolicies(): array
    {
        return array(
            'skip keeps the one that is there' => array('skip', 0, 1),
            'create adds the second copy' => array('create', 1, 2)
        );
    }

    /**
     * @dataProvider duplicatePolicies
     */
    public function testTheDuplicatePolicyDecidesWhatARepeatedRowDoes(
        string $policy,
        int $expectedCreated,
        int $expectedActivities
    ): void {
        $member = $this->createMember('req_imp_dup');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 8.0);
        $existing = (string) $this->getDatabase()->queryPrepared(
            'SELECT rqa_title FROM ' . RequirementsTables::activities() . ' WHERE rqa_user_id = ?',
            array($member['usr_id'])
        )->fetchColumn();

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date'),
            array('req_imp_dup', $existing, '2026-03-01')
        ));

        $result = $this->import($file, array('duplicates' => $policy));

        $this->assertSame($expectedCreated, $result['summary']['create'], $this->messages($result));
        $this->assertSame($policy === 'skip' ? 1 : 0, $result['summary']['skip']);
        $this->assertSame($expectedActivities, $this->countActivities());
        $this->assertNotSame(array(), $result['rows'][0]['duplicates'], 'the candidate is reported either way');
    }

    /**
     * REQ-DEC-008 leaves the policy without a default. An import that guessed would be the one
     * mistake nobody notices: the rows land, and the correction becomes a second attendance.
     */
    public function testAnImportWithoutAnExplicitDuplicatePolicyIsRefused(): void
    {
        $this->createMember('req_imp_nopolicy');
        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date'),
            array('req_imp_nopolicy', 'First aid', '2026-03-01')
        ));

        $service = new ImportService($this->getDatabase());
        $rows = $service->readFile($file);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate policy must be given explicitly');

        $service->analyse($rows, $service->resolveMapping($rows), array());
    }

    /**
     * A source key says which record a row is, so a re-import of the same export is recognised even
     * when the title has been corrected in the meantime - which is what an exact-field match cannot
     * do and why §12 prefers a key when the source provides one.
     */
    public function testASourceKeyIdentifiesTheRowEvenWhenTheTitleChanged(): void
    {
        $member = $this->createMember('req_imp_key');

        $first = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'source_key'),
            array('req_imp_key', 'Fisrt aid', '2026-03-01', 'EXT-4711')
        ));
        $this->assertSame(1, $this->import($first)['summary']['create']);

        $second = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'source_key'),
            array('req_imp_key', 'First aid', '2026-03-01', 'EXT-4711')
        ));
        $result = $this->import($second, array('duplicates' => 'skip'));

        $this->assertSame(1, $result['summary']['skip']);
        $this->assertStringContainsString('EXT-4711', $result['rows'][0]['messages'][0]);
        $this->assertSame(1, $this->countActivities());
    }

    public function testARowMayOverruleTheImportLevelDuplicatePolicy(): void
    {
        $member = $this->createMember('req_imp_rowpolicy');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 8.0);
        $existing = (string) $this->getDatabase()->queryPrepared(
            'SELECT rqa_title FROM ' . RequirementsTables::activities() . ' WHERE rqa_user_id = ?',
            array($member['usr_id'])
        )->fetchColumn();

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'duplicate'),
            array('req_imp_rowpolicy', $existing, '2026-03-01', 'create')
        ));

        $result = $this->import($file, array('duplicates' => 'skip'));

        $this->assertSame(1, $result['summary']['create'], $this->messages($result));
        $this->assertSame(2, $this->countActivities());
    }

    /**
     * The preview writes nothing. Not "nothing that survives": `analyse()` opens no transaction and
     * builds no entity, so there is nothing to roll back - which is why the guarantee still holds
     * when a caller has a transaction of its own open around it.
     */
    public function testThePreviewWritesNothing(): void
    {
        $member = $this->createMember('req_imp_preview');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $short = (string) $type->getValue('rqt_short_name');

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'credit_' . $short),
            array('req_imp_preview', 'First aid', '2026-03-01', '8'),
            array('req_imp_preview', 'Broken', 'not a date', '3')
        ));

        $before = $this->tableCounts();
        $service = new ImportService($this->getDatabase());
        $rows = $service->readFile($file);
        $result = $service->analyse($rows, $service->resolveMapping($rows), array('duplicates' => 'create'));

        $this->assertSame($before, $this->tableCounts(), 'a preview may not write a single row');
        $this->assertSame(1, $result['summary']['create']);
        $this->assertSame(1, $result['summary']['error']);
        $this->assertSame('create', $result['rows'][0]['status']);
        $this->assertStringContainsString('is not a date', $result['rows'][1]['messages'][0]);
        // The preview reports the credits it would write, which is what a reviewer confirms.
        $this->assertSame(8.0, $result['rows'][0]['credits'][0]['claimed']);
    }

    /**
     * A row is one unit and the rows around it are not.
     *
     * This runs outside the per-test transaction: the import rolls a row back on purpose, and
     * `Database::rollback()` unwinds every open transaction rather than the innermost one, so the
     * fixtures would go with it and the failure would read as "nothing was imported".
     */
    public function testAFailingRowRollsBackAloneAndLeavesTheOthersStanding(): void
    {
        $this->outsideTheTestTransaction(function (): void {
            $member = $this->createMember('req_imp_rollback');
            $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
            $short = (string) $type->getValue('rqt_short_name');

            // The middle row's activity is fine and its credit is not: the period is nonsense, so
            // the write fails after the activity has been saved inside the row's transaction.
            $file = $this->csv(array(
                array('user_login', 'title', 'begin_date', 'credit_' . $short, 'period_' . $short),
                array('req_imp_rollback', 'Good one', '2026-03-01', '8', '2026'),
                array('req_imp_rollback', 'Half written', '2026-04-01', '5', 'not a period'),
                array('req_imp_rollback', 'Good two', '2026-05-01', '3', '2026')
            ));

            $result = $this->import($file);

            $this->assertSame(2, $result['summary']['create'], $this->messages($result));
            $this->assertSame(1, $result['summary']['error']);

            $titles = $this->getDatabase()->queryPrepared(
                'SELECT rqa_title FROM ' . RequirementsTables::activities()
                . ' WHERE rqa_user_id = ? ORDER BY rqa_begin_date',
                array($member['usr_id'])
            )->fetchAll(\PDO::FETCH_COLUMN);

            $this->assertSame(
                array('Good one', 'Good two'),
                $titles,
                'the failed row leaves no activity behind, and the rows around it stay'
            );
        });
    }

    /**
     * §12 assigns a normal user's rows server-side. A member's file that names somebody else is not
     * refused - a course provider's export plausibly carries a name column - but the name in it is
     * not what decides who the activity belongs to.
     */
    public function testAMemberColumnInANormalMembersFileIsIgnoredRatherThanObeyed(): void
    {
        $member = $this->createMember('req_imp_member');
        $other = $this->createMember('req_imp_other');
        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date'),
            array('req_imp_other', 'First aid', '2026-03-01')
        ));

        $this->asMember($member['usr_id'], function () use ($file): void {
            $service = new ImportService($this->getDatabase());
            $rows = $service->readFile($file);

            $mapping = $service->resolveMapping($rows, array(), true, false);
            $this->assertArrayNotHasKey('user_login', $mapping);

            $service->import($rows, $mapping, array('duplicates' => 'create'));
        });

        $this->assertSame($member['usr_id'], (int) $this->getDatabase()->queryPrepared(
            'SELECT rqa_user_id FROM ' . RequirementsTables::activities(),
            array()
        )->fetchColumn(), 'the row belongs to the member who imported it, not to the one it names');
        $this->assertNotSame($other['usr_id'], $member['usr_id']);
    }

    /**
     * The backstop behind that: a mapping built by hand - which the wizard cannot produce, but a
     * caller of the service can - is refused rather than quietly reassigned.
     */
    public function testANormalMemberIsRefusedAMemberColumnThatWasMappedByHand(): void
    {
        $member = $this->createMember('req_imp_handmapped');
        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date'),
            array('somebody_else', 'First aid', '2026-03-01')
        ));

        $this->asMember($member['usr_id'], function () use ($file): void {
            $service = new ImportService($this->getDatabase());
            $rows = $service->readFile($file);

            $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($service, $rows): void {
                $service->analyse(
                    $rows,
                    array('user_login' => 0, 'title' => 1, 'begin_date' => 2),
                    array('duplicates' => 'create')
                );
            });
        });

        $this->assertSame(0, $this->countActivities());
    }

    public function testAMembersOwnImportIsAssignedToThem(): void
    {
        $member = $this->createMember('req_imp_own');
        $file = $this->csv(array(
            array('title', 'begin_date'),
            array('First aid', '2026-03-01')
        ));

        $this->asMember($member['usr_id'], function () use ($file): void {
            $service = new ImportService($this->getDatabase());
            $rows = $service->readFile($file);
            $service->import($rows, $service->resolveMapping($rows), array('duplicates' => 'create'));
        });

        $this->assertSame($member['usr_id'], (int) $this->getDatabase()->queryPrepared(
            'SELECT rqa_user_id FROM ' . RequirementsTables::activities(),
            array()
        )->fetchColumn());
    }

    /**
     * A credited period may be written the way the requirement counts, and a plain date still says
     * which period it means - the same two answers the activity form's period control accepts.
     */
    public function testACreditedPeriodMayBeWrittenAsAPeriodOrAsADate(): void
    {
        $member = $this->createMember('req_imp_period');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0, array(), array(
            'rqt_period_unit' => 'quarter'
        ));
        $short = (string) $type->getValue('rqt_short_name');

        $file = $this->csv(array(
            array('user_login', 'title', 'begin_date', 'credit_' . $short, 'period_' . $short),
            array('req_imp_period', 'As a quarter', '2026-01-15', '4', '2026-Q2'),
            array('req_imp_period', 'As a date', '2026-01-16', '4', '2026-08-20')
        ));

        $result = $this->import($file);

        $this->assertSame(0, $result['summary']['error'], $this->messages($result));
        $this->assertSame(
            array('2026-04-01', '2026-07-01'),
            $this->getDatabase()->queryPrepared(
                'SELECT rac_period_reference FROM ' . RequirementsTables::activityCredits()
                . ' INNER JOIN ' . RequirementsTables::activities() . ' ON rqa_id = rac_activity_id'
                . ' ORDER BY rqa_begin_date',
                array()
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * Run an import as the administrator the test case logs in as.
     *
     * @param array<string,mixed> $options
     * @param array<string,string> $assignments
     * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>}
     */
    private function import(
        string $file,
        array $options = array(),
        array $assignments = array(),
        string $worksheet = ''
    ): array {
        $service = new ImportService($this->getDatabase());
        $rows = $service->readFile($file, 'AUTO', '', '', 'AUTO', $worksheet);

        return $service->import(
            $rows,
            $service->resolveMapping($rows, $assignments),
            array_merge(array('duplicates' => 'create'), $options)
        );
    }

    /**
     * @param array<int,array<int,mixed>> $rows
     */
    private function csv(array $rows): string
    {
        $lines = array();
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                static fn(mixed $value): string => '"' . str_replace('"', '""', (string) $value) . '"',
                $row
            ));
        }

        return $this->write(implode("\n", $lines) . "\n", 'csv');
    }

    /**
     * @param array<string,array<int,array<int,mixed>>> $sheets
     */
    private function xlsx(array $sheets): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    // setValueExplicit() would need a type per cell; what matters here is that a
                    // formula stays a formula in the file, which is what the reader then sees.
                    $sheet->getCell(array($columnIndex + 1, $rowIndex + 1))->setValue($value);
                }
            }
        }

        $path = $this->reserve('xlsx');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function write(string $content, string $extension): string
    {
        $path = $this->reserve($extension);
        file_put_contents($path, $content);

        return $path;
    }

    private function reserve(string $extension): string
    {
        $path = sys_get_temp_dir() . '/req_import_' . uniqid('', true) . '.' . $extension;
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function nameMember(int $userId, string $first, string $last): void
    {
        global $gProfileFields;

        foreach (array('FIRST_NAME' => $first, 'LAST_NAME' => $last) as $field => $value) {
            $usfId = (int) $gProfileFields->getProperty($field, 'usf_id');
            $this->getDatabase()->queryPrepared(
                'DELETE FROM ' . TBL_USER_DATA . ' WHERE usd_usr_id = ? AND usd_usf_id = ?',
                array($userId, $usfId)
            );
            $this->getDatabase()->queryPrepared(
                'INSERT INTO ' . TBL_USER_DATA . ' (usd_usr_id, usd_usf_id, usd_value) VALUES (?, ?, ?)',
                array($userId, $usfId, $value)
            );
        }
    }

    private function countActivities(): int
    {
        return (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::activities(),
            array()
        )->fetchColumn();
    }

    /**
     * @return array<string,int>
     */
    private function tableCounts(): array
    {
        $counts = array();
        foreach (array(
            RequirementsTables::activities(),
            RequirementsTables::activityCredits(),
            RequirementsTables::providers(),
            RequirementsTables::requirementTypes()
        ) as $table) {
            $counts[$table] = (int) $this->getDatabase()
                ->queryPrepared('SELECT COUNT(*) FROM ' . $table, array())->fetchColumn();
        }

        return $counts;
    }

    /**
     * Every message the import produced, so a failing assertion says why rather than only that.
     *
     * @param array{rows:array<int,array<string,mixed>>} $result
     */
    private function messages(array $result): string
    {
        $messages = array();
        foreach ($result['rows'] as $row) {
            foreach ($row['messages'] as $message) {
                $messages[] = 'row ' . $row['row'] . ': ' . $message;
            }
        }

        return implode("\n", $messages);
    }
}
