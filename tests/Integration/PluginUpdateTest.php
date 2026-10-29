<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Components\Entity\ComponentUpdate;
use Admidio\Infrastructure\Database;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The update scripts take an installation of the last release to the schema install.sql creates.
 *
 * The schemas of earlier releases are rebuilt from the current one by undoing what the later ones
 * added, and then the core runs the plugin update the way an administrator's click does.
 */
final class PluginUpdateTest extends RequirementsDatabaseTestCase
{
    public function testAnInstallationOfVersion03IsUpdatedToTheCurrentSchema(): void
    {
        $this->outsideTheTransaction(function (Database $db): void {
            $this->downgradeTo04($db);
            $this->downgradeTo03($db);
            $legacyType = $this->insertLegacyRequirement($db);

            $this->runPluginUpdate($db, '0.3.0');
            $this->assertSchemaIsCurrent($db);
            $this->assertNull(
                $db->queryPrepared(
                    'SELECT rqt_usr_id FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_id = ?',
                    array($legacyType)
                )->fetchColumn(),
                'An existing requirement stays the organization\'s.'
            );

            // A fresh installation without its update steps recorded - what Admidio versions before
            // the fix of PluginInstaller::install() leave - runs the file of its version again.
            $this->runPluginUpdate($db, '0.5.0');
            $this->assertSchemaIsCurrent($db);
        });
    }

    /**
     * 0.4 tied the rule of a member's own requirement to the owner of its type; 0.5 names the
     * member on the rule itself, so the update has to carry the owner over.
     */
    public function testTheRulesOfOwnRequirementsOf04TakeTheirOwner(): void
    {
        global $gCurrentOrgId, $gCurrentUser;

        $this->outsideTheTransaction(function (Database $db) use ($gCurrentOrgId, $gCurrentUser): void {
            $this->downgradeTo04($db);
            $owner = (int) $gCurrentUser->getValue('usr_id');
            $db->queryPrepared(
                'INSERT INTO ' . RequirementsTables::requirementTypes()
                . ' (rqt_uuid, rqt_org_id, rqt_usr_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?, ?)',
                array('00000000-0000-4000-8000-000000000406', (int) $gCurrentOrgId, $owner, 'Own of 0.4', 'year')
            );
            $typeId = (int) $db->lastInsertId();
            $db->queryPrepared(
                'INSERT INTO ' . RequirementsTables::requirementRoleRules()
                . ' (rrr_uuid, rrr_requirement_type_id, rrr_role_id, rrr_required_credits) VALUES (?, ?, NULL, ?)',
                array('00000000-0000-4000-8000-000000000407', $typeId, 5.0)
            );

            $this->runPluginUpdate($db, '0.4.0');

            $this->assertSame($owner, (int) $db->queryPrepared(
                'SELECT rrr_usr_id FROM ' . RequirementsTables::requirementRoleRules() . ' WHERE rrr_requirement_type_id = ?',
                array($typeId)
            )->fetchColumn());
        });
    }

    /**
     * Schema statements end any open transaction, so the test runs outside the test transaction and
     * builds the schema anew from install.sql afterwards, whatever happened.
     */
    private function outsideTheTransaction(callable $test): void
    {
        $db = $this->getDatabase();
        $db->endTransaction();

        try {
            $test($db);
        } finally {
            $db->queryPrepared(
                'DELETE FROM ' . TBL_COMPONENTS . ' WHERE com_type = ? AND com_name_intern = ?',
                array('PLUGIN', RequirementsPlugin::ID),
                false
            );
            self::runSchemaScript($db, dirname(__DIR__, 2) . '/db_scripts/uninstall.sql');
            self::runSchemaScript($db, dirname(__DIR__, 2) . '/db_scripts/install.sql');
            $db->startTransaction();
        }
    }

