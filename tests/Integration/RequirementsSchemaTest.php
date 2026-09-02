<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * Concept §10 and §15: the shipped schema creates the five tables and their relations, and the
 * uninstall script takes them away again in an order the database accepts.
 *
 * The round trip runs against the real `db_scripts/*.sql`, because a schema test that builds its
 * own tables proves nothing about the ones an installation gets.
 */
final class RequirementsSchemaTest extends RequirementsDatabaseTestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function requirementTables(): array
    {
        return array(
            'providers' => array('_req_providers'),
            'activities' => array('_req_activities'),
            'requirement types' => array('_req_requirement_types'),
            'role rules' => array('_req_requirement_role_rules'),
            'activity credits' => array('_req_activity_credits')
        );
    }

    /**
     * @dataProvider requirementTables
     */
    public function testInstallCreatesEveryTable(string $suffix): void
    {
        $this->assertTrue(self::tableExists($this->getDatabase(), TABLE_PREFIX . $suffix));
    }

    /**
     * The uninstall script has to drop child tables before their parents: MySQL ignores the CASCADE
     * keyword on DROP TABLE, so only the order keeps it working there (finding 3.9).
     */
    public function testUninstallAndReinstallRoundTrip(): void
    {
        $db = $this->getDatabase();

        // DDL commits implicitly, so the per-test transaction cannot protect this one. The tables
        // are put back before the assertion ends, and the class is the only user of them.
        $db->endTransaction();

        try {
            self::runSchemaScript($db, dirname(__DIR__, 2) . '/db_scripts/uninstall.sql');

            foreach (array_keys(self::requirementTables()) as $label) {
                $suffix = self::requirementTables()[$label][0];
                $this->assertFalse(
                    self::tableExists($db, TABLE_PREFIX . $suffix),
                    TABLE_PREFIX . $suffix . ' should be gone after uninstall.'
                );
            }
        } finally {
            self::runSchemaScript($db, dirname(__DIR__, 2) . '/db_scripts/install.sql');
            $db->startTransaction();
        }

        $this->assertTrue(self::tableExists($db, RequirementsTables::providers()));
    }

    /**
     * §7.5: one activity cannot contribute twice to the same requirement and credited period.
     */
    public function testTheCreditUniqueKeyIsEnforced(): void
    {
        $db = $this->getDatabase();

        $activityId = $this->insertActivity();
        $typeId = $this->insertRequirementType();

        $this->assertNotFalse($db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activityCredits()
            . ' (rac_uuid, rac_activity_id, rac_requirement_type_id, rac_period_reference, rac_credits_claimed)'
            . ' VALUES (?, ?, ?, ?, ?)',
            array('11111111-1111-1111-1111-111111111111', $activityId, $typeId, '2026-01-01', '5.00'),
            false
        ));

        $this->assertFalse(
            $db->queryPrepared(
                'INSERT INTO ' . RequirementsTables::activityCredits()
                . ' (rac_uuid, rac_activity_id, rac_requirement_type_id, rac_period_reference, rac_credits_claimed)'
                . ' VALUES (?, ?, ?, ?, ?)',
                array('22222222-2222-2222-2222-222222222222', $activityId, $typeId, '2026-01-01', '3.00'),
                false
            ),
            'A second credit for the same activity, requirement and period must be refused.'
        );
    }

    /**
     * REQ-DEC-004: deleting a role takes its requirement rules with it, deliberately.
     */
    public function testDeletingARoleCascadesIntoItsRules(): void
    {
        global $gCurrentOrgId;

        $db = $this->getDatabase();
        $role = $this->fixture->createAndSaveRole('Cascade role', (int) $gCurrentOrgId);
        $typeId = $this->insertRequirementType();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementRoleRules()
            . ' (rrr_uuid, rrr_requirement_type_id, rrr_role_id, rrr_required_credits)'
            . ' VALUES (?, ?, ?, ?)',
            array('33333333-3333-3333-3333-333333333333', $typeId, (int) $role['rol_id'], '10.00')
        );
        $this->assertSame(1, $this->countRules($typeId));

        $this->fixture->deleteRole((int) $role['rol_id']);

        $this->assertSame(0, $this->countRules($typeId), 'The rule should have gone with the role.');
    }

    /**
     * §7.5: a requirement type that still has credits cannot be deleted, which is what makes
     * RequirementTypeService::deleteType() check before it tries.
     */
    public function testARequirementTypeWithCreditsCannotBeDeleted(): void
    {
        $db = $this->getDatabase();

        $activityId = $this->insertActivity();
        $typeId = $this->insertRequirementType();
        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activityCredits()
            . ' (rac_uuid, rac_activity_id, rac_requirement_type_id, rac_period_reference, rac_credits_claimed)'
            . ' VALUES (?, ?, ?, ?, ?)',
            array('44444444-4444-4444-4444-444444444444', $activityId, $typeId, '2026-01-01', '5.00')
        );

        $this->assertFalse($db->queryPrepared(
            'DELETE FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_id = ?',
            array($typeId),
            false
        ));
    }

    /**
     * §7.2: deleting a provider leaves the activity in place with its name snapshot.
     */
    public function testDeletingAProviderOnlyClearsTheRelation(): void
    {
        global $gCurrentOrgId, $gCurrentUserId;

        $db = $this->getDatabase();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::providers()
            . ' (rqp_uuid, rqp_org_id, rqp_name, rqp_short_name) VALUES (?, ?, ?, ?)',
            array('55555555-5555-5555-5555-555555555555', (int) $gCurrentOrgId, 'Gone Ltd', 'GONE')
        );
        $providerId = (int) $db->queryPrepared(
            'SELECT rqp_id FROM ' . RequirementsTables::providers() . ' WHERE rqp_uuid = ?',
            array('55555555-5555-5555-5555-555555555555')
        )->fetchColumn();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activities()
            . ' (rqa_uuid, rqa_org_id, rqa_user_id, rqa_provider_id, rqa_provider_name,'
            . ' rqa_provider_short_name, rqa_title, rqa_begin_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            array(
                '66666666-6666-6666-6666-666666666666', (int) $gCurrentOrgId, (int) $gCurrentUserId,
                $providerId, 'Gone Ltd', 'GONE', 'Course', '2026-03-01'
            )
        );

        $db->queryPrepared(
            'DELETE FROM ' . RequirementsTables::providers() . ' WHERE rqp_id = ?',
            array($providerId)
        );

        $row = $db->queryPrepared(
            'SELECT rqa_provider_id, rqa_provider_name FROM ' . RequirementsTables::activities()
            . ' WHERE rqa_uuid = ?',
            array('66666666-6666-6666-6666-666666666666')
        )->fetch();

        $this->assertNotFalse($row, 'The activity must survive its provider.');
        $this->assertNull($row['rqa_provider_id']);
        $this->assertSame('Gone Ltd', $row['rqa_provider_name'], 'The snapshot keeps the history readable.');
    }

    private function insertActivity(): int
    {
        global $gCurrentOrgId, $gCurrentUserId;

        $uuid = '77777777-7777-7777-7777-' . substr(md5((string) mt_rand()), 0, 12);
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activities()
            . ' (rqa_uuid, rqa_org_id, rqa_user_id, rqa_title, rqa_begin_date) VALUES (?, ?, ?, ?, ?)',
            array($uuid, (int) $gCurrentOrgId, (int) $gCurrentUserId, 'Course', '2026-03-01')
        );

        return (int) $this->getDatabase()->queryPrepared(
            'SELECT rqa_id FROM ' . RequirementsTables::activities() . ' WHERE rqa_uuid = ?',
            array($uuid)
        )->fetchColumn();
    }

    private function insertRequirementType(): int
    {
        global $gCurrentOrgId;

        $uuid = '88888888-8888-8888-8888-' . substr(md5((string) mt_rand()), 0, 12);
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementTypes()
            . ' (rqt_uuid, rqt_org_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?)',
            array($uuid, (int) $gCurrentOrgId, 'CPD', 'year')
        );

        return (int) $this->getDatabase()->queryPrepared(
            'SELECT rqt_id FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_uuid = ?',
            array($uuid)
        )->fetchColumn();
    }

    private function countRules(int $typeId): int
    {
        return (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::requirementRoleRules()
            . ' WHERE rrr_requirement_type_id = ?',
            array($typeId)
        )->fetchColumn();
    }
}
