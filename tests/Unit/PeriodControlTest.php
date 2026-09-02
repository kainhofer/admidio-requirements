<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

require_once dirname(__DIR__, 2) . '/src/Service/RequirementTypeService.php';
require_once dirname(__DIR__, 2) . '/src/Service/FulfilmentService.php';

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Requirements\Service\FulfilmentService;

final class PeriodControlTest extends AdmidioTestCase
{
    public function testYearControlValue(): void
    {
        $this->assertSame(
            array('value' => '2026', 'quarter' => ''),
            FulfilmentService::periodControlValue('year', '2026-01-01')
        );
    }

    public function testQuarterControlValueDerivesTheQuarterFromTheMonth(): void
    {
        $this->assertSame(
            array('value' => '2026', 'quarter' => '3'),
            FulfilmentService::periodControlValue('quarter', '2026-07-01')
        );
        $this->assertSame(
            array('value' => '2008', 'quarter' => '1'),
            FulfilmentService::periodControlValue('quarter', '2008-02-11')
        );
    }

    public function testMonthAndWeekControlValues(): void
    {
        $this->assertSame(
            array('value' => '2026-05', 'quarter' => ''),
            FulfilmentService::periodControlValue('month', '2026-05-20')
        );
        // 2026-01-01 is a Thursday, ISO week 1.
        $this->assertSame(
            array('value' => '2026-W01', 'quarter' => ''),
            FulfilmentService::periodControlValue('week', '2026-01-01')
        );
    }

    public function testEmptyReferenceGivesEmptyControlValue(): void
    {
        $this->assertSame(
            array('value' => '', 'quarter' => ''),
            FulfilmentService::periodControlValue('year', '')
        );
    }
}
