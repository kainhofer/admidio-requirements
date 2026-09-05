<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use AdmidioPlugin\Requirements\Presenter\ImportPresenter;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * How an import report is presented once it is longer than the table will show.
 *
 * A failed row is only useful if somebody sees it. The counts always say how many there were, but
 * the table is what says *which* - and a table cut at the first N rows loses exactly the rows that
 * need acting on, because a long file's failures are spread through it rather than sitting at the
 * top. Nothing else in the suite would notice: the import still reports "3 errors", the page still
 * renders, and the three rows are simply not on it.
 */
final class ImportReportTest extends RequirementsUnitTestCase
{
    public function testAReportThatFitsIsShownAsItIs(): void
    {
        $rows = array(self::row(1, 'imported'), self::row(2, 'error'), self::row(3, 'skip'));

        $this->assertSame($rows, ImportPresenter::rowsWorthShowing($rows, 10));
    }

    public function testTheRowsThatNeedActingOnSurviveTheCut(): void
    {
        $rows = array();
        for ($number = 1; $number <= 100; ++$number) {
            $rows[] = self::row($number, 'imported');
        }
        // The two that went wrong are at the far end of the file, where a cut at the top loses them.
        $rows[] = self::row(101, 'error');
        $rows[] = self::row(102, 'skip');

        $shown = ImportPresenter::rowsWorthShowing($rows, 10);

        $this->assertCount(10, $shown);
        $this->assertSame(array(101, 102), array_column(array_slice($shown, 0, 2), 'row'));
        $this->assertSame(array('error', 'skip'), array_column(array_slice($shown, 0, 2), 'status'));
    }

    /**
     * Errors before skips, and file order inside each group - a reviewer reads the table against
     * the file in front of them, so a row still has to be findable by its number.
     */
    public function testEachGroupKeepsItsFileOrder(): void
    {
        $rows = array(
            self::row(1, 'imported'),
            self::row(2, 'skip'),
            self::row(3, 'error'),
            self::row(4, 'imported'),
            self::row(5, 'skip'),
            self::row(6, 'error')
        );

        $shown = ImportPresenter::rowsWorthShowing($rows, 5);

        $this->assertSame(array(3, 6, 2, 5, 1), array_column($shown, 'row'));
    }

    /**
     * More failures than the table holds is the case where the count and the table disagree, and
     * the page has to say so rather than look complete.
     */
    public function testACutIsStillACut(): void
    {
        $rows = array();
        for ($number = 1; $number <= 20; ++$number) {
            $rows[] = self::row($number, 'error');
        }

        $this->assertCount(5, ImportPresenter::rowsWorthShowing($rows, 5));
    }

    /**
     * @return array<string,mixed>
     */
    private static function row(int $number, string $status): array
    {
        return array('row' => $number, 'status' => $status);
    }
}
