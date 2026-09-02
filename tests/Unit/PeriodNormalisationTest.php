<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;
use DateTimeImmutable;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * Concept §6.6 and §8.1: which period a date falls into, and what a credited period is normalised to.
 */
final class PeriodNormalisationTest extends RequirementsUnitTestCase
{
    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function calendarPeriods(): array
    {
        return array(
            'year from a date in the middle' => array('year', '2026-07-14', '2026-01-01', '2026-12-31', '2026'),
            'year from its first day' => array('year', '2026-01-01', '2026-01-01', '2026-12-31', '2026'),
            'year from its last day' => array('year', '2026-12-31', '2026-01-01', '2026-12-31', '2026'),
            'leap year ends on 31 December' => array('year', '2024-02-29', '2024-01-01', '2024-12-31', '2024'),
            'first quarter' => array('quarter', '2026-02-11', '2026-01-01', '2026-03-31', 'Q1 2026'),
            'second quarter' => array('quarter', '2026-04-01', '2026-04-01', '2026-06-30', 'Q2 2026'),
            'third quarter' => array('quarter', '2026-09-30', '2026-07-01', '2026-09-30', 'Q3 2026'),
            'fourth quarter' => array('quarter', '2026-12-31', '2026-10-01', '2026-12-31', 'Q4 2026'),
            'month' => array('month', '2026-05-20', '2026-05-01', '2026-05-31', '2026-05'),
            'february in a leap year' => array('month', '2024-02-15', '2024-02-01', '2024-02-29', '2024-02'),
            'week from a Thursday' => array('week', '2026-01-01', '2025-12-29', '2026-01-04', '2026-W01'),
            'week from its Monday' => array('week', '2026-03-02', '2026-03-02', '2026-03-08', '2026-W10'),
            'week from its Sunday' => array('week', '2026-03-08', '2026-03-02', '2026-03-08', '2026-W10')
        );
    }

    /**
     * @dataProvider calendarPeriods
     */
    public function testPeriodForUnitBracketsTheReferenceDate(
        string $unit,
        string $reference,
        string $expectedStart,
        string $expectedEnd,
        string $expectedLabel
    ): void {
        [$start, $end, $label] = FulfilmentService::periodForUnit($unit, 1, new DateTimeImmutable($reference));

        $this->assertSame($expectedStart, $start->format('Y-m-d'));
        $this->assertSame($expectedEnd, $end->format('Y-m-d'));
        $this->assertSame($expectedLabel, $label);
    }

    /**
     * The week of 1 January 2026 starts in the previous year, which is the case an implementation
     * that snaps to "1 January or later" gets wrong.
     */
    public function testAnIsoWeekMayStartInThePreviousYear(): void
    {
        [$start, $end] = FulfilmentService::periodForUnit('week', 1, new DateTimeImmutable('2026-01-01'));

        $this->assertSame('2025-12-29', $start->format('Y-m-d'));
        $this->assertSame('2026-01-04', $end->format('Y-m-d'));
    }

    public function testCalendarPeriodCountAboveOneIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PLG_REQUIREMENTS_CALENDAR_MULTI_PERIOD_UNSUPPORTED');

        FulfilmentService::periodForUnit('year', 2, new DateTimeImmutable('2026-07-14'));
    }

    public function testAnUnknownPeriodUnitIsRejected(): void
    {
        $this->expectException(Exception::class);

        FulfilmentService::periodForUnit('fortnight', 1, new DateTimeImmutable('2026-07-14'));
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function controlInputs(): array
    {
        return array(
            'year' => array('year', '2026', '2026-01-01'),
            'month' => array('month', '2026-05', '2026-05-01'),
            'december' => array('month', '2026-12', '2026-12-01'),
            'week' => array('week', '2026-W10', '2026-03-02'),
            'week 1 of 2026 starts in 2025' => array('week', '2026-W01', '2025-12-29'),
            'date passes through' => array('date', '2026-07-14', '2026-07-14')
        );
    }

    /**
     * @dataProvider controlInputs
     */
    public function testPeriodInputToDateConvertsEachControl(string $kind, string $raw, string $expected): void
    {
        $this->assertSame($expected, FulfilmentService::periodInputToDateForKind($kind, $raw));
    }

    public function testQuarterControlCombinesYearAndQuarter(): void
    {
        $this->assertSame('2026-01-01', FulfilmentService::periodInputToDateForKind('quarter', '2026', '1'));
        $this->assertSame('2026-04-01', FulfilmentService::periodInputToDateForKind('quarter', '2026', '2'));
        $this->assertSame('2026-07-01', FulfilmentService::periodInputToDateForKind('quarter', '2026', '3'));
        $this->assertSame('2026-10-01', FulfilmentService::periodInputToDateForKind('quarter', '2026', '4'));
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function rejectedInputs(): array
    {
        return array(
            'empty year' => array('year', '', ''),
            'year below the range' => array('year', '1899', ''),
            'year far in the future' => array('year', '2999', ''),
            'year with letters' => array('year', '20x6', ''),
            'month without a month' => array('month', '2026', ''),
            'month 13' => array('month', '2026-13', ''),
            'month 00' => array('month', '2026-00', ''),
            'week 54' => array('week', '2026-W54', ''),
            'week 00' => array('week', '2026-W00', ''),
            'week without the W' => array('week', '2026-10', ''),
            'quarter without a quarter' => array('quarter', '2026', ''),
            'quarter 5' => array('quarter', '2026', '5'),
            'quarter 0' => array('quarter', '2026', '0'),
            'date that is not a date' => array('date', '2026-02-30', ''),
            'date in a foreign format' => array('date', '14.07.2026', '')
        );
    }

    /**
     * @dataProvider rejectedInputs
     */
    public function testMalformedOrOutOfRangeInputIsRejected(string $kind, string $raw, string $quarter): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SYS_DATE_INVALID');

        FulfilmentService::periodInputToDateForKind($kind, $raw, $quarter);
    }

    /**
     * The control is a usability layer over the stored date, so rendering a stored value into the
     * control and reading it back has to arrive at the same stored value.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function roundTrips(): array
    {
        return array(
            'year' => array('year', '2026-01-01'),
            'quarter' => array('quarter', '2026-07-01'),
            'month' => array('month', '2026-05-01'),
            'week' => array('week', '2026-03-02'),
            'date' => array('date', '2026-07-14')
        );
    }

    /**
     * @dataProvider roundTrips
     */
    public function testStoredValueSurvivesTheControl(string $kind, string $stored): void
    {
        $parts = FulfilmentService::periodControlValue($kind, $stored);

        $this->assertSame(
            $stored,
            FulfilmentService::periodInputToDateForKind($kind, $parts['value'], $parts['quarter'])
        );
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function controlKinds(): array
    {
        return array(
            'calendar year' => array('calendar', 'year', 'year'),
            'calendar quarter' => array('calendar', 'quarter', 'quarter'),
            'calendar month' => array('calendar', 'month', 'month'),
            'calendar week' => array('calendar', 'week', 'week'),
            'last fulfilment is a plain date' => array('last_fulfilment', 'year', 'date'),
            'last fulfilment ignores the unit' => array('last_fulfilment', 'week', 'date')
        );
    }

    /**
     * @dataProvider controlKinds
     */
    public function testTheControlFollowsTheRequirementUnit(string $anchor, string $unit, string $expected): void
    {
        $this->assertSame($expected, FulfilmentService::periodControlKindFor($anchor, $unit));
    }
}
