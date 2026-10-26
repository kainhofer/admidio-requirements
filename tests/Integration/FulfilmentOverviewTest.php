<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;
use DateTimeImmutable;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The fulfilment overview above the activity list: the periods it shows, and paging back through
 * them. The overview is always relative to today, so every date here is too.
 */
final class FulfilmentOverviewTest extends RequirementsDatabaseTestCase
{
    /**
     * A course renews a three-year requirement for the three years that start on its date, and
     * that window is what the overview shows - not an empty bar for the cycle after it.
     */
    public function testARenewedRecurringRequirementShowsTheWindowItIsValidFor(): void
    {
        global $gSettingsManager;

        $member = $this->createMember('req_ovw_valid');
        $type = $this->recurringRequirement($member['rol_id']);
        $renewal = $this->daysAgo(365);
        $this->createCreditedActivity($member['usr_id'], $type, $renewal, 1.0);

        $row = $this->overviewRow($member['usr_id'], $type);

        $this->assertCount(1, $row['periods']);
        $period = $row['periods'][0];
        $format = $gSettingsManager->getString('system_date');
        $from = new DateTimeImmutable($renewal);
        $this->assertSame(
            $from->format($format) . ' – ' . $from->modify('+3 years -1 day')->format($format),
            $period['label']
        );
        $this->assertSame('fulfilled', $period['status']);
        $this->assertSame(1.0, $period['attained']);
        $this->assertSame(1.0, $period['required']);
        $this->assertSame($renewal, $period['periodTo']);
        $this->assertFalse($row['hasEarlier']);
    }

    public function testCreditsTowardsTheNextCycleAddABarOfTheirOwn(): void
    {
        $member = $this->createMember('req_ovw_progress');
        $type = $this->recurringRequirement($member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, $this->daysAgo(400), 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, $this->daysAgo(30), 4.0);

        $periods = $this->overviewRow($member['usr_id'], $type)['periods'];

        $this->assertCount(2, $periods);
        $this->assertSame('fulfilled', $periods[0]['status']);
        $this->assertSame('in-progress', $periods[1]['status']);
        $this->assertSame(4.0, $periods[1]['attained']);
        $this->assertSame('', $periods[1]['periodTo'], 'The cycle under way is open-ended.');
    }

    public function testAnExpiredCycleIsFollowedByAnOverdueBar(): void
    {
        $member = $this->createMember('req_ovw_overdue');
        $type = $this->recurringRequirement($member['rol_id']);
        $this->createCreditedActivity($member['usr_id'], $type, $this->daysAgo(4 * 365), 1.0);

        $periods = $this->overviewRow($member['usr_id'], $type)['periods'];

        $this->assertSame(array('fulfilled', 'overdue'), array_column($periods, 'status'));
    }

    public function testPagingMovesBackThroughTheRenewalCycles(): void
    {
        $member = $this->createMember('req_ovw_cycles');
        $type = $this->recurringRequirement($member['rol_id']);
        foreach (array(2000, 1000, 10) as $days) {
            $this->createCreditedActivity($member['usr_id'], $type, $this->daysAgo($days), 1.0);
        }

        $latest = $this->overviewRow($member['usr_id'], $type, 0, 0);
        $first = $this->overviewRow($member['usr_id'], $type, 0, 2);
        $beyond = $this->overviewRow($member['usr_id'], $type, 0, 3);

        $this->assertSame($this->daysAgo(10), $latest['periods'][0]['periodTo']);
        $this->assertTrue($latest['hasEarlier']);
        $this->assertSame($this->daysAgo(2000), $first['periods'][0]['periodTo']);
        $this->assertFalse($first['hasEarlier']);
        $this->assertSame(array(), $beyond['periods']);
    }

    public function testPagingMovesACalendarRequirementBackByThePeriodsShown(): void
    {
        $year = (int) date('Y');
        // Joined in the year of the contribution, so neither reaches back past it.
        $member = $this->createMemberJoining('req_ovw_years', ($year - 7) . '-01-01');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, ($year - 7) . '-03-01', 10.0);

        $current = $this->overviewRow($member['usr_id'], $type, 2, 0);
        $back = $this->overviewRow($member['usr_id'], $type, 2, 2);
        $last = $this->overviewRow($member['usr_id'], $type, 2, 3);

        $this->assertSame((string) $year, end($current['periods'])['label']);
        $this->assertTrue($current['hasEarlier'], 'A contribution lies before the years shown.');
        $this->assertSame(
            array((string) ($year - 8), (string) ($year - 7), (string) ($year - 6)),
            array_column($back['periods'], 'label')
        );
        $this->assertSame('fulfilled', $back['periods'][1]['status']);
        $this->assertFalse($last['hasEarlier']);
    }

    /**
     * System roles store a translation key as their name; the rule list shows what it stands for.
     */
    public function testARuleOfASystemRoleShowsTheTranslatedRoleName(): void
    {
        global $gCurrentOrgId, $gL10n;

        $role = $this->fixture->createAndSaveRole('INS_BOARD', (int) $gCurrentOrgId);
        $type = $this->createRequirement('Board', (int) $role['rol_id'], 5.0);

        $rules = (new RequirementTypeService($this->getDatabase()))->findRules((int) $type->getValue('rqt_id'));

        $this->assertSame($gL10n->get('INS_BOARD'), $rules[0]['role_name']);
        $this->assertNotSame('INS_BOARD', $rules[0]['role_name']);
    }

    private function recurringRequirement(int $roleId, float $credits = 1.0): RequirementType
    {
        return $this->createRequirement(
            'Licence',
            $roleId,
            $credits,
            array('rrr_partial_period_mode' => 'full'),
            array('rqt_period_anchor' => 'last_fulfilment', 'rqt_period_count' => 3)
        );
    }

    private function daysAgo(int $days): string
    {
        return (new DateTimeImmutable(DATE_NOW))->modify('-' . $days . ' days')->format('Y-m-d');
    }

    /**
     * @return array<string,mixed>
     */
    private function overviewRow(int $userId, RequirementType $type, int $previous = 4, int $offset = 0): array
    {
        $rows = $this->asMember($userId, fn(): array =>
            (new FulfilmentService($this->getDatabase()))->coverageHistoryForUser($userId, $previous, $offset));
        foreach ($rows as $row) {
            if ($row['typeId'] === (int) $type->getValue('rqt_id')) {
                return $row;
            }
        }
        $this->fail('No overview row for requirement ' . $type->getValue('rqt_name') . '.');
    }
}
