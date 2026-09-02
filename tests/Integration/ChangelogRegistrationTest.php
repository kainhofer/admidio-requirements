<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Changelog\Service\ChangelogService;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * What `plugin.php` registers with the changelog (§11), and who it answers for.
 *
 * The two findings this pins are 2.3 - the permission has to be the requirements right rather than
 * the generic administrator flag - and 2.4, where an object handed back for a changelog row must not
 * be able to be a record of another organisation.
 */
final class ChangelogRegistrationTest extends RequirementsDatabaseTestCase
{
    private const TABLES = array(
        'req_providers',
        'req_activities',
        'req_requirement_types',
        'req_requirement_role_rules',
        'req_activity_credits'
    );

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // plugin.php only registers; requiring it twice would stack the callbacks.
        static $registered = false;
        if (!$registered) {
            $registered = true;
            require dirname(__DIR__, 2) . '/plugin.php';
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function requirementTables(): array
    {
        return array_combine(
            self::TABLES,
            array_map(static fn(string $t): array => array($t), self::TABLES)
        );
    }

    /**
     * @dataProvider requirementTables
     */
    public function testEveryTableHasALabelAndAnObject(string $table): void
    {
        $this->assertNotSame('', ChangelogService::getTableLabel($table), $table . ' should be named.');
        $this->assertNotNull(ChangelogService::getObjectForTable($table), $table . ' should have an entity.');
    }

    public function testAnAdministratorMayReadEveryRequirementTable(): void
    {
        global $gCurrentUser;

        $permitted = ChangelogService::getPermittedTables($gCurrentUser);

        foreach (self::TABLES as $table) {
            $this->assertContains($table, $permitted, $table . ' should be readable by an administrator.');
        }
    }

    /**
     * Finding 2.3: the answer follows the requirements right. A member who does not administer the
     * component gets nothing, whatever else they may administer.
     */
    public function testANormalMemberMayReadNoRequirementTable(): void
    {
        $member = $this->createMember('req_changelog_member');

        $permitted = $this->asMember($member['usr_id'], function (): array {
            global $gCurrentUser;
            return ChangelogService::getPermittedTables($gCurrentUser);
        });

        foreach (self::TABLES as $table) {
            $this->assertNotContains($table, $permitted);
        }
    }

    /**
     * The callback speaks for the current user, so being asked about somebody else has to answer
     * nothing rather than the current user's own rights.
     */
    public function testTheCallbackDoesNotAnswerForAnotherUser(): void
    {
        global $gProfileFields;

        $member = $this->createMember('req_changelog_other');
        $other = new User($this->getDatabase(), $gProfileFields, $member['usr_id']);

        $permitted = ChangelogService::getPermittedTables($other);

        foreach (self::TABLES as $table) {
            $this->assertNotContains($table, $permitted);
        }
    }

    /**
     * Finding 2.4: the changelog reads the record by id after the callback has handed back an empty
     * entity, so the organisation check has to be in the entity - which is what this proves.
     */
    public function testAnObjectFromTheCallbackCannotLoadAForeignRecord(): void
    {
        $db = $this->getDatabase();
        $otherOrgId = (int) $this->fixture->createAndSaveOrganization('Changelog Org', 'CHLOG')['org_id'];

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::providers()
            . ' (rqp_uuid, rqp_org_id, rqp_name) VALUES (?, ?, ?)',
            array('cccccccc-0000-0000-0000-000000000001', $otherOrgId, 'Foreign in the log')
        );
        $foreignId = (int) $db->queryPrepared(
            'SELECT rqp_id FROM ' . RequirementsTables::providers() . ' WHERE rqp_uuid = ?',
            array('cccccccc-0000-0000-0000-000000000001')
        )->fetchColumn();

        $object = ChangelogService::getObjectForTable('req_providers');
        $this->assertInstanceOf(Provider::class, $object);

        $object->readDataById($foreignId);
        $this->assertSame(0, (int) $object->getValue('rqp_id'));
        $this->assertSame('', (string) $object->getValue('rqp_name'), 'No name of another org may leak.');

        $object->readDataByUuid('cccccccc-0000-0000-0000-000000000001');
        $this->assertSame(0, (int) $object->getValue('rqp_id'));
    }

    /**
     * A record of the current organisation is still readable, so the scope did not simply break the
     * changelog for everybody.
     */
    public function testAnObjectFromTheCallbackLoadsAnOwnRecord(): void
    {
        global $gCurrentOrgId, $gCurrentUserId;

        $db = $this->getDatabase();
        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activities()
            . ' (rqa_uuid, rqa_org_id, rqa_user_id, rqa_title, rqa_begin_date) VALUES (?, ?, ?, ?, ?)',
            array(
                'cccccccc-0000-0000-0000-000000000002', (int) $gCurrentOrgId, (int) $gCurrentUserId,
                'Our own course', '2026-03-01'
            )
        );

