<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Service\ActivityExportService;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ImportService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The export writes what the importer reads: a file exported from the list imports again without
 * a single column mapped by hand, and the activities it creates are the ones that were exported.
 */
final class ActivityExportTest extends RequirementsDatabaseTestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testAnExportedListImportsAgainUnchanged(): void
    {
        $member = $this->createMember('req_export_roundtrip');
        $yearly = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $quarterly = $this->createRequirement('Ethics', $member['rol_id'], 2.0, array(), array('rqt_period_unit' => 'quarter'));

        $service = new ActivityService($this->getDatabase());
        $activity = new Activity($this->getDatabase());
        $service->save($activity, array(
            'rqa_title' => 'Two-day seminar',
            'rqa_begin_date' => '2026-05-11',
            'rqa_begin_time' => '09:00',
            'rqa_end_date' => '2026-05-12',
            'rqa_end_time' => '17:30',
            'rqa_certificate_date' => '2026-05-20',
            'rqa_attendance' => '12.5',
            'rqa_location' => 'Vienna'
        ), $member['usr_id']);
        $service->saveCredit(new ActivityCredit($this->getDatabase()), $activity, $yearly, '2026-05-11', 12.5, 10.0);
        $service->saveCredit(new ActivityCredit($this->getDatabase()), $activity, $quarterly, '2026-05-11', 2.0);
        $this->createCreditedActivity($member['usr_id'], $yearly, '2025-09-01', 3.0);

        $this->file = tempnam(sys_get_temp_dir(), 'reqexport') . '.xlsx';
        (new Xlsx((new ActivityExportService($this->getDatabase()))->spreadsheet(array('userId' => $member['usr_id']))))
            ->save($this->file);

        $before = $this->snapshot($member['usr_id']);
        foreach ($service->findActivityRows(array('userId' => $member['usr_id']))['rows'] as $row) {
            $existing = new Activity($this->getDatabase(), (int) $row['rqa_id']);
            if ((int) $existing->getValue('rqa_id') > 0) {
                $service->delete($existing);
            }
        }
        $this->assertSame(array(), $this->snapshot($member['usr_id']));

        $import = new ImportService($this->getDatabase());
        $rows = $import->readFile($this->file);
        $result = $import->import($rows, $import->resolveMapping($rows), array('duplicates' => 'skip'));

        $this->assertSame(0, $result['summary']['error'], json_encode($result['rows']));
        $this->assertSame(2, $result['summary']['create']);

        // The reviewer's accepted value is the one thing an import must not set.
        $expected = $before;
        foreach ($expected as $i => $row) {
            $expected[$i]['rac_credits_accepted'] = null;
        }
        $this->assertEquals($expected, $this->snapshot($member['usr_id']));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function snapshot(int $userId): array
    {
        $rows = (new ActivityService($this->getDatabase()))->findActivityRows(
            array('userId' => $userId),
            '',
            array(array('date', 'asc'), array('requirement', 'asc'))
        )['rows'];

        return array_map(static fn(array $row): array => array_intersect_key($row, array_flip(array(
            'rqa_title', 'rqa_begin_date', 'rqa_end_date', 'rqa_certificate_date', 'rqa_attendance',
            'rac_period_reference', 'rac_credits_claimed', 'rac_credits_accepted', 'rqt_id'
        ))), $rows);
    }
}
