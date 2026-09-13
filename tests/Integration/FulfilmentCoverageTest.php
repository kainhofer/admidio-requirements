<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * Coverage end to end (§8.1): real memberships, real credits, real numbers.
 *
 * The unit tests pin the obligation half against arrays; this one checks that the same policy comes
 * out of the database - that the membership rows are read the way the calculator expects, that the
 * credited period is what selects a contribution, and that accepted overrides claimed.
 */
final class FulfilmentCoverageTest extends RequirementsDatabaseTestCase
{
    private const YEAR = '2026';

    public function testAFulfilledYearReportsAsFulfilled(): void
    {
        $member = $this->createMember('req_cov_full');
        $type = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 15.0);

        $row = $this->coverage($member['usr_id'], $type);

        $this->assertSame(15.0, $row['attained']);
        $this->assertSame(15.0, $row['required']);
        $this->assertSame(100.0, $row['coverage']);
        $this->assertSame('fulfilled', $row['status']);
        $this->assertSame(array(), $row['warnings']);
    }

    public function testAnIncompleteYearReportsWhatIsMissing(): void
    {
        $member = $this->createMember('req_cov_partial');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 5.0);

        $row = $this->coverage($member['usr_id'], $type);

        $this->assertSame(5.0, $row['attained']);
        $this->assertSame(20.0, $row['required']);
        $this->assertSame(25.0, $row['coverage']);
        $this->assertSame('missing', $row['status']);
    }

    /**
     * §6.6: the credited period is explicit and independent of the activity dates. An activity held
     * in January can be credited to the previous year.
     */
    public function testTheCreditedPeriodIsWhatSelectsAContribution(): void
    {
        $member = $this->createMember('req_cov_period');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);

        // Held in 2026, credited to 2025.
        $this->createCreditedActivity($member['usr_id'], $type, '2026-01-15', 10.0, null, '2025-06-01');

        $this->assertSame(0.0, $this->coverage($member['usr_id'], $type, '2026-06-01')['attained']);
        $this->assertSame(10.0, $this->coverage($member['usr_id'], $type, '2025-06-01')['attained']);
    }

    /**
     * §6.6: the reference is normalised to the start of the period, so any day of the year selects
     * the same contribution.
     */
    public function testAnyDayOfThePeriodFindsTheSameCredits(): void
    {
        $member = $this->createMember('req_cov_normalise');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-07-14', 4.0, null, '2026-11-30');

        foreach (array('2026-01-01', '2026-07-14', '2026-12-31') as $day) {
            $this->assertSame(4.0, $this->coverage($member['usr_id'], $type, $day)['attained'], $day);
        }
    }

    /**
     * §6.6: accepted overrides claimed, and an accepted zero is an explicit rejection.
     */
    public function testAcceptedCreditsOverrideTheClaim(): void
    {
        $member = $this->createMember('req_cov_accepted');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 10.0, 4.0);

        $this->assertSame(4.0, $this->coverage($member['usr_id'], $type)['attained']);
    }

    public function testAnAcceptedZeroRejectsTheClaimEntirely(): void
    {
        $member = $this->createMember('req_cov_zero');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 10.0, 0.0);

        $row = $this->coverage($member['usr_id'], $type);

        $this->assertSame(0.0, $row['attained']);
        $this->assertSame('missing', $row['status']);
    }

    /**
     * REQ-DEC-022: a draft does not count, and is reported as pending rather than dropped.
     */
    public function testDraftCreditsAreReportedAsPendingAndDoNotCount(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));

        $member = $this->createMember('req_cov_draft');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);

        $this->assertSame('draft', (string) $activity->getValue('rqa_status'));

        $row = $this->coverage($member['usr_id'], $type);
        $this->assertSame(0.0, $row['attained'], 'A draft must not raise the coverage.');
        $this->assertSame(6.0, $row['pending'], 'It is pending, not lost.');
        $this->assertSame('missing', $row['status']);

        (new ActivityService($this->getDatabase()))->submit($activity);

        $row = $this->coverage($member['usr_id'], $type);
        $this->assertSame(6.0, $row['attained'], 'Submitting is what makes it count.');
        $this->assertSame(0.0, $row['pending']);
    }

    public function testWithdrawnCreditsNeverCount(): void
    {
        $member = $this->createMember('req_cov_withdrawn');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 10.0);

        (new ActivityService($this->getDatabase()))->save($activity, array('rqa_status' => 'withdrawn'));

        $row = $this->coverage($member['usr_id'], $type);
        $this->assertSame(0.0, $row['attained']);
        $this->assertSame(0.0, $row['pending'], 'Withdrawn is not pending either; it is gone.');
    }

    // ---------------------------------------------------------------- historical membership

    /**
     * §6.3: without the gate a late joiner still owes a share, measured from the real membership row.
     */
    public function testALateJoinerOwesAProRataShare(): void
    {
        $member = $this->createMemberJoining('req_cov_late', '2026-07-01');
        $type = $this->createRequirement('CPD', $member['rol_id'], 12.0);

        $this->assertSame(6.0, $this->coverage($member['usr_id'], $type)['required']);
    }

    /**
     * With the gate closed and no credits of its own, the requirement is not part of this member's
     * year at all, so it is left out of the report rather than shown as a zero.
     */
    public function testThePeriodStartGateExcusesALateJoinerEntirely(): void
    {
        $member = $this->createMemberJoining('req_cov_gate', '2026-07-01');
        $type = $this->createRequirement('CPD', $member['rol_id'], 12.0, array(
            'rrr_membership_at_period_start' => true
        ));

        $this->assertNull(
            $this->findCoverage($member['usr_id'], $type),
            'A requirement that does not apply should not appear in the report.'
        );

        // The same member and rule in the year they were there from the start does create one.
        $this->assertSame(12.0, $this->coverage($member['usr_id'], $type, '2027-06-01')['required']);
    }

    /**
     * §6.3: leaving and coming back counts both intervals, which needs two membership rows to be
     * read for one role.
     */
    public function testLeavingAndRejoiningCountsBothIntervals(): void
    {
        global $gCurrentOrgId;

        // The membership of the *organization* has to be current for the report to be readable at
        // all, so the gap is in a second role - which is also how this looks in practice: somebody
        // who stayed a member while their committee seat lapsed.
        $member = $this->createMember('req_cov_rejoin');
        $role = $this->fixture->createAndSaveRole('Rejoin role', (int) $gCurrentOrgId);
        $this->fixture->assignUserToRolePeriod($member['usr_id'], (int) $role['rol_id'], '2026-01-01', '2026-03-31');
        $this->fixture->assignUserToRolePeriod($member['usr_id'], (int) $role['rol_id'], '2026-10-01', '2026-12-31');

        $type = $this->createRequirement('CPD', (int) $role['rol_id'], 12.0);

        $this->assertSame(6.0, $this->coverage($member['usr_id'], $type)['required']);
    }

    /**
     * §6.2: two adjacent validity rows are one obligation, not two.
     */
    public function testAdjacentRulesAddUpToOneYear(): void
    {
        $member = $this->createMember('req_cov_adjacent');
        $type = $this->createRequirement('CPD', $member['rol_id'], 12.0, array(
            'rrr_valid_until' => '2026-06-30'
        ));
        (new RequirementTypeService($this->getDatabase()))->saveRule(
            new RequirementRoleRule($this->getDatabase()),
            $type,
            array(
                'rrr_role_id' => $member['rol_id'],
                'rrr_required_credits' => 24.0,
                'rrr_valid_from' => '2026-07-01'
            )
        );

        $this->assertSame(18.0, $this->coverage($member['usr_id'], $type)['required']);
    }

    /**
     * §6.2: several full rows are a misconfiguration, and the report has to say so.
     */
    public function testSeveralFullRulesRaiseAVisibleWarning(): void
    {
        global $gL10n;

        $member = $this->createMember('req_cov_warn');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0, array(
            'rrr_partial_period_mode' => 'full',
            'rrr_valid_until' => '2026-06-30'
        ));
        (new RequirementTypeService($this->getDatabase()))->saveRule(
            new RequirementRoleRule($this->getDatabase()),
            $type,
            array(
                'rrr_role_id' => $member['rol_id'],
                'rrr_required_credits' => 25.0,
                'rrr_partial_period_mode' => 'full',
                'rrr_valid_from' => '2026-07-01'
            )
        );

        $row = $this->coverage($member['usr_id'], $type);

        $this->assertSame(25.0, $row['required'], 'The highest full value is used.');
        $this->assertSame(
            array($gL10n->get('PLG_REQUIREMENTS_WARNING_SEVERAL_FULL_RULES', array('Role req_cov_warn'))),
            $row['warnings'],
            'The warning names the role rather than its id.'
        );
    }

    // ---------------------------------------------------------------- the detail behind a bar

    /**
     * Clicking a bar of the overview asks "what is this number made of". The list answers it by
     * narrowing to the contributions of that requirement and period, so an activity credited
     * elsewhere - or to nothing - drops out.
     */
    public function testTheListCanBeNarrowedToOnePeriodsContributions(): void
    {
        $member = $this->createMember('req_cov_detail');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);

        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 4.0, null, '2026-05-01');
        $this->createCreditedActivity($member['usr_id'], $type, '2025-03-01', 6.0, null, '2025-05-01');
        // Credited to nothing: it is in the list, but it is not part of any bar.
        (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Uncredited', 'rqa_begin_date' => '2026-04-01'
        ), $member['usr_id']);

        $service = new ActivityService($this->getDatabase());
        $typeId = (int) $type->getValue('rqt_id');

        $all = array_column($service->findActivities($member['usr_id'], '', $typeId), 'rqa_title');
        $this->assertContains('Uncredited', $all, 'Without a period the whole list is shown.');

        $of2026 = $service->findActivities($member['usr_id'], '', $typeId, array('2026-01-01', '2026-12-31'));
        $this->assertSame(array('Course on 2026-03-01'), array_column($of2026, 'rqa_title'));
        $this->assertSame(4.0, (float) $of2026[0]['rac_credits_claimed']);

        $of2025 = $service->findActivities($member['usr_id'], '', $typeId, array('2025-01-01', '2025-12-31'));
        $this->assertSame(array('Course on 2025-03-01'), array_column($of2025, 'rqa_title'));

        $this->assertSame(array(), $service->findActivities($member['usr_id'], '', $typeId, array('2020-01-01', '2020-12-31')));
    }

    /**
     * The figure the bar shows and the rows the detail lists have to be the same contributions.
     */
    public function testTheDetailAddsUpToTheBar(): void
    {
        $member = $this->createMember('req_cov_addsup');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-02-01', 3.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-06-01', 4.5);

        $row = $this->coverage($member['usr_id'], $type);
        $detail = (new ActivityService($this->getDatabase()))
            ->findActivities($member['usr_id'], '', (int) $type->getValue('rqt_id'), array('2026-01-01', '2026-12-31'));

        $this->assertSame(7.5, $row['attained']);
        $this->assertSame(7.5, array_sum(array_map('floatval', array_column($detail, 'rac_credits_claimed'))));
    }

    // ---------------------------------------------------------------- permissions and transactions

    public function testAMemberCannotReadAnotherMembersCoverage(): void
    {
        $member = $this->createMember('req_cov_reader');
        $other = $this->createMember('req_cov_subject');

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($member, $other): void {
            $this->asMember($member['usr_id'], function () use ($other): void {
                (new FulfilmentService($this->getDatabase()))->coverageForUser($other['usr_id'], '2026-06-01');
            });
        });
    }

    public function testAMemberMayReadTheirOwnCoverage(): void
    {
        $member = $this->createMember('req_cov_self');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 10.0);

        $rows = $this->asMember($member['usr_id'], fn(): array =>
            (new FulfilmentService($this->getDatabase()))->coverageForUser($member['usr_id'], '2026-06-01'));

        $this->assertNotSame(array(), $rows);
    }

    /**
     * §14 and finding 2.7: an activity and its credits are one unit. A credit that cannot be written
     * has to take the activity with it.
     */
    public function testARejectedCreditRollsTheWholeActivityBack(): void
    {
        $member = $this->createMember('req_cov_rollback');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);

        $before = $this->countActivities();

        try {
            (new ActivityService($this->getDatabase()))->saveWithCredits(
                new Activity($this->getDatabase()),
                array('rqa_title' => 'Rolled back', 'rqa_begin_date' => '2026-03-01'),
                array(
                    // A period the control cannot resolve, rejected after the activity was written.
                    (int) $type->getValue('rqt_id') => array(
                        'period' => 'not-a-year',
                        'quarter' => '',
                        'claimed' => 5.0,
                        'accepted' => null
                    )
                ),
                $member['usr_id']
            );
            $this->fail('An unusable credited period should be refused.');
        } catch (Exception) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, $this->countActivities(), 'The activity must not survive its credit.');
        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::activities() . ' WHERE rqa_title = ?',
            array('Rolled back')
        )->fetchColumn());
    }

    public function testAnActivityAndItsCreditsAreWrittenTogether(): void
    {
        $member = $this->createMember('req_cov_together');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);

        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->saveWithCredits(
            $activity,
            array('rqa_title' => 'Both saved', 'rqa_begin_date' => '2026-03-01'),
            array(
                (int) $type->getValue('rqt_id') => array(
                    'period' => '2026', 'quarter' => '', 'claimed' => 7.5, 'accepted' => null
                )
            ),
            $member['usr_id']
        );

        $credits = (new ActivityService($this->getDatabase()))
            ->creditsForActivity((int) $activity->getValue('rqa_id'));

        $this->assertArrayHasKey((int) $type->getValue('rqt_id'), $credits);
        $this->assertSame('2026-01-01', (string) $credits[(int) $type->getValue('rqt_id')]['rac_period_reference']);
        $this->assertSame(7.5, (float) $credits[(int) $type->getValue('rqt_id')]['rac_credits_claimed']);
    }

    /**
     * Clearing both fields removes the row, which is how the web form deletes a contribution.
     */
    public function testClearingACreditRemovesTheRow(): void
    {
        $member = $this->createMember('req_cov_clear');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 5.0);

        $service = new ActivityService($this->getDatabase());
        $service->syncCredit($activity, $type, '2026-01-01', null, null);

        $this->assertSame(array(), $service->creditsForActivity((int) $activity->getValue('rqa_id')));
    }

    /**
     * §6.6: a member may not overwrite a reviewer's accepted value by editing their own claim.
     */
    public function testAMemberCannotClearAnAcceptedValue(): void
    {
        $member = $this->createMember('req_cov_protect');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 10.0, 4.0);

        $this->asMember($member['usr_id'], function () use ($activity, $type): void {
            (new ActivityService($this->getDatabase()))->syncCredit($activity, $type, '2026-01-01', 10.0, null);
        });

        $credits = (new ActivityService($this->getDatabase()))
            ->creditsForActivity((int) $activity->getValue('rqa_id'));

        $this->assertSame(4.0, (float) $credits[(int) $type->getValue('rqt_id')]['rac_credits_accepted']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed> the coverage row of one requirement type
     */
    // ---------------------------------------------------------------- rolling windows

    /**
     * REQ-DEC-014, and the property the whole rolling-window reading rests on: one credit counts in
     * every window that contains its unit. "Sixteen hours in every two-year window" is satisfied by
     * eight hours a year, and the 2026 credit therefore has to be found by 2025-2026 and by
     * 2026-2027 alike - not moved from one to the other, and not counted once and then gone.
     */
    public function testOneCreditCountsInEveryWindowThatContainsItsYear(): void
    {
        $member = $this->createMember('req_cov_rolling');
        $type = $this->createRequirement('CPD', $member['rol_id'], 16.0, array(), array(
            'rqt_period_unit' => 'year',
            'rqt_period_count' => 2
        ));

        $this->createCreditedActivity($member['usr_id'], $type, '2025-05-01', 8.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-05-01', 8.0);

        // The window that opened in 2025 holds both years and is therefore met.
        $opened2025 = $this->findCoverage($member['usr_id'], $type, '2025-05-01');
        $this->assertNotNull($opened2025);
        $this->assertSame('2025 - 2026', $opened2025['period']);
        $this->assertSame(16.0, $opened2025['attained']);
        $this->assertSame('fulfilled', $opened2025['status']);

        // The window that opened in 2026 holds the 2026 credit and 2027, which has not happened
        // yet. The same credit is counted here as well.
        $opened2026 = $this->findCoverage($member['usr_id'], $type, '2026-05-01');
        $this->assertNotNull($opened2026);
        $this->assertSame('2026 - 2027', $opened2026['period']);
        $this->assertSame(8.0, $opened2026['attained']);
        $this->assertSame('missing', $opened2026['status']);
    }

    /**
     * A credit is stored against the unit it happened in, never against the window. Two years of
     * credits sharing one bucket would make the overlap above impossible to compute.
     */
    public function testACreditIsBookedToItsYearAndNotToTheWindow(): void
    {
        $member = $this->createMember('req_cov_booking');
        $type = $this->createRequirement('CPD', $member['rol_id'], 16.0, array(), array(
            'rqt_period_unit' => 'year',
            'rqt_period_count' => 2
        ));
        $this->createCreditedActivity($member['usr_id'], $type, '2026-05-01', 8.0);

        $this->assertSame('2026-01-01', (string) $this->getDatabase()->queryPrepared(
            'SELECT rac_period_reference FROM ' . RequirementsTables::activityCredits(),
            array()
        )->fetchColumn());
    }

    /**
     * A window that closed is reported exactly like one that is still open - the member reads the
     * label and knows which is which. What it is not is unreachable: entering a course from 2024
     * that was never recorded still repairs the window it belongs to.
     */
    public function testAClosedWindowIsReportedAndCanStillBeFilled(): void
    {
        $member = $this->createMember('req_cov_closed');
        $type = $this->createRequirement('CPD', $member['rol_id'], 16.0, array(), array(
            'rqt_period_unit' => 'year',
            'rqt_period_count' => 2
        ));

        $closed = $this->findCoverage($member['usr_id'], $type, '2022-05-01');
        $this->assertNotNull($closed);
        $this->assertSame('2022 - 2023', $closed['period']);
        $this->assertSame('missing', $closed['status']);

        // The course happened in 2022 and is only being entered now.
        $this->createCreditedActivity($member['usr_id'], $type, '2022-09-01', 16.0);

        $repaired = $this->findCoverage($member['usr_id'], $type, '2022-05-01');
        $this->assertNotNull($repaired);
        $this->assertSame(16.0, $repaired['attained']);
        $this->assertSame('fulfilled', $repaired['status']);
    }

    private function coverage(int $userId, RequirementType $type, string $reference = '2026-06-01'): array
    {
        $row = $this->findCoverage($userId, $type, $reference);
        if ($row === null) {
            $this->fail('No coverage row for requirement ' . $type->getValue('rqt_name') . '.');
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|null null when the requirement does not apply to this member
     */
    private function findCoverage(int $userId, RequirementType $type, string $reference = '2026-06-01'): ?array
    {
        foreach ((new FulfilmentService($this->getDatabase()))->coverageForUser($userId, $reference) as $row) {
            if (($row['typeId'] ?? 0) === (int) $type->getValue('rqt_id')) {
                return $row;
            }
        }

        return null;
    }
    private function countActivities(): int
    {
        return (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::activities()
        )->fetchColumn();
    }
}