        $object = ChangelogService::getObjectForTable('req_activities');
        $this->assertInstanceOf(Activity::class, $object);

        $object->readDataByUuid('cccccccc-0000-0000-0000-000000000002');

        $this->assertGreaterThan(0, (int) $object->getValue('rqa_id'));
        $this->assertSame('Our own course', (string) $object->getValue('rqa_title'));
    }

    /**
     * A changelog row has to lead back to the record it is about, otherwise "who changed what" ends
     * in plain text an administrator cannot follow.
     */
    public function testEveryTableLinksBackToItsRecord(): void
    {
        $expected = array(
            'req_providers' => 'providers.php',
            'req_activities' => 'index.php',
            'req_requirement_types' => 'rules.php',
            'req_requirement_role_rules' => 'rules.php'
        );

        foreach ($expected as $table => $page) {
            $link = ChangelogService::createLink('Name', $table, 1, 'eeeeeeee-0000-0000-0000-000000000001');

            $this->assertStringContainsString('<a href="', $link, $table . ' should be rendered as a link.');
            $this->assertStringContainsString($page, $link, $table . ' should link to ' . $page);
            $this->assertStringContainsString('eeeeeeee-0000-0000-0000-000000000001', $link, $table);
        }
    }

    /**
     * A credit has no page of its own; it is edited on its activity, so that is where it links.
     */
    public function testACreditLinksToTheActivityItBelongsTo(): void
    {
        global $gCurrentOrgId, $gCurrentUserId;

        $db = $this->getDatabase();
        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activities()
            . ' (rqa_uuid, rqa_org_id, rqa_user_id, rqa_title, rqa_begin_date) VALUES (?, ?, ?, ?, ?)',
            array(
                'eeeeeeee-0000-0000-0000-000000000002', (int) $gCurrentOrgId, (int) $gCurrentUserId,
                'Linked course', '2026-03-01'
            )
        );
        $activityId = (int) $db->queryPrepared(
            'SELECT rqa_id FROM ' . RequirementsTables::activities() . ' WHERE rqa_uuid = ?',
            array('eeeeeeee-0000-0000-0000-000000000002')
        )->fetchColumn();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementTypes()
            . ' (rqt_uuid, rqt_org_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?)',
            array('eeeeeeee-0000-0000-0000-000000000003', (int) $gCurrentOrgId, 'Linked CPD', 'year')
        );
        $typeId = (int) $db->queryPrepared(
            'SELECT rqt_id FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_uuid = ?',
            array('eeeeeeee-0000-0000-0000-000000000003')
        )->fetchColumn();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activityCredits()
            . ' (rac_uuid, rac_activity_id, rac_requirement_type_id, rac_period_reference, rac_credits_claimed)'
            . ' VALUES (?, ?, ?, ?, ?)',
            array('eeeeeeee-0000-0000-0000-000000000004', $activityId, $typeId, '2026-01-01', '5.00')
        );
        $creditId = (int) $db->queryPrepared(
            'SELECT rac_id FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_uuid = ?',
            array('eeeeeeee-0000-0000-0000-000000000004')
        )->fetchColumn();

        $link = ChangelogService::createLink(
            'Credit',
            'req_activity_credits',
            $creditId,
            'eeeeeeee-0000-0000-0000-000000000004'
        );

        $this->assertStringContainsString('<a href="', $link);
        $this->assertStringContainsString('index.php', $link);
        $this->assertStringContainsString('eeeeeeee-0000-0000-0000-000000000002', $link, 'the activity UUID');
    }

    /**
     * A credit whose activity is gone - or belongs to another organisation, which reads the same
     * way - has nowhere to point. `createLink()` renders the plain name in that case rather than an
     * anchor, which is what a callback returning an empty URL is for.
     */
    public function testACreditWithNoReachableActivityIsNotLinked(): void
    {
        $rendered = ChangelogService::createLink(
            'Credit',
            'req_activity_credits',
            999999,
            'eeeeeeee-dead-0000-0000-000000000000'
        );

        $this->assertSame('Credit', $rendered);
        $this->assertStringNotContainsString('<a ', $rendered);
    }

    /**
     * §11: an entity has to carry a stable hook id, which is what an after-commit hook subscribes to.
     */
    public function testEveryEntityCarriesItsHookId(): void
    {
        $expected = array(
            'req_providers' => 'requirements_provider',
            'req_activities' => 'requirements_activity',
            'req_requirement_types' => 'requirements_requirement_type',
            'req_requirement_role_rules' => 'requirements_requirement_role_rule',
            'req_activity_credits' => 'requirements_activity_credit'
        );

        foreach ($expected as $table => $hookId) {
            $this->assertSame($hookId, ChangelogService::getObjectForTable($table)->getHookId(), $table);
        }
    }
}
