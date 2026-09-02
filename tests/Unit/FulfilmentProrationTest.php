<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

require_once dirname(__DIR__, 2) . '/src/Service/RequirementTypeService.php';
require_once dirname(__DIR__, 2) . '/src/Service/FulfilmentService.php';

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use DateTimeImmutable;

final class FulfilmentProrationTest extends AdmidioTestCase
{
    public function testAnnualMonthProrationUsesTwelveCalendarUnits(): void
    {
        $starts = FulfilmentService::prorationUnitStarts(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31'),
            'month'
        );

        $this->assertCount(12, $starts);
        $this->assertSame('2026-01-01', $starts[0]->format('Y-m-d'));
        $this->assertSame('2026-12-01', $starts[11]->format('Y-m-d'));
    }

    public function testAnnualDayProrationUsesCalendarDays(): void
    {
        $this->assertCount(
            365,
            FulfilmentService::prorationUnitStarts(
                new DateTimeImmutable('2026-01-01'),
                new DateTimeImmutable('2026-12-31'),
                'day'
            )
        );
    }

    public function testAnnualWeekProrationStartsAtPeriodBoundaryThenMondays(): void
    {
        $starts = FulfilmentService::prorationUnitStarts(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31'),
            'week'
        );

        $this->assertCount(53, $starts);
        $this->assertSame('2026-01-01', $starts[0]->format('Y-m-d'));
        $this->assertSame('2026-01-05', $starts[1]->format('Y-m-d'));
    }
}
