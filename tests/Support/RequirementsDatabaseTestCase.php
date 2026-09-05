<?php
namespace AdmidioPlugin\Requirements\Tests\Support;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Tests\Support\AdministratorTestCase;
use Admidio\Tests\Support\AdmidioTestFixture;
use Admidio\Tests\Support\PermissionContext;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

/**
 * Base class for the requirements integration tests.
 *
 * Two things have to be true before any of the services can be exercised, and neither of them is
 * true of a plain Admidio test database:
 *
 * 1. the five plugin tables exist. They are created here from the real `db_scripts/install.sql`, so
 *    the tests run against the schema that ships, not against one written for them. DDL commits
 *    implicitly on MySQL, which is why this happens once per class and outside the per-test
 *    transaction that `DatabaseTestCase` rolls back;
 * 2. the plugin counts as installed and enabled. `Component::isVisible()` reads the access
 *    preference and `PluginRegistry::isInstalled()` reads the component table, so both are supplied
 *    here rather than by installing the plugin for real - installing it is `PluginInstallerTest`'s
 *    subject, not this one's.
 */
abstract class RequirementsDatabaseTestCase extends AdministratorTestCase
{
    use PermissionContext;

    protected AdmidioTestFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::registerPluginAutoloader();
        self::installRequirementsSchema(self::$gDb);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new AdmidioTestFixture($this->getDatabase());

        // The registry reads the component table for this; supplying it keeps the test to the
        // services and off the installer. null puts it back to reading the database.
        PluginRegistry::setInstallations(array(
            RequirementsPlugin::ID => array('comId' => 1, 'version' => '0.3.0')
        ));

