<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Changelog\Service\ChangelogService;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * What the requirements plugin writes into the change history: nothing for a save that changes
 * nothing, a readable name and related object for what it does write, no ids where a name belongs,
 * and nothing at all about the records a member keeps for themselves.
 */
final class ChangelogContentTest extends RequirementsDatabaseTestCase
{
    private const TABLES = array(
        'req_providers', 'req_activities', 'req_requirement_types',
        'req_requirement_role_rules', 'req_activity_credits', 'req_activity_files'
    );

    /** @var array<string,mixed> */
    private array $previousSettings = array();

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static $registered = false;
        if (!$registered) {
            $registered = true;
            require dirname(__DIR__, 2) . '/plugin.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The suite installs with the changelog off; this test wants the trail, for these tables only.
        Entity::setLoggingEnabled(true);
        $settings = $GLOBALS['gSettingsManager'];
        foreach (self::TABLES as $table) {
            $this->previousSettings[$table] = $settings->has('changelog_table_' . $table)
                ? $settings->getBool('changelog_table_' . $table) : null;
            $settings->set('changelog_table_' . $table, 1);
        }
    }

    protected function tearDown(): void
    {
        $settings = $GLOBALS['gSettingsManager'];
        foreach ($this->previousSettings as $table => $previous) {
            $settings->set('changelog_table_' . $table, $previous ? 1 : 0);
        }
        Entity::setLoggingEnabled(false);

        parent::tearDown();
    }

    private function lastLogId(): int
    {
        return (int) $this->getDatabase()->queryPrepared('SELECT COALESCE(MAX(log_id), 0) FROM ' . TBL_LOG_CHANGES)->fetchColumn();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function entriesSince(int $logId, string $table): array
    {
        return $this->getDatabase()->queryPrepared(
            'SELECT log_table, log_record_id, log_record_name, log_record_uuid, log_record_linkid,
                    log_related_id, log_related_name, log_field, log_value_old, log_value_new, log_action
               FROM ' . TBL_LOG_CHANGES . ' WHERE log_id > ? AND log_table = ? ORDER BY log_id',
            array($logId, $table)
        )->fetchAll();
    }

    private function member(string $login): array
    {
        return $this->createMember($login);
    }

    private function saveCredit(Activity $activity, RequirementType $type, float $claimed, ?float $accepted = null): void
    {
        (new ActivityService($this->getDatabase()))->saveCredit(
            new ActivityCredit($this->getDatabase(), $this->creditId($activity, $type)),
            $activity,
            $type,
            '2026-03-01',
            $claimed,
            $accepted
        );
    }

    private function creditId(Activity $activity, RequirementType $type): int
    {
        return (int) $this->getDatabase()->queryPrepared(
            'SELECT rac_id FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_activity_id = ? AND rac_requirement_type_id = ?',
            array((int) $activity->getValue('rqa_id'), (int) $type->getValue('rqt_id'))
        )->fetchColumn();
    }

    public function testSavingACreditAgainWithTheSameAmountsLogsNothing(): void
    {
        $member = $this->member('req_log_noop');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);

        $before = $this->lastLogId();
        $this->saveCredit($activity, $type, 6.0);
        $this->saveCredit($activity, $type, 6.0, null);

        $this->assertSame(array(), $this->entriesSince($before, 'req_activity_credits'), 'Nothing changed, so nothing is logged.');
    }

    public function testAnUnsetAcceptedAmountAndAnAcceptedZeroAreNotTheSame(): void
    {
        $member = $this->member('req_log_zero');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);

        $before = $this->lastLogId();
        $this->saveCredit($activity, $type, 6.0, 0.0);

