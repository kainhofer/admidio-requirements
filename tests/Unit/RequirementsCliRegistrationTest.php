<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use Admidio\Infrastructure\Cli\CliTaskRegistry;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * The CLI contract of concept §13: which commands exist, what each of them may be run by, and that
 * a plugin keeps its lowercase component id (REQ-DEC-009).
 *
 * The access level is the whole authorization story for a command, so it is asserted per command
 * rather than in bulk: a mutation that silently became `visible` would be a security bug that
 * nothing else in the suite would notice.
 */
final class RequirementsCliRegistrationTest extends RequirementsUnitTestCase
{
    /** Commands every member may run, listed with the reason they are readable. */
    private const VISIBLE = array(
        'requirements:provider-list',
        'requirements:requirement-list',
        'requirements:rule-list',
        'requirements:activity-list',
        // submitting one's own draft is what a normal member does; submit() does the record check
        'requirements:activity-submit',
        'requirements:report'
    );

    /** Commands that change data, and therefore require administering the component. */
    private const ADMINISTRABLE = array(
        'requirements:provider-add',
        'requirements:provider-update',
        'requirements:provider-delete',
        'requirements:requirement-add',
        'requirements:requirement-update',
        'requirements:requirement-delete',
        'requirements:rule-add',
        'requirements:rule-update',
        'requirements:rule-delete',
        'requirements:activity-add',
        'requirements:activity-update',
        'requirements:activity-delete',
        'requirements:credit-set',
        'requirements:credit-delete'
    );

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The registry is static and has no reset, so fill it once. Registering under plugin
        // context is what the native CLI does when it loads plugins/<id>/cli.php.
        if (CliTaskRegistry::get('requirements:provider-list') === null) {
            CliTaskRegistry::setPluginContext(RequirementsPlugin::ID);
            try {
                require dirname(__DIR__, 2) . '/cli.php';
            } finally {
                CliTaskRegistry::setPluginContext(null);
            }
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function everyCommand(): array
    {
        $cases = array();
        foreach (array_merge(self::VISIBLE, self::ADMINISTRABLE) as $name) {
            $cases[$name] = array($name);
        }
        return $cases;
    }

    /**
     * @dataProvider everyCommand
     */
    public function testEveryCommandIsRegisteredAndRunnable(string $name): void
    {
        $task = CliTaskRegistry::get($name);

        $this->assertNotNull($task, $name . ' should be registered.');
        $this->assertTrue(is_callable($task['callback']), $name . ' should name a callable.');
        $this->assertNotSame('', trim((string) $task['description']), $name . ' should describe itself.');
        $this->assertStringStartsWith($name, (string) $task['usage'], $name . ' should show its usage.');
    }

    /**
     * REQ-DEC-009. A core component id is uppercased on registration; a plugin's is its registry id,
     * which is lowercase - uppercasing it would make Component::isAdministrable() answer about a
     * component that does not exist, and every command would be refused.
     *
     * @dataProvider everyCommand
     */
    public function testEveryCommandKeepsTheLowercasePluginComponentId(string $name): void
    {
        $task = CliTaskRegistry::get($name);

        $this->assertSame('requirements', $task['component']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function visibleCommands(): array
    {
        return array_combine(self::VISIBLE, array_map(static fn(string $n): array => array($n), self::VISIBLE));
    }

    /**
     * @dataProvider visibleCommands
     */
    public function testReadCommandsOnlyRequireVisibility(string $name): void
    {
        $this->assertSame(CliTaskRegistry::ACCESS_VISIBLE, CliTaskRegistry::get($name)['componentAccess']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function administrableCommands(): array
    {
        return array_combine(
            self::ADMINISTRABLE,
            array_map(static fn(string $n): array => array($n), self::ADMINISTRABLE)
        );
    }

    /**
     * @dataProvider administrableCommands
     */
    public function testMutatingCommandsRequireAdministeringTheComponent(string $name): void
    {
        $this->assertSame(CliTaskRegistry::ACCESS_ADMINISTRABLE, CliTaskRegistry::get($name)['componentAccess']);
    }

    /**
     * @dataProvider administrableCommands
     */
    public function testEveryMutationCanBeRehearsed(string $name): void
    {
        $this->assertTrue(
            (bool) CliTaskRegistry::get($name)['supportsDryRun'],
            $name . ' changes data and should support --dry-run.'
        );
    }

    public function testSubmitIsTheOneVisibleCommandThatWrites(): void
    {
        $writing = array();
        foreach (self::VISIBLE as $name) {
            if ((bool) CliTaskRegistry::get($name)['supportsDryRun']) {
                $writing[] = $name;
            }
        }

        $this->assertSame(array('requirements:activity-submit'), $writing);
    }

    /**
     * Nothing beyond the documented set, so a command added without a decision shows up here.
     */
    public function testTheRegisteredSetIsExactlyTheDocumentedOne(): void
    {
        $registered = array();
        foreach (array_keys(CliTaskRegistry::getAll()) as $name) {
            if (str_starts_with($name, 'requirements:')) {
                $registered[] = $name;
            }
        }
        sort($registered);

        $expected = array_merge(self::VISIBLE, self::ADMINISTRABLE);
        sort($expected);

        $this->assertSame($expected, $registered);
    }

    /**
     * Every delete is destructive and asks before it acts, which the callbacks do through
     * CliApplication::confirm(). The registration therefore has to accept --yes.
     */
    public function testDeleteCommandsAreRegisteredWithAConfirmation(): void
    {
        foreach (array('provider-delete', 'requirement-delete', 'rule-delete', 'activity-delete', 'credit-delete') as $verb) {
            $task = CliTaskRegistry::get('requirements:' . $verb);
            $this->assertStringContainsString('--yes', (string) $task['usage'], $verb . ' should document --yes.');
        }
    }
}