        $this->enablePluginForCurrentOrganization();
    }

    protected function tearDown(): void
    {
        PluginRegistry::setInstallations(null);

        parent::tearDown();
    }

    /**
     * Switch the plugin on for the organization of the running test and give it its defaults.
     *
     * The preferences are written inside the test transaction, so they disappear with it.
     */
    protected function enablePluginForCurrentOrganization(array $settings = array()): void
    {
        global $gSettingsManager;

        $defaults = array(
            'plugin_requirements_enabled' => '1',
            RequirementsPlugin::SETTING_ACCESS => '2',
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_ALWAYS,
            RequirementsPlugin::SETTING_PRORATION_BASIS_DEFAULT => 'month',
            RequirementsPlugin::SETTING_OVERVIEW_PREVIOUS_YEARS => '4'
        );

        foreach (array_merge($defaults, $settings) as $name => $value) {
            $gSettingsManager->set($name, $value, true);
        }
    }

    /**
     * Run the shipped install script, unless the tables are already there.
     */
    protected static function installRequirementsSchema(Database $db): void
    {
        if (self::tableExists($db, RequirementsTables::providers())) {
            return;
        }

        self::runSchemaScript($db, dirname(__DIR__, 2) . '/db_scripts/install.sql');
    }

    /**
     * Run one of the plugin schema scripts, statement by statement.
     */
    protected static function runSchemaScript(Database $db, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read ' . $path);
        }

        $sql = str_replace('%PREFIX%', TABLE_PREFIX, $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            // showError() would end the process rather than fail the test, so the failure is
            // turned into an exception that names the statement that could not be run.
            if ($db->queryPrepared($statement, array(), false) === false) {
                throw new \RuntimeException(
                    'Schema statement failed: ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 120)
                );
            }
        }
    }

    protected static function tableExists(Database $db, string $table): bool
    {
        return $db->queryPrepared('SELECT 1 FROM ' . $table . ' WHERE 1 = 0', array(), false) !== false;
    }

    /**
     * Register the plugin PSR-4 root, which nothing else does in a test run.
     */
    protected static function registerPluginAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $root = dirname(__DIR__, 2) . '/src/';
        $prefix = 'AdmidioPlugin' . chr(92) . 'Requirements' . chr(92);

        spl_autoload_register(static function (string $class) use ($root, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = $root . str_replace(chr(92), '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    /**
     * Assert that a callback is refused with a particular message.
     *
     * The integration bootstrap has a real Language, so an Admidio exception carries the translated
     * sentence rather than the key. Comparing against what the same key translates to keeps the
     * assertion about which refusal happened, not about its wording.
     *
     * @param array<int,mixed> $params
     */
    protected function assertRefusedWith(string $languageKey, callable $callback, array $params = array()): void
    {
        global $gL10n;

        try {
            $callback();
        } catch (\Admidio\Infrastructure\Exception $exception) {
            $this->assertSame($gL10n->get($languageKey, $params), $exception->getMessage());
            return;
        }

        $this->fail('Expected the call to be refused with ' . $languageKey . ', but it succeeded.');
    }

    /**
     * Run a callback outside the transaction each test is wrapped in, and clear the plugin tables
     * afterwards.
     *
     * `Database::rollback()` unwinds every open transaction, not just the innermost one, so any code
     * under test that rolls back on purpose - a `--dry-run` command, or a service refusing halfway
     * through a batch - would take the test transaction with it and leave the fixtures gone rather
     * than the write undone. Such a test has to own its own cleanup instead.
     */
    protected function outsideTheTestTransaction(callable $callback): void
    {
        $db = $this->getDatabase();
        $db->endTransaction();

        try {
            $callback();
        } finally {
            foreach (array(
                RequirementsTables::activityCredits(),
                RequirementsTables::requirementRoleRules(),
                RequirementsTables::activities(),
                RequirementsTables::requirementTypes(),
                RequirementsTables::providers()
            ) as $table) {
                $db->queryPrepared('DELETE FROM ' . $table, array(), false);
            }
            $db->startTransaction();
        }
    }

    /**
     * Run a callback as a member who is not an administrator, in the current organization.
     *
     * @return mixed the return value of the callback
     */
    protected function asMember(int $userId, callable $callback)
    {
        global $gCurrentOrgId, $gProfileFields;

        $user = new User($this->getDatabase(), $gProfileFields, $userId);

        return $this->withCurrentUser($user, (int) $gCurrentOrgId, true, $callback);
    }

    /**
     * A requirement type with one role rule: a yearly calendar requirement unless $typeValues says
     * otherwise, which is what most of the tests want and none of them should have to spell out.
     *
     * @param array<string,mixed> $ruleValues
     * @param array<string,mixed> $typeValues
     */
    protected function createRequirement(
        string $name,
        int $roleId,
        float $credits,
        array $ruleValues = array(),
        array $typeValues = array()
    ): RequirementType {
        static $counter = 0;
        ++$counter;

        $service = new RequirementTypeService($this->getDatabase());
        $type = new RequirementType($this->getDatabase());
        $service->saveType($type, array_merge(array(
            'rqt_name' => $name . ' ' . $counter,
            'rqt_short_name' => strtoupper(substr($name, 0, 3)) . $counter,
            'rqt_period_unit' => 'year',
            'rqt_period_anchor' => 'calendar'
        ), $typeValues));

        $service->saveRule(new RequirementRoleRule($this->getDatabase()), $type, array_merge(array(
            'rrr_role_id' => $roleId,
            'rrr_required_credits' => $credits,
            'rrr_partial_period_mode' => 'pro_rata',
            'rrr_proration_basis' => 'month'
        ), $ruleValues));

        return $type;
    }

    protected function createCreditedActivity(
        int $userId,
        RequirementType $type,
        string $beginDate,
        float $claimed,
        ?float $accepted = null,
        ?string $periodReference = null
    ): Activity {
        $service = new ActivityService($this->getDatabase());
        $activity = new Activity($this->getDatabase());
        $service->save($activity, array(
            'rqa_title' => 'Course on ' . $beginDate,
            'rqa_begin_date' => $beginDate
        ), $userId);

        $service->saveCredit(
            new ActivityCredit($this->getDatabase()),
            $activity,
            $type,
            $periodReference ?? $beginDate,
            $claimed,
            $accepted
        );

        return $activity;
    }

    /**
     * @return array{usr_id:int,rol_id:int}
     */
    protected function createMemberJoining(string $login, string $joinDate): array
    {
        global $gCurrentOrgId;

        $user = $this->fixture->createAndSaveUser($login, $login . '@example.local', (int) $gCurrentOrgId);
        $role = $this->fixture->createAndSaveRole('Role ' . $login, (int) $gCurrentOrgId);
        $this->fixture->assignUserToRolePeriod(
            (int) $user['usr_id'],
            (int) $role['rol_id'],
            $joinDate,
            '9999-12-31'
        );

        return array('usr_id' => (int) $user['usr_id'], 'rol_id' => (int) $role['rol_id']);
    }

    /**
     * A member of the current organization, in a role that exists only for that purpose.
     *
     * @return array{usr_id:int,rol_id:int}
     */
    protected function createMember(string $login): array
    {
        global $gCurrentOrgId;

        $user = $this->fixture->createAndSaveUser($login, $login . '@example.local', (int) $gCurrentOrgId);
        $role = $this->fixture->createAndSaveRole('Role ' . $login, (int) $gCurrentOrgId);
        $this->fixture->assignUserToRolePeriod(
            (int) $user['usr_id'],
            (int) $role['rol_id'],
            '1990-01-01',
            '9999-12-31'
        );

        return array('usr_id' => (int) $user['usr_id'], 'rol_id' => (int) $role['rol_id']);
    }
}
