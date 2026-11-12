<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The query behind the activity table: one row per credit, the filter bar, the grouping order
 * and the paging that DataTables asks for.
 */
final class ActivityListTest extends RequirementsDatabaseTestCase
{
    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $filter, string $groupBy = '', array $order = array(), int $start = 0, int $length = -1): array
    {
        return (new ActivityService($this->getDatabase()))
            ->findActivityRows($filter, $groupBy, $order, $start, $length)['rows'];
    }

    public function testAnActivityIsOneRowPerCreditAndARequirementKeepsOnlyItsOwn(): void
    {
        $member = $this->createMember('req_list_rows');
        $cpd = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $ethics = $this->createRequirement('Ethics', $member['rol_id'], 2.0);

        $activity = $this->createCreditedActivity($member['usr_id'], $cpd, '2026-03-01', 4.0);
        (new ActivityService($this->getDatabase()))->saveCredit(
            new ActivityCredit($this->getDatabase()), $activity, $ethics, '2026-03-01', 1.0
        );
        (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Uncredited', 'rqa_begin_date' => '2026-04-01'
        ), $member['usr_id']);

        $all = $this->rows(array('userId' => $member['usr_id']));
        $this->assertCount(3, $all, 'Two credits are two rows, and the uncredited activity is the third.');

        $ofEthics = $this->rows(array(
            'userId' => $member['usr_id'],
            'requirementTypeId' => (int) $ethics->getValue('rqt_id')
        ));
        $this->assertSame(array('Course on 2026-03-01'), array_column($ofEthics, 'rqa_title'),
            'Narrowed to a requirement, an activity without a credit for it is not listed.');
        $this->assertSame(1.0, (float) $ofEthics[0]['rac_credits_claimed']);
    }

    public function testTheDateRangeKeepsEveryActivityThatTouchesIt(): void
    {
        $member = $this->createMember('req_list_range');
        $service = new ActivityService($this->getDatabase());
        $service->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Turn of the year', 'rqa_begin_date' => '2025-12-30', 'rqa_end_date' => '2026-01-02'
        ), $member['usr_id']);
        $service->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Spring', 'rqa_begin_date' => '2026-04-01'
        ), $member['usr_id']);

        $titles = static fn(array $rows): array => array_column($rows, 'rqa_title');
        $this->assertSame(
            array('Turn of the year'),
            $titles($this->rows(array('userId' => $member['usr_id'], 'dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31')))
        );
        $this->assertSame(
            array('Turn of the year'),
            $titles($this->rows(array('userId' => $member['usr_id'], 'dateTo' => '2025-12-31')))
        );
        $this->assertSame(
            array('Spring'),
            $titles($this->rows(array('userId' => $member['usr_id'], 'dateFrom' => '2026-01-03')))
        );
    }

    public function testGroupsComeFirstInTheOrderAndRowsWithoutAValueLast(): void
    {
        $member = $this->createMember('req_list_group');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $service = new ActivityService($this->getDatabase());

        $this->createCreditedActivity($member['usr_id'], $type, '2025-05-01', 1.0);
        $service->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Uncredited', 'rqa_begin_date' => '2027-01-01'
        ), $member['usr_id']);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-05-01', 1.0);

        $byPeriod = $this->rows(array('userId' => $member['usr_id']), 'period', array(array('title', 'asc')));
        $this->assertSame(
            array('Course on 2026-05-01', 'Course on 2025-05-01', 'Uncredited'),
            array_column($byPeriod, 'rqa_title'),
            'Newest period first; an activity credited to no period ends the list.'
        );
    }

    public function testTheOrderAndThePageAreTheOnesAskedFor(): void
    {
        $member = $this->createMember('req_list_page');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-01-01', 3.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-02-01', 1.0, 5.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 2.0);

        $service = new ActivityService($this->getDatabase());
        $result = $service->findActivityRows(
            array('userId' => $member['usr_id']),
            '',
            array(array('credits', 'desc')),
            1,
            1
        );

        $this->assertSame(3, $result['count'], 'The count is of all matching rows, not of the page.');
        $this->assertSame(array('Course on 2026-01-01'), array_column($result['rows'], 'rqa_title'),
            'Sorted by the accepted credits where there are some: 5, then 3, then 2.');

        $unknown = $service->findActivityRows(array('userId' => $member['usr_id']), '', array(array('rqa_id; DROP', 'asc')));
        $this->assertSame(3, $unknown['count'], 'A column the list does not know is ignored, not put into the SQL.');
    }

    /**
     * A part ("thereof in person") is a second line of the row of its parent credit, not a row of its
     * own - except where there is no parent credit to carry it, or the list is narrowed to the part.
     */
    public function testAPartIsNoRowOfItsOwnButTheSecondLineOfItsParent(): void
    {
        $member = $this->createMember('req_list_parts');
        $cpd = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $person = $this->createRequirement('Person', $member['rol_id'], 5.0);
        $service = new ActivityService($this->getDatabase());

        // A credit that was booked before the requirement became a part has no parent credit.
        $orphan = new Activity($this->getDatabase());
        $service->save($orphan, array('rqa_title' => 'Old course', 'rqa_begin_date' => '2025-03-01'), $member['usr_id']);
        $service->saveCredit(new ActivityCredit($this->getDatabase()), $orphan, $person, '2025-03-01', 2.0);

        (new \AdmidioPlugin\Requirements\Service\RequirementTypeService($this->getDatabase()))
            ->saveType($person, array('rqt_parent_id' => (int) $cpd->getValue('rqt_id')));

        $withBoth = $this->createCreditedActivity($member['usr_id'], $cpd, '2026-03-01', 25.0);
        $service->saveCredit(new ActivityCredit($this->getDatabase()), $withBoth, $person, '2026-03-01', 15.0);

        $rows = $this->rows(array('userId' => $member['usr_id']));
        $this->assertSame(
            array('Course on 2026-03-01' => 1, 'Old course' => 1),
            array_count_values(array_column($rows, 'rqa_title')),
            'One row for the activity with both credits, and the part that has no parent credit stays a row.'
        );
        $byTitle = array_column($rows, null, 'rqa_title');
        $this->assertSame((int) $cpd->getValue('rqt_id'), (int) $byTitle['Course on 2026-03-01']['rqt_id']);
        $this->assertSame((int) $person->getValue('rqt_id'), (int) $byTitle['Old course']['rqt_id']);

        $parts = $service->findPartCredits(array_column($rows, 'rqa_id'));
        $key = $withBoth->getValue('rqa_id') . '|' . $cpd->getValue('rqt_id') . '|2026-01-01';
        $this->assertArrayHasKey($key, $parts);
        $this->assertSame(15.0, (float) $parts[$key][0]['rac_credits_claimed']);
        $this->assertSame((string) $person->getValue('rqt_short_name'), $parts[$key][0]['rqt_short_name']);

        $ofThePart = $this->rows(array(
            'userId' => $member['usr_id'],
            'requirementTypeId' => (int) $person->getValue('rqt_id')
        ));
        $this->assertCount(2, $ofThePart, 'Narrowed to the part, its credits are the rows.');
    }
}