        $entries = $this->entriesSince($before, 'req_activity_credits');
        $this->assertCount(1, $entries, 'A rejection is a change.');
        $this->assertSame('rac_credits_accepted', $entries[0]['log_field']);
    }

    public function testACreditEntryIsNamedAfterItsActivityAndRelatedToItsRequirement(): void
    {
        $member = $this->member('req_log_credit');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);

        $before = $this->lastLogId();
        $this->saveCredit($activity, $type, 7.0);

        $entries = $this->entriesSince($before, 'req_activity_credits');
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('rac_credits_claimed', $entry['log_field']);
        $this->assertSame((string) $activity->getValue('rqa_title'), $entry['log_record_name']);
        $this->assertSame((string) $activity->getValue('rqa_uuid'), $entry['log_record_uuid'], 'The entry points at the activity.');
        $this->assertSame((int) $activity->getValue('rqa_id'), (int) $entry['log_record_linkid']);
        $this->assertSame((string) $type->getValue('rqt_uuid'), $entry['log_related_id']);
        $this->assertStringContainsString((string) $type->getValue('rqt_name'), (string) $entry['log_related_name']);
        $this->assertStringContainsString('2026', (string) $entry['log_related_name'], 'The credited period tells credits of one requirement apart.');
    }

    public function testTheIdsThatSayWhatARecordBelongsToAreNotFields(): void
    {
        $member = $this->member('req_log_ids');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $before = $this->lastLogId();
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);

        $fields = array_column($this->entriesSince($before, 'req_activity_credits'), 'log_field');
        $this->assertNotContains('rac_activity_id', $fields);
        $this->assertNotContains('rac_requirement_type_id', $fields);
        $this->assertContains('rac_credits_claimed', $fields);

        $fields = array_column($this->entriesSince($before, 'req_activities'), 'log_field');
        foreach (array('rqa_org_id', 'rqa_user_id', 'rqa_provider_name', 'rqa_provider_short_name', 'rqa_source_key') as $hidden) {
            $this->assertNotContains($hidden, $fields);
        }

        $related = array_values(array_filter(
            $this->entriesSince($before, 'req_activities'),
            static fn(array $e): bool => $e['log_related_name'] !== null && $e['log_related_name'] !== ''
        ));
        $this->assertNotSame(array(), $related, 'The member is the related object of an activity.');
        $this->assertSame((string) $activity->getValue('rqa_user_id'), (string) $member['usr_id']);
    }

    public function testARuleEntryIsNamedAfterItsRequirementAndRelatedToItsRole(): void
    {
        global $gDb;

        $member = $this->member('req_log_rule');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $rule = new RequirementRoleRule($gDb);
        $rule->readDataById((int) $gDb->queryPrepared(
            'SELECT rrr_id FROM ' . RequirementsTables::requirementRoleRules() . ' WHERE rrr_requirement_type_id = ?',
            array((int) $type->getValue('rqt_id'))
        )->fetchColumn());

        $before = $this->lastLogId();
        (new RequirementTypeService($gDb))->saveRule($rule, $type, array('rrr_required_credits' => 12.0));

        $entries = $this->entriesSince($before, 'req_requirement_role_rules');
        $this->assertCount(1, $entries);
        $this->assertSame('rrr_required_credits', $entries[0]['log_field']);
        $this->assertSame((string) $type->getValue('rqt_name'), $entries[0]['log_record_name']);
        $this->assertSame((string) $rule->getValue('rrr_uuid'), $entries[0]['log_record_uuid']);
        $this->assertNotSame('', (string) $entries[0]['log_related_id'], 'The role is the related object.');
        $this->assertNotSame('', (string) $entries[0]['log_related_name']);
        $this->assertSame('roles', ChangelogService::getRelatedTable('req_requirement_role_rules'));
    }

    public function testNoIdColumnOfARuleIsALoggedField(): void
    {
        $member = $this->member('req_log_ruleids');
        $before = $this->lastLogId();
        $this->createRequirement('CPD', $member['rol_id'], 10.0);

        $fields = array_column($this->entriesSince($before, 'req_requirement_role_rules'), 'log_field');
        $this->assertNotContains('rrr_requirement_type_id', $fields);
        $this->assertNotContains('rrr_role_id', $fields);
        $this->assertContains('rrr_required_credits', $fields);
    }

    public function testEveryLoggedColumnOfEveryTableHasAReadableNameOrIsLeftOut(): void
    {
        global $gDb;

        $translations = ChangelogService::getFieldTranslations();
        $entities = array(
            new \AdmidioPlugin\Requirements\Entity\Provider($gDb),
            new Activity($gDb),
            new RequirementType($gDb),
            new RequirementRoleRule($gDb),
            new ActivityCredit($gDb),
            new \AdmidioPlugin\Requirements\Entity\ActivityFile($gDb)
        );
        foreach ($entities as $entity) {
            $columns = (new \ReflectionClass($entity))->getParentClass()->getProperty('dbColumns');
            $columns->setAccessible(true);
            foreach (array_keys($columns->getValue($entity)) as $column) {
                if (in_array($column, $entity->getIgnoredLogColumns(), true) || str_ends_with($column, '_id') && $column === $entity->getKeyColumnName()) {
                    continue;
                }
                $this->assertArrayHasKey($column, $translations, $column . ' would be shown by its database name.');
            }
        }
    }

    public function testAParentIsShownByItsNameWithALink(): void
    {
        $member = $this->member('req_log_parent');
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);

        $html = ChangelogService::formatValue((string) $parent->getValue('rqt_id'), 'REQUIREMENTS_TYPE');

        $this->assertStringContainsString((string) $parent->getValue('rqt_name'), $html);
        $this->assertStringContainsString('<a href="', $html);
        $this->assertStringContainsString((string) $parent->getValue('rqt_uuid'), $html);
        $this->assertSame('99', ChangelogService::formatValue('99', 'REQUIREMENTS_TYPE'), 'A parent that is gone stays the plain id.');
    }

    public function testCreditsAreShownWithoutTrailingZeros(): void
    {
        $this->assertSame('6', ChangelogService::formatValue('6.00', 'REQUIREMENTS_CREDITS'));
        $this->assertSame('7.5', ChangelogService::formatValue('7.50', 'REQUIREMENTS_CREDITS'));
    }

    public function testAMembersOwnRequirementLeavesNoTrace(): void
    {
        global $gDb;

        $member = $this->member('req_log_own');
        $before = $this->lastLogId();

        $type = $this->asMember($member['usr_id'], function () use ($gDb): RequirementType {
            $type = new RequirementType($gDb);
            (new RequirementTypeService($gDb))->saveOwnType($type, array(
                'rqt_name' => 'Private goal', 'rqt_short_name' => 'PRIV1',
                'rqt_period_unit' => 'year', 'rqt_period_anchor' => 'calendar',
                'rrr_required_credits' => 5.0, 'rrr_partial_period_mode' => 'pro_rata',
                'rrr_proration_basis' => 'month', 'rrr_valid_from' => '2020-01-01'
            ));
            return $type;
        });
        $activity = $this->asMember($member['usr_id'], fn(): Activity =>
            $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 3.0));

        foreach (array('req_requirement_types', 'req_requirement_role_rules') as $table) {
            $this->assertSame(array(), $this->entriesSince($before, $table), $table);
        }
        $this->assertSame(array(), array_filter(
            $this->entriesSince($before, 'req_activity_credits'),
            static fn(array $e): bool => true
        ), 'The credit towards a private requirement would name it.');
        $this->assertNotSame(array(), $this->entriesSince($before, 'req_activities'), 'The activity itself is logged.');
        $this->assertGreaterThan(0, (int) $activity->getValue('rqa_id'));
    }

    public function testDeletingAnActivityLogsTheCreditsItCarried(): void
    {
        $member = $this->member('req_log_delete');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);

        $before = $this->lastLogId();
        (new ActivityService($this->getDatabase()))->delete($activity);

        $credits = $this->entriesSince($before, 'req_activity_credits');
        $this->assertCount(1, $credits);
        $this->assertSame('DELETED', $credits[0]['log_action']);
        $this->assertCount(1, $this->entriesSince($before, 'req_activities'));
    }

    public function testDeletingARequirementLogsItsRules(): void
    {
        global $gDb;

        $member = $this->member('req_log_deltype');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);

        $before = $this->lastLogId();
        (new RequirementTypeService($gDb))->deleteType($type);

        $rules = $this->entriesSince($before, 'req_requirement_role_rules');
        $this->assertCount(1, $rules);
        $this->assertSame('DELETED', $rules[0]['log_action']);
    }
}