    private function downgradeTo04(Database $db): void
    {
        $rules = RequirementsTables::requirementRoleRules();
        $prefix = TABLE_PREFIX;
        $mysql = $db->getEngine() === Database::PDO_ENGINE_MYSQL;

        foreach (array(
            'ALTER TABLE ' . $rules . ($mysql ? ' DROP FOREIGN KEY ' : ' DROP CONSTRAINT ') . $prefix . '_fk_rrr_usr',
            $mysql ? 'DROP INDEX ' . $prefix . '_idx_rrr_usr ON ' . $rules : 'DROP INDEX ' . $prefix . '_idx_rrr_usr',
            'ALTER TABLE ' . $rules . ' DROP COLUMN rrr_usr_id'
        ) as $sql) {
            $db->queryPrepared($sql);
        }
        $db->initializeTableColumnProperties();
    }

    private function downgradeTo03(Database $db): void
    {
        $types = RequirementsTables::requirementTypes();
        $rules = RequirementsTables::requirementRoleRules();
        $prefix = TABLE_PREFIX;
        $mysql = $db->getEngine() === Database::PDO_ENGINE_MYSQL;

        foreach (array(
            'ALTER TABLE ' . $types . ($mysql ? ' DROP FOREIGN KEY ' : ' DROP CONSTRAINT ') . $prefix . '_fk_rqt_usr',
            $mysql ? 'DROP INDEX ' . $prefix . '_idx_rqt_usr ON ' . $types : 'DROP INDEX ' . $prefix . '_idx_rqt_usr',
            'ALTER TABLE ' . $types . ' DROP COLUMN rqt_usr_id',
            $mysql
                ? 'ALTER TABLE ' . $rules . ' MODIFY COLUMN rrr_role_id integer unsigned NOT NULL'
                : 'ALTER TABLE ' . $rules . ' ALTER COLUMN rrr_role_id SET NOT NULL'
        ) as $sql) {
            $db->queryPrepared($sql);
        }
        $db->initializeTableColumnProperties();
    }

    private function insertLegacyRequirement(Database $db): int
    {
        global $gCurrentOrgId;

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementTypes()
            . ' (rqt_uuid, rqt_org_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?)',
            array('00000000-0000-4000-8000-000000000403', (int) $gCurrentOrgId, 'Legacy', 'year')
        );

        return (int) $db->lastInsertId();
    }

    private function runPluginUpdate(Database $db, string $installedVersion): void
    {
        $db->queryPrepared(
            'DELETE FROM ' . TBL_COMPONENTS . ' WHERE com_type = ? AND com_name_intern = ?',
            array('PLUGIN', RequirementsPlugin::ID)
        );

        $component = new ComponentUpdate($db);
        $component->setValue('com_type', 'PLUGIN');
        $component->setValue('com_name', 'PLG_REQUIREMENTS_NAME');
        $component->setValue('com_name_intern', RequirementsPlugin::ID);
        $component->setValue('com_version', $installedVersion);
        $component->setValue('com_update_step', 0);
        $component->save();

        $component->updatePlugin('0.5.0', '');
        $db->initializeTableColumnProperties();
    }

    private function assertSchemaIsCurrent(Database $db): void
    {
        global $gCurrentOrgId, $gCurrentUser;

        // The owner column exists and takes a member, and a rule takes a member instead of a role.
        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementTypes()
            . ' (rqt_uuid, rqt_org_id, rqt_usr_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?, ?)',
            array('00000000-0000-4000-8000-000000000404', (int) $gCurrentOrgId,
                (int) $gCurrentUser->getValue('usr_id'), 'Own', 'year')
        );
        $typeId = (int) $db->lastInsertId();
        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementRoleRules()
            . ' (rrr_uuid, rrr_requirement_type_id, rrr_role_id, rrr_usr_id, rrr_required_credits) VALUES (?, ?, NULL, ?, ?)',
            array('00000000-0000-4000-8000-000000000405', $typeId, (int) $gCurrentUser->getValue('usr_id'), 1.0)
        );

        // The owner is a real user: the foreign key refuses anybody else.
        $this->assertFalse($db->queryPrepared(
            'UPDATE ' . RequirementsTables::requirementTypes() . ' SET rqt_usr_id = ? WHERE rqt_id = ?',
            array(999999999, $typeId),
            false
        ));

        $db->queryPrepared('DELETE FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_id = ?', array($typeId));
    }
}
