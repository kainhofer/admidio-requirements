<?php
namespace AdmidioPlugin\Requirements\Tests\Cli;

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Cli\RequirementsCli;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;
use InvalidArgumentException;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The CLI callbacks against a real database (§13).
 *
 * The commands are invoked the way `CliApplication` invokes them - arguments and options as plain
 * arrays - so the test covers the callback and the services under it without a subprocess. What a
 * subprocess would add is the bootstrap and the argument parser, and both are covered by the core
 * CLI suite; what matters here is that each command writes what it claims to and refuses what it
 * should.
 */
final class RequirementsCliTest extends RequirementsDatabaseTestCase
{
    /** A minimal but genuine PDF, so the MIME sniff has something real to recognise. */
    private const PDF = "%PDF-1.4
1 0 obj<</Type/Catalog>>endobj
trailer<</Root 1 0 R>>
%%EOF
";

    /** @var array<int,string> import and certificate files written by a test, removed afterwards */
    private array $importFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->importFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->importFiles = array();

        parent::tearDown();
    }

    /**
     * Run a command and return the rows it printed as JSON.
     *
     * @param array<int,string> $arguments
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    private function runCommand(string $method, array $arguments = array(), array $options = array()): array
    {
        $options = array_merge(array('format' => 'json', 'yes' => true), $options);

        ob_start();
        try {
            $exit = RequirementsCli::$method($arguments, $options);
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(0, $exit, $method . ' should report success.');

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded, $method . ' should print JSON, got: ' . $output);

        return $decoded;
    }

    // ---------------------------------------------------------------- list commands

    /**
     * The list commands print the numeric id, which is what the update and delete commands take.
     * A UUID column would be unreadable in a terminal and is deliberately not there.
     */
    public function testProviderListPrintsTheIdAndNotTheUuid(): void
    {
        $this->runCommand('providerAdd', array('Institute'), array('short-name' => 'INS'));

        $rows = $this->runCommand('providerList');

        $this->assertNotSame(array(), $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayNotHasKey('uuid', $rows[0]);
        $this->assertSame('Institute', $rows[0]['name']);
    }

    public function testRequirementListPrintsTheIdAndNotTheUuid(): void
    {
        $this->runCommand('requirementAdd', array('CPD'), array('period-unit' => 'year', 'short-name' => 'CPD'));

        $rows = $this->runCommand('requirementList');

        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayNotHasKey('uuid', $rows[0]);
    }

    public function testRuleListShowsTheRuleWithItsRoleAndValidity(): void
    {
        global $gCurrentOrgId;

        $type = $this->createRequirementType('Rules');
        $role = $this->fixture->createAndSaveRole('CLI rule role', (int) $gCurrentOrgId);
        $this->runCommand(
            'ruleAdd',
            array((string) $type->getValue('rqt_id'), (string) $role['rol_id'], '12'),
            array('valid-from' => '2026-01-01')
        );

        $rows = $this->runCommand('ruleList');

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayNotHasKey('uuid', $rows[0]);
        $this->assertSame('CLI rule role', $rows[0]['role']);
        $this->assertSame('2026-01-01', $rows[0]['valid_from']);
    }

    public function testActivityListShowsTheActivitiesOfTheOrganisation(): void
    {
        $this->runCommand('activityAdd', array('First course'), array('begin' => '2026-03-01'));
        $this->runCommand('activityAdd', array('Second course'), array('begin' => '2026-04-01'));

        $rows = $this->runCommand('activityList');

        $titles = array_column($rows, 'title');
        $this->assertContains('First course', $titles);
        $this->assertContains('Second course', $titles);
        $this->assertArrayNotHasKey('uuid', $rows[0]);
    }

    public function testActivityListCanShowTheCreditsOfOneRequirement(): void
    {
        $type = $this->createRequirementType('Credited');
        $activity = $this->runCommand('activityAdd', array('Credited course'), array('begin' => '2026-03-01'));
        $this->runCommand('creditSet', array(
            $activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-05-01', '7.5'
        ));

        $rows = $this->runCommand('activityList', array(), array('requirement' => (string) $type->getValue('rqt_id')));

        $this->assertSame('2026-01-01', $rows[0]['credited_period']);
        $this->assertSame(7.5, (float) $rows[0]['claimed']);
    }

    public function testActivityListNarrowsToOneMember(): void
    {
        $member = $this->createMember('cli_list_member');
        $this->runCommand('activityAdd', array('Theirs'), array('begin' => '2026-03-01', 'user' => (string) $member['usr_id']));
        $this->runCommand('activityAdd', array('Mine'), array('begin' => '2026-03-01'));

        $rows = $this->runCommand('activityList', array(), array('user' => (string) $member['usr_id']));

        $this->assertSame(array('Theirs'), array_column($rows, 'title'));
    }

    // ---------------------------------------------------------------- mutations and dry runs

    public function testProviderAddWritesTheProvider(): void
    {
        $rows = $this->runCommand('providerAdd', array('Academy'), array('short-name' => 'ACA', 'qualified' => 'true'));

        $this->assertSame('Academy', $rows[0]['name']);
        $this->assertTrue($rows[0]['qualified']);
        $this->assertSame(1, $this->countRows(RequirementsTables::providers()));
    }

    /**
     * §13: every mutation can be rehearsed, and a rehearsal leaves nothing behind.
     */
    public function testADryRunProviderAddWritesNothing(): void
    {
        $rows = $this->runCommand('providerAdd', array('Never saved'), array('dry-run' => true));

        $this->assertTrue($rows[0]['dry_run']);
        $this->assertSame(0, $this->countRows(RequirementsTables::providers()));
    }

    /**
     * A dry run has to leave a record it was told to delete.
     *
     * This one runs outside the per-test transaction. `Database::rollback()` unwinds everything that
     * is open rather than only the innermost level, so the rehearsal inside `mutate()` would take
     * the test's own transaction - and with it the record created a line earlier - down with it.
     * On the command line there is no outer transaction, so this is the production shape.
     */
    public function testADryRunDeleteLeavesTheRecord(): void
    {
        $this->outsideTheTestTransaction(function (): void {
            $added = $this->runCommand('providerAdd', array('Kept'));

            $this->runCommand('providerDelete', array($added[0]['uuid']), array('dry-run' => true));

            $this->assertSame(1, $this->countRows(RequirementsTables::providers()));
        });
    }

    public function testProviderDeleteRemovesTheProvider(): void
    {
        $added = $this->runCommand('providerAdd', array('Doomed'));

        $rows = $this->runCommand('providerDelete', array($added[0]['uuid']));

        $this->assertTrue($rows[0]['deleted']);
        $this->assertSame(0, $this->countRows(RequirementsTables::providers()));
    }

    public function testRequirementDeleteRemovesTheTypeAndItsRules(): void
    {
        global $gCurrentOrgId;

        $type = $this->createRequirementType('Doomed requirement');
        $role = $this->fixture->createAndSaveRole('Doomed rule role', (int) $gCurrentOrgId);
        $this->runCommand('ruleAdd', array((string) $type->getValue('rqt_id'), (string) $role['rol_id'], '5'));
        $this->assertSame(1, $this->countRows(RequirementsTables::requirementRoleRules()));

        $this->runCommand('requirementDelete', array((string) $type->getValue('rqt_id')));

        $this->assertSame(0, $this->countRows(RequirementsTables::requirementTypes()));
        $this->assertSame(0, $this->countRows(RequirementsTables::requirementRoleRules()));
    }

    /**
     * Finding 4.9: a type that still has credits is refused with a sentence, not a driver error.
     */
    public function testRequirementDeleteRefusesATypeThatStillHasCredits(): void
    {
        global $gL10n;

        $type = $this->createRequirementType('Has credits');
        $activity = $this->runCommand('activityAdd', array('Contributing'), array('begin' => '2026-03-01'));
        $this->runCommand('creditSet', array($activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-01-01', '3'));

        try {
            $this->runCommand('requirementDelete', array((string) $type->getValue('rqt_id')));
            $this->fail('A requirement type with credits should not be deletable.');
        } catch (Exception $exception) {
            $this->assertSame(
                $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_HAS_CREDITS', array(1)),
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $this->countRows(RequirementsTables::requirementTypes()));
    }

    public function testRuleDeleteRemovesOneRule(): void
    {
        global $gCurrentOrgId;

        $type = $this->createRequirementType('Rule delete');
        $role = $this->fixture->createAndSaveRole('Rule delete role', (int) $gCurrentOrgId);
        $added = $this->runCommand('ruleAdd', array((string) $type->getValue('rqt_id'), (string) $role['rol_id'], '5'));

        $this->runCommand('ruleDelete', array($added[0]['uuid']));

        $this->assertSame(0, $this->countRows(RequirementsTables::requirementRoleRules()));
    }

    public function testCreditSetThenCreditDelete(): void
    {
        $type = $this->createRequirementType('Credit lifecycle');
        $activity = $this->runCommand('activityAdd', array('Course'), array('begin' => '2026-03-01'));

        $credit = $this->runCommand('creditSet', array(
            $activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-05-01', '4'
        ));
        $this->assertSame('2026-01-01', $credit[0]['period_reference'], 'The period is normalised.');
        $this->assertSame(1, $this->countRows(RequirementsTables::activityCredits()));

        $this->runCommand('creditDelete', array(
            $activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-08-01'
        ));

        $this->assertSame(0, $this->countRows(RequirementsTables::activityCredits()));
    }

    public function testCreditDeleteReportsAPeriodThatHasNoCredit(): void
    {
        $type = $this->createRequirementType('No credit');
        $activity = $this->runCommand('activityAdd', array('Course'), array('begin' => '2026-03-01'));

        $this->expectException(InvalidArgumentException::class);

        $this->runCommand('creditDelete', array(
            $activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2020-01-01'
        ));
    }

    /**
     * Finding 2.1: the CLI half of the `until_submitted` workflow.
     */
    public function testActivitySubmitMovesADraftOn(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));

        $added = $this->runCommand('activityAdd', array('Draft course'), array('begin' => '2026-03-01'));
        $this->assertSame('draft', $added[0]['status']);

        $rows = $this->runCommand('activitySubmit', array($added[0]['uuid']));

        $this->assertSame('submitted', $rows[0]['status']);
    }

    /** @see testADryRunDeleteLeavesTheRecord for why this runs outside the test transaction. */
    public function testADryRunSubmitLeavesTheDraftAlone(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));

        $this->outsideTheTestTransaction(function (): void {
            $added = $this->runCommand('activityAdd', array('Still a draft'), array('begin' => '2026-03-01'));
            $this->runCommand('activitySubmit', array($added[0]['uuid']), array('dry-run' => true));

            $this->assertSame(
                'draft',
                (string) $this->reloadActivity($added[0]['uuid'])->getValue('rqa_status')
            );
        });
    }

    public function testActivityDeleteTakesItsCreditsWithIt(): void
    {
        $type = $this->createRequirementType('Cascade');
        $activity = $this->runCommand('activityAdd', array('Doomed course'), array('begin' => '2026-03-01'));
        $this->runCommand('creditSet', array($activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-01-01', '3'));

        $this->runCommand('activityDelete', array($activity[0]['uuid']));

        $this->assertSame(0, $this->countRows(RequirementsTables::activities()));
        $this->assertSame(0, $this->countRows(RequirementsTables::activityCredits()));
    }

    // ---------------------------------------------------------------- authorization

    /**
     * §13: `--user` is restricted to the actor unless they administer the component.
     */
    public function testReportRefusesAnotherUserForANonAdministrator(): void
    {
        $member = $this->createMember('cli_report_member');
        $other = $this->createMember('cli_report_other');

        $this->expectException(Exception::class);

        $this->asMember($member['usr_id'], function () use ($other): void {
            $this->runCommand('report', array(), array('user' => (string) $other['usr_id']));
        });
    }

    public function testActivityListRefusesAnotherUserForANonAdministrator(): void
    {
        $member = $this->createMember('cli_activities_member');
        $other = $this->createMember('cli_activities_other');

        $this->expectException(Exception::class);

        $this->asMember($member['usr_id'], function () use ($other): void {
            $this->runCommand('activityList', array(), array('user' => (string) $other['usr_id']));
        });
    }

    public function testAMemberMayReportOnThemselves(): void
    {
        $member = $this->createMember('cli_report_self');
        $type = $this->createRequirementType('Self report');
        $this->addRule($type, $member['rol_id'], 10.0);

        $rows = $this->asMember($member['usr_id'], fn(): array => $this->runCommand('report'));

        $this->assertNotSame(array(), $rows);
        $this->assertSame('Self report', $rows[0]['requirement']);
        $this->assertSame(10.0, (float) $rows[0]['required']);
    }

    /**
     * §13: `--details` adds the contributing activities under each requirement.
     */
    public function testReportDetailsListTheContributingActivities(): void
    {
        $member = $this->createMember('cli_report_details');
        $type = $this->createRequirementType('Detailed');
        $this->addRule($type, $member['rol_id'], 10.0);

        $activity = $this->runCommand(
            'activityAdd',
            array('Counted course'),
            array('begin' => '2026-03-01', 'user' => (string) $member['usr_id'])
        );
        $this->runCommand('creditSet', array($activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-01-01', '4'));

        $summary = $this->runCommand('report', array(), array('user' => (string) $member['usr_id']));
        $detailed = $this->runCommand('report', array(), array('user' => (string) $member['usr_id'], 'details' => true));

        $this->assertCount(1, $summary);
        $this->assertCount(2, $detailed);
        $this->assertSame('activity', $detailed[1]['status']);
        $this->assertStringContainsString('Counted course', (string) $detailed[1]['requirement']);
    }

    /**
     * §13: `--requirement` turns the report around - one requirement across the members it concerns,
     * which is the roster the web review view shows.
     */
    public function testReportWithARequirementListsEveryMemberItConcerns(): void
    {
        $member = $this->createMember('cli_roster_member');
        $type = $this->createRequirementType('Roster');
        $this->addRule($type, $member['rol_id'], 10.0);

        $activity = $this->runCommand(
            'activityAdd',
            array('Roster course'),
            array('begin' => '2026-03-01', 'user' => (string) $member['usr_id'])
        );
        $this->runCommand('creditSet', array($activity[0]['uuid'], (string) $type->getValue('rqt_id'), '2026-01-01', '4'));

        $rows = $this->runCommand('report', array(), array(
            'requirement' => (string) $type->getValue('rqt_id'),
            'period' => '2026-06-01'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame(4.0, (float) $rows[0]['attained']);
        $this->assertSame(10.0, (float) $rows[0]['required']);
        $this->assertSame('missing', $rows[0]['status']);
        // The first column names the member here, where the per-member report names the requirement.
        $this->assertArrayHasKey('member', $rows[0]);
    }

    public function testTheRosterStatusOptionNarrowsTheReport(): void
    {
        $member = $this->createMember('cli_roster_status');
        $type = $this->createRequirementType('Roster status');
        $this->addRule($type, $member['rol_id'], 10.0);

        $options = array('requirement' => (string) $type->getValue('rqt_id'), 'period' => '2026-06-01');

        $this->assertCount(1, $this->runCommand('report', array(), $options + array('status' => 'missing')));
        $this->assertSame(array(), $this->runCommand('report', array(), $options + array('status' => 'fulfilled')));
    }

    /**
     * The roster is administrative: a normal member asking for one is refused rather than quietly
     * handed their own row.
     */
    public function testANonAdministratorCannotAskForARoster(): void
    {
        $member = $this->createMember('cli_roster_rights');
        $type = $this->createRequirementType('Roster rights');
        $this->addRule($type, $member['rol_id'], 10.0);
        $typeId = (string) $type->getValue('rqt_id');

        $this->expectException(Exception::class);

        $this->asMember($member['usr_id'], function () use ($typeId): void {
            $this->runCommand('report', array(), array('requirement' => $typeId));
        });
    }

    /**
     * A record of another organisation is not addressable, whatever reference is typed.
     */
    public function testACommandCannotReachAnotherOrganisationsRecord(): void
    {
        $db = $this->getDatabase();
        $otherOrgId = (int) $this->fixture->createAndSaveOrganization('CLI Other', 'CLIOTH')['org_id'];

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::providers()
            . ' (rqp_uuid, rqp_org_id, rqp_name) VALUES (?, ?, ?)',
            array('dddddddd-0000-0000-0000-000000000001', $otherOrgId, 'Elsewhere')
        );

        $this->expectException(InvalidArgumentException::class);

        $this->runCommand('providerUpdate', array('dddddddd-0000-0000-0000-000000000001'), array('name' => 'Renamed'));
    }

    public function testAnUnknownReferenceIsReportedAsNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runCommand('providerUpdate', array('99999999'), array('name' => 'Nothing here'));
    }

    // ---------------------------------------------------------------- import

    /**
     * The command reports what an import would do and writes nothing, because writing is what
     * `--commit` is for. An import command whose default is to write is one accident away from a
     * database full of somebody's spreadsheet.
     */
    public function testImportWithoutCommitWritesNothing(): void
    {
        $this->createMember('cli_import_preview');
        $file = $this->importFile(array(
            array('user_login', 'title', 'begin_date'),
            array('cli_import_preview', 'First aid', '2026-03-01')
        ));

        $rows = $this->runCommand('import', array($file), array('duplicates' => 'create'));

        $this->assertSame('create', $rows[0]['status']);
        $this->assertSame('First aid', $rows[0]['title']);
        $this->assertSame('preview', $rows[1]['status'], 'the last row is the summary');
        $this->assertSame(0, $this->countRows(RequirementsTables::activities()));
    }

    public function testImportWithCommitWritesTheRowsAndTheirCredits(): void
    {
        $this->createMember('cli_import_commit');
        $type = $this->createRequirementType('CLI import');
        $type->setValue('rqt_short_name', 'CLIIMP');
        $type->save();

        $file = $this->importFile(array(
            array('user_login', 'title', 'begin_date', 'credit_CLIIMP'),
            array('cli_import_commit', 'First aid', '2026-03-01', '8')
        ));

        $rows = $this->runCommand('import', array($file), array(
            'duplicates' => 'create',
            'commit' => true
        ));

        $this->assertSame('imported', $rows[0]['status']);
        $this->assertSame('total', $rows[1]['status']);
        $this->assertSame(1, $this->countRows(RequirementsTables::activities()));
        $this->assertSame(8.0, (float) $this->getDatabase()->queryPrepared(
            'SELECT rac_credits_claimed FROM ' . RequirementsTables::activityCredits()
        )->fetchColumn());
    }

    /**
     * A file whose columns are not the field names is mapped by --map, which is what makes the
     * command usable at all against an export nobody here controls.
     */
    public function testImportMapsColumnsByTheirHeadingOrTheirIndex(): void
    {
        $this->createMember('cli_import_map');
        $file = $this->importFile(array(
            array('Who', 'Course', 'When'),
            array('cli_import_map', 'First aid', '2026-03-01')
        ));

        $rows = $this->runCommand('import', array($file), array(
            'duplicates' => 'create',
            'commit' => true,
            'map' => array('user_login=Who', 'title=Course', 'begin_date=2')
        ));

        $this->assertSame('imported', $rows[0]['status'], (string) $rows[0]['messages']);
        $this->assertSame('First aid', (string) $this->getDatabase()->queryPrepared(
            'SELECT rqa_title FROM ' . RequirementsTables::activities()
        )->fetchColumn());
    }

    public function testImportRefusesAMalformedMapAssignment(): void
    {
        $this->createMember('cli_import_badmap');
        $file = $this->importFile(array(
            array('title', 'begin_date'),
            array('First aid', '2026-03-01')
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--map expects FIELD=COLUMN');

        $this->runCommand('import', array($file), array(
            'duplicates' => 'create',
            'map' => array('nonsense')
        ));
    }

    /**
     * REQ-DEC-008 gives the policy no default, and the command must not invent one - not even the
     * safe-looking `skip`, which would silently drop a corrected re-import.
     */
    public function testImportRefusesToRunWithoutADuplicatePolicy(): void
    {
        $this->createMember('cli_import_nopolicy');
        $file = $this->importFile(array(
            array('user_login', 'title', 'begin_date'),
            array('cli_import_nopolicy', 'First aid', '2026-03-01')
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate policy must be given explicitly');

        $this->runCommand('import', array($file), array());
    }

    /**
     * --user assigns the whole file to one member, for an export that names the course but not who
     * attended it. It is the administrator's alternative to a member column, not an addition to it.
     */
    public function testImportAssignsTheWholeFileToTheMemberGivenByUser(): void
    {
        $member = $this->createMember('cli_import_user');
        $file = $this->importFile(array(
            array('title', 'begin_date'),
            array('First aid', '2026-03-01')
        ));

        $this->runCommand('import', array($file), array(
            'duplicates' => 'create',
            'commit' => true,
            'user' => (string) $member['usr_id']
        ));

        $this->assertSame($member['usr_id'], (int) $this->getDatabase()->queryPrepared(
            'SELECT rqa_user_id FROM ' . RequirementsTables::activities()
        )->fetchColumn());
    }

    /**
     * A file that produced nothing but errors is a failed import rather than a successful report of
     * failures: a nightly job piping an export in has to be able to notice.
     */
    public function testImportExitsNonZeroWhenEveryRowFailed(): void
    {
        $this->createMember('cli_import_fail');
        $file = $this->importFile(array(
            array('user_login', 'title', 'begin_date'),
            array('nobody_at_all', 'First aid', '2026-03-01')
        ));

        ob_start();
        try {
            $exit = RequirementsCli::import(
                array($file),
                array('format' => 'json', 'duplicates' => 'create', 'commit' => true)
            );
        } finally {
            ob_end_clean();
        }

        $this->assertSame(1, $exit);
        $this->assertSame(0, $this->countRows(RequirementsTables::activities()));
    }

    /**
     * @param array<int,array<int,mixed>> $rows
     */
    private function importFile(array $rows): string
    {
        $lines = array();
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                static fn(mixed $value): string => '"' . str_replace('"', '""', (string) $value) . '"',
                $row
            ));
        }

        $path = sys_get_temp_dir() . '/req_cli_import_' . uniqid('', true) . '.csv';
        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->importFiles[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------- certificate files

    /**
     * The CLI attaches a file through the same service the web upload uses, so the type is decided
     * by what is in the file rather than by what it is called. A migration script feeding a
     * directory of scanned certificates in must not be a way around the check.
     */
    public function testFileAddAppliesTheSameTypeCheckAsTheWebUpload(): void
    {
        $this->enableFiles();
        $activity = $this->activityForFiles('cli_file_type');

        $script = $this->writeFile("<?php echo 'pwned';", 'php');

        try {
            $this->runCommand('fileAdd', array((string) $activity->getValue('rqa_uuid'), $script));
            $this->fail('a PHP script should not be attachable as a certificate');
        } catch (Exception $exception) {
            $this->assertStringContainsString('text/x-php', $exception->getMessage());
        }

        $this->assertSame(0, $this->countRows(RequirementsTables::activityFiles()));
    }

    public function testFileAddAndFileListRoundTrip(): void
    {
        $this->enableFiles();
        $activity = $this->activityForFiles('cli_file_add');

        $added = $this->runCommand('fileAdd', array(
            (string) $activity->getValue('rqa_uuid'),
            $this->writeFile(self::PDF, 'pdf')
        ), array('name' => 'Course confirmation.pdf'));

        $this->assertSame('application/pdf', $added[0]['mime_type']);
        $this->assertSame('Course confirmation.pdf', $added[0]['name']);

        $listed = $this->runCommand('fileList', array((string) $activity->getValue('rqa_uuid')));

        $this->assertCount(1, $listed);
        $this->assertSame($added[0]['uuid'], $listed[0]['uuid']);
    }

    /**
     * file-list is readable by a member, and the record it may read is its own. The command is
     * registered ACCESS_VISIBLE, so this refusal is the whole protection.
     */
    public function testFileListRefusesAnotherMembersActivity(): void
    {
        $this->enableFiles();
        $activity = $this->activityForFiles('cli_file_owner');
        $other = $this->createMember('cli_file_other');

        $this->asMember($other['usr_id'], function () use ($activity): void {
            $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($activity): void {
                $this->runCommand('fileList', array((string) $activity->getValue('rqa_uuid')));
            });
        });
    }

    public function testFilesGcReportsBeforeItDeletes(): void
    {
        $this->enableFiles();
        $activity = $this->activityForFiles('cli_file_gc');
        $file = (new ActivityFileService($this->getDatabase()))->add(
            $activity,
            array(
                'name' => 'certificate.pdf',
                'tmp_name' => $this->writeFile(self::PDF, 'pdf'),
                'size' => strlen(self::PDF),
                'error' => UPLOAD_ERR_OK
            )
        );
        $path = (new ActivityFileService($this->getDatabase()))->pathFor($file);
        unlink($path);

        $reported = $this->runCommand('filesGc');
        $this->assertSame('row without file', $reported[0]['kind']);
        $this->assertSame(1, $this->countRows(RequirementsTables::activityFiles()), 'a report deletes nothing');

        $this->runCommand('filesGc', array(), array('commit' => true));
        $this->assertSame(0, $this->countRows(RequirementsTables::activityFiles()));
    }

    /**
     * Switch the certificate feature on for the running test.
     */
    private function enableFiles(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            ActivityFileService::SETTING_ENABLED => '1',
            ActivityFileService::SETTING_MAX_COUNT => '5',
            ActivityFileService::SETTING_MAX_SIZE => '10',
            ActivityFileService::SETTING_TOTAL_QUOTA => '0'
        ));
    }

    private function activityForFiles(string $login): Activity
    {
        $member = $this->createMember($login);
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'Course for ' . $login,
            'rqa_begin_date' => '2026-03-01'
        ), $member['usr_id']);

        return $activity;
    }

    private function writeFile(string $content, string $extension): string
    {
        $path = sys_get_temp_dir() . '/req_cli_file_' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->importFiles[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run a callback with the per-test transaction closed, then put the plugin tables back as they
     * were and reopen it so tearDown() finds what it expects.
     *
     * Only a test that exercises `--dry-run` needs this, and only because a rollback is what it is
     * testing. Everything the callback writes is committed, so the plugin tables are emptied
     * afterwards - they are this plugin's own and every test starts from an empty set anyway.
     */
    private function countRows(string $table): int
    {
        return (int) $this->getDatabase()->queryPrepared('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function reloadActivity(string $uuid): Activity
    {
        $activity = new Activity($this->getDatabase());
        $activity->readDataByUuid($uuid);

        return $activity;
    }

    private function createRequirementType(string $name): RequirementType
    {
        $type = new RequirementType($this->getDatabase());
        (new RequirementTypeService($this->getDatabase()))->saveType($type, array(
            'rqt_name' => $name,
            'rqt_period_unit' => 'year',
            'rqt_period_anchor' => 'calendar'
        ));

        return $type;
    }

    private function addRule(RequirementType $type, int $roleId, float $credits): void
    {
        (new RequirementTypeService($this->getDatabase()))->saveRule(
            new RequirementRoleRule($this->getDatabase()),
            $type,
            array(
                'rrr_role_id' => $roleId,
                'rrr_required_credits' => $credits,
                'rrr_partial_period_mode' => 'full'
            )
        );
    }
}
