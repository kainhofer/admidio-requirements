<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use AdmidioPlugin\Requirements\Service\ImportService;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;
use InvalidArgumentException;
use ReflectionClass;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * The import's column vocabulary and the mapping it resolves (concept §12, steps 3 and 4).
 *
 * Which header means which field, and which combinations of headers are a mapping at all, is pure
 * policy: it needs no database, and putting it in the integration suite would make each of these
 * cases cost a schema. The service is therefore built without its constructor here - the methods
 * under test touch neither the database nor the current user, and the one that would (`analyse()`)
 * is the integration suite's subject.
 *
 * What this protects is the quiet failure. A header that stops being recognised does not raise
 * anything: the column is simply not mapped, and the import runs to completion having dropped it.
 */
final class ImportMappingTest extends RequirementsUnitTestCase
{
    private ImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = (new ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string,array{0:string,1:?string}>
     */
    public static function headerCases(): array
    {
        return array(
            'a fixed field' => array('title', 'title'),
            'upper case' => array('BEGIN_DATE', 'begin_date'),
            'padded' => array('  provider  ', 'provider'),
            'the name pair half that is not a member field on its own' => array('first_name', 'first_name'),
            'a credit column' => array('credit_CPD', 'credit_cpd'),
            'a period column' => array('period_CPD', 'period_cpd'),
            'a credit column with no requirement' => array('credit_', null),
            'a column of the file that means nothing here' => array('Remarks', null),
            'an empty header' => array('', null)
        );
    }

    /**
     * @dataProvider headerCases
     */
    public function testAHeaderIsRecognisedOrIgnored(string $header, ?string $expected): void
    {
        $this->assertSame($expected, ImportService::canonicalField($header));
    }

    public function testHeadersThatSpellAFieldNameMapThemselves(): void
    {
        $rows = array(
            array('title', 'begin_date', 'credit_CPD', 'Remarks'),
            array('First aid', '2026-03-01', '8', 'ignored')
        );

        $mapping = $this->service->resolveMapping($rows);

        $this->assertSame(array('title' => 0, 'begin_date' => 1, 'credit_cpd' => 2), $mapping);
    }

    public function testAnExplicitAssignmentOverridesWhatTheHeaderSuggested(): void
    {
        $rows = array(
            array('title', 'begin_date', 'Real title'),
            array('wrong', '2026-03-01', 'First aid')
        );

        $mapping = $this->service->resolveMapping($rows, array('title' => 'Real title'));

        $this->assertSame(2, $mapping['title']);
    }

    public function testAColumnMayBeAssignedByItsZeroBasedIndex(): void
    {
        $rows = array(array('First aid', '2026-03-01'));

        $mapping = $this->service->resolveMapping($rows, array('title' => '0', 'begin_date' => '1'), false);

        $this->assertSame(array('title' => 0, 'begin_date' => 1), $mapping);
    }

    public function testAnAssignmentToAColumnTheFileDoesNotHaveIsRefused(): void
    {
        $rows = array(array('title', 'begin_date'), array('First aid', '2026-03-01'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Points" for field "credit_cpd" was not found');

        $this->service->resolveMapping($rows, array('credit_cpd' => 'Points'));
    }

    public function testAnUnknownFieldNameIsRefusedRatherThanIgnored(): void
    {
        $rows = array(array('title', 'begin_date'), array('First aid', '2026-03-01'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown import field "rqa_title"');

        $this->service->resolveMapping($rows, array('rqa_title' => '0'));
    }

    /**
     * @return array<string,array{0:array<int,string>}>
     */
    public static function incompleteMappings(): array
    {
        return array(
            'no title' => array(array('begin_date')),
            'no begin date' => array(array('title'))
        );
    }

    /**
     * @dataProvider incompleteMappings
     * @param array<int,string> $headers
     */
    public function testAnActivityNeedsATitleAndABeginDate(array $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must include a');

        $this->service->resolveMapping(array($headers, array_fill(0, count($headers), 'x')));
    }

    /**
     * §12 never selects an ambiguous user. Two identifier columns is that ambiguity in the mapping
     * rather than in a row, so it is refused before a single row is read.
     */
    public function testOnlyOneMemberIdentifierMayBeMapped(): void
    {
        $rows = array(
            array('title', 'begin_date', 'user_login', 'user_uuid'),
            array('First aid', '2026-03-01', 'jdoe', 'x')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one member identifier');

        $this->service->resolveMapping($rows);
    }

    public function testAMemberNameNeedsBothHalves(): void
    {
        $rows = array(
            array('title', 'begin_date', 'last_name'),
            array('First aid', '2026-03-01', 'Doe')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both a first_name and a last_name');

        $this->service->resolveMapping($rows);
    }

    /**
     * A period column the operator mapped and an import that ignored it would credit every row to
     * the activity date instead, which is a wrong result rather than a missing one.
     */
    public function testAPeriodColumnWithoutItsCreditsIsRefused(): void
    {
        $rows = array(
            array('title', 'begin_date', 'period_CPD'),
            array('First aid', '2026-03-01', '2026')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no matching "credit_cpd" column');

        $this->service->resolveMapping($rows);
    }

    /**
     * A header is a suggestion. A member who exports their own attendance from a course provider
     * plausibly gets a file with their name in it, and the import assigns their rows server-side
     * anyway - so the column is passed over rather than turned into a refusal of the whole file.
     */
    public function testAHeaderNamingARestrictedFieldIsPassedOverWhenTheActorMayNotUseIt(): void
    {
        $rows = array(
            array('user_login', 'title', 'begin_date', 'status'),
            array('jdoe', 'First aid', '2026-03-01', 'submitted')
        );

        $mapping = $this->service->resolveMapping($rows, array(), true, false);

        $this->assertSame(array('title' => 1, 'begin_date' => 2), $mapping);
    }

    /**
     * An assignment made by hand is a different matter: there the operator asked for something they
     * do not have, and passing over it silently would hide that.
     */
    public function testAnExplicitAssignmentToARestrictedFieldIsRefused(): void
    {
        $rows = array(array('Who', 'title', 'begin_date'), array('jdoe', 'First aid', '2026-03-01'));

        $this->expectException(\Admidio\Infrastructure\Exception::class);

        $this->service->resolveMapping($rows, array('user_login' => 'Who'), true, false);
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function restrictedFieldCases(): array
    {
        return array(
            'a login' => array('user_login', true),
            'a UUID' => array('user_uuid', true),
            'a numeric id' => array('user_id', true),
            'the last name half of the pair' => array('last_name', true),
            'the first name half of the pair' => array('first_name', true),
            'the activity status' => array('status', true),
            'the title' => array('title', false),
            'a credit column' => array('credit_cpd', false),
            'the source key' => array('source_key', false)
        );
    }

    /**
     * @dataProvider restrictedFieldCases
     */
    public function testTheRestrictedFieldsAreTheMemberIdentifiersAndTheStatus(
        string $field,
        bool $restricted
    ): void {
        $this->assertSame($restricted, ImportService::isRestrictedField($field));
    }

    public function testAFileWithNoRowsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains no rows');

        $this->service->resolveMapping(array());
    }

    /**
     * The §12 limits are a contract with the operator, not an implementation detail: a file just
     * under one of them has to be accepted, so the numbers are asserted rather than merely present.
     */
    public function testTheDocumentedLimitsAreTheOnesTheServiceEnforces(): void
    {
        $this->assertSame(8388608, ImportService::MAX_FILE_BYTES);
        $this->assertSame(50, ImportService::MAX_WORKSHEETS);
        $this->assertSame(5000, ImportService::MAX_ROWS);
        $this->assertSame(200, ImportService::MAX_COLUMNS);
        $this->assertSame(250000, ImportService::MAX_CELLS);
    }

    public function testTheDuplicatePolicyHasExactlyTheTwoDocumentedAnswers(): void
    {
        // REQ-DEC-008: skip or create, and nothing that could serve as a silent default.
        $this->assertSame(array('skip', 'create'), ImportService::DUPLICATE_POLICIES);
    }
}
