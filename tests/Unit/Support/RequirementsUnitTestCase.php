<?php
namespace AdmidioPlugin\Requirements\Tests\Unit\Support;

use Admidio\Tests\Support\AdmidioTestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 3) . '/src/Service/RequirementTypeService.php';
require_once dirname(__DIR__, 3) . '/src/Service/FulfilmentService.php';

/**
 * Base class for the requirements unit tests.
 *
 * The calculator translates its configuration warnings, so a test that reaches them needs something
 * that answers get(). The stub returns the language key, which is also what a test asserts on - the
 * wording of a warning is not what these tests are about, only which warning was raised.
 *
 * The global is restored afterwards: the whole suite runs in one process, and a global left behind
 * is read by code that runs long after the test that set it.
 */
abstract class RequirementsUnitTestCase extends AdmidioTestCase
{
    /** @var array{0:bool,1:mixed} */
    private array $previousL10n;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Admidio\Infrastructure\Exception logs the message it was constructed with, so every test
        // that asserts on a rejection needs a logger to exist.
        $GLOBALS['gLogger'] = $GLOBALS['gLogger'] ?? new NullLogger();

        self::registerPluginAutoloader();
    }

    /**
     * Make the plugin classes loadable.
     *
     * A unit test runs without the plugin runtime, which is what normally registers the PSR-4 root
     * of plugin.json. Registering the same mapping here keeps the tests to the classes they name
     * instead of a list of require statements that has to be kept in order.
     */
    protected static function registerPluginAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $root = dirname(__DIR__, 3) . '/src/';
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousL10n = array(array_key_exists('gL10n', $GLOBALS), $GLOBALS['gL10n'] ?? null);
        $GLOBALS['gL10n'] = new RequirementsStubLanguage();
    }

    protected function tearDown(): void
    {
        if ($this->previousL10n[0]) {
            $GLOBALS['gL10n'] = $this->previousL10n[1];
        } else {
            unset($GLOBALS['gL10n']);
        }

        parent::tearDown();
    }

    /**
     * One row of req_requirement_role_rules, with the columns the calculator reads.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected static function rule(int $roleId, float $credits, array $overrides = array()): array
    {
        return array_merge(array(
            'rrr_role_id' => $roleId,
            'rrr_required_credits' => $credits,
            'rrr_partial_period_mode' => 'pro_rata',
            'rrr_proration_basis' => 'month',
            'rrr_membership_at_period_start' => false,
            'rrr_valid_from' => null,
            'rrr_valid_until' => null,
            'rol_name' => 'Role ' . $roleId
        ), $overrides);
    }

    /**
     * Membership intervals of one role, in the shape loadMemberships() returns.
     *
     * @param array<int,array{0:string,1:string}> $intervals begin and end date of each interval
     * @return array<int,array{start:string,end:string}>
     */
    protected static function memberships(array $intervals): array
    {
        return array_map(
            static fn(array $interval): array => array('start' => $interval[0], 'end' => $interval[1]),
            $intervals
        );
    }
}

/**
 * Returns the language key instead of a translation, like the plugin unit tests of the core suite.
 */
final class RequirementsStubLanguage
{
    /**
     * @param array<int,mixed> $params
     */
    public function get(string $textId, array $params = array()): string
    {
        return $textId;
    }

    public function getLanguage(): string
    {
        return 'en';
    }

    public function addLanguageFolderPath(string $path): bool
    {
        return true;
    }
}
