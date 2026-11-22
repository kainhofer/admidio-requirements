<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ReviewService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The administrative review of §9: one requirement across the members it concerns, and the accepted
 * credits written back from it.
 *
 * The per-member calculation is FulfilmentCoverageTest's subject and is not repeated here. What this
 * one is about is the roster: who ends up on it, who does not, and that batching the queries did not
 * quietly change the answer the single-member path gives.
 */
final class FulfilmentReviewTest extends RequirementsDatabaseTestCase
{
    private const PERIOD = '2026-06-01';

    public function testTheRosterListsEveryMemberTheRuleRoleCarries(): void
    {
        $first = $this->createMember('req_rev_first');
        $second = $this->fixture->createAndSaveUser(
            'req_rev_second',
            'req_rev_second@example.local',
            (int) $GLOBALS['gCurrentOrgId']
        );
        $this->fixture->assignUserToRolePeriod(
            (int) $second['usr_id'],
            $first['rol_id'],
            '1990-01-01',
            '9999-12-31'
        );

        $type = $this->createRequirement('CPD', $first['rol_id'], 15.0);
        $this->createCreditedActivity($first['usr_id'], $type, '2026-03-01', 15.0);

        $roster = $this->roster($type);

        $this->assertSame(
            array($first['usr_id'], (int) $second['usr_id']),
            array_column($roster['members'], 'userId'),
            'both role members belong on the roster'
        );
        $this->assertSame('fulfilled', $this->memberRow($roster, $first['usr_id'])['status']);
        $this->assertSame('missing', $this->memberRow($roster, (int) $second['usr_id'])['status']);
    }

    /**
     * The roster answers the same numbers as the member's own page, which is the point of sharing
     * calculateRequiredCredits() rather than writing a second calculation for a whole roster.
     */
    public function testARosterRowSaysWhatTheMembersOwnCoverageSays(): void
    {
        $member = $this->createMember('req_rev_agree');
        $type = $this->createRequirement('CPD', $member['rol_id'], 20.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 8.0);

        $own = null;
        foreach ((new FulfilmentService($this->getDatabase()))
                     ->coverageForUser($member['usr_id'], self::PERIOD) as $entry) {
            if ((int) $entry['typeId'] === (int) $type->getValue('rqt_id')) {
                $own = $entry;
            }
        }
        $this->assertNotNull($own);

        $row = $this->memberRow($this->roster($type), $member['usr_id']);

        $this->assertSame($own['attained'], $row['attained']);
        $this->assertSame($own['required'], $row['required']);
        $this->assertSame($own['status'], $row['status']);
        $this->assertSame((int) round($own['coverage']), $row['percent']);
    }

    /**
     * §8.5: the member's own overview only lists requirements their current roles carry, so credits
     * collected under a role they no longer hold disappear from their page. The review of the period
     * those credits belong to is exactly where they have to show up again.
     */
    public function testAMemberWithCreditsButNoRuleIsStillOnTheRoster(): void
    {
        $ruleMember = $this->createMember('req_rev_current');
        $type = $this->createRequirement('CPD', $ruleMember['rol_id'], 10.0);

        // A role of their own, which no rule of this requirement names.
        $outsider = $this->createMember('req_rev_leaver');
        $this->createCreditedActivity($outsider['usr_id'], $type, '2026-02-01', 4.0);

        $roster = $this->roster($type);
        $row = $this->memberRow($roster, $outsider['usr_id']);

        $this->assertSame(4.0, $row['attained']);
        // No rule applies to them, so there is nothing to fall short of - the credits are simply
        // extra, which is what the calculator calls them.
        $this->assertSame('additional-credits', $row['status']);
        $this->assertNull($row['required']);
        $this->assertCount(1, $row['contributions']);
    }

    public function testAMemberWithNeitherARuleNorACreditIsNotOnTheRoster(): void
    {
        $ruleMember = $this->createMember('req_rev_rule_only');
        $bystander = $this->createMember('req_rev_bystander');
        $type = $this->createRequirement('CPD', $ruleMember['rol_id'], 10.0);

        $this->assertSame(
            array($ruleMember['usr_id']),
            array_column($this->roster($type)['members'], 'userId')
        );
        $this->assertNull($this->findMemberRow($this->roster($type), $bystander['usr_id']));
    }

    /**
     * §7.6: the credit half of the candidate query is scoped by the organisation of the activity.
     * Without that scope an activity written for another organisation would drag its member onto
     * this organisation's roster.
     */
    public function testAnActivityOfAnotherOrganisationDoesNotPutItsMemberOnTheRoster(): void
    {
        $ruleMember = $this->createMember('req_rev_scoped');
        $outsider = $this->createMember('req_rev_foreign_activity');
        $type = $this->createRequirement('CPD', $ruleMember['rol_id'], 10.0);

        $this->insertForeignCredit($outsider['usr_id'], (int) $type->getValue('rqt_id'), self::PERIOD);

        $this->assertSame(
            array($ruleMember['usr_id']),
            array_column($this->roster($type)['members'], 'userId')
        );
    }

    public function testARequirementOfAnotherOrganisationIsRefused(): void
    {
        $otherOrgId = (int) $this->fixture->createAndSaveOrganization(
            'Other Review Organisation',
            'OTHREV'
        )['org_id'];

        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementTypes()
            . ' (rqt_uuid, rqt_org_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?)',
            array('cccccccc-0000-0000-0000-000000000001', $otherOrgId, 'Foreign review', 'year')
        );
        $foreignTypeId = (int) $this->getDatabase()->queryPrepared(
            'SELECT rqt_id FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_uuid = ?',
            array('cccccccc-0000-0000-0000-000000000001')
        )->fetchColumn();

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($foreignTypeId): void {
            (new FulfilmentService($this->getDatabase()))->coverageForMembers($foreignTypeId, self::PERIOD);
        });
    }

    public function testANormalMemberCannotReadTheRoster(): void
    {
        $member = $this->createMember('req_rev_no_rights');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $typeId = (int) $type->getValue('rqt_id');

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($member, $typeId): void {
            $this->asMember($member['usr_id'], function () use ($typeId): void {
                (new FulfilmentService($this->getDatabase()))->coverageForMembers($typeId, self::PERIOD);
            });
        });
    }

    public function testTheStatusFilterKeepsOnlyMatchingMembers(): void
    {
        $done = $this->createMember('req_rev_filter_done');
        $type = $this->createRequirement('CPD', $done['rol_id'], 10.0);
        $short = $this->fixture->createAndSaveUser(
            'req_rev_filter_short',
            'req_rev_filter_short@example.local',
            (int) $GLOBALS['gCurrentOrgId']
        );
        $this->fixture->assignUserToRolePeriod(
            (int) $short['usr_id'],
            $done['rol_id'],
            '1990-01-01',
            '9999-12-31'
        );
        $this->createCreditedActivity($done['usr_id'], $type, '2026-03-01', 10.0);

        $this->assertSame(
            array($done['usr_id']),
            array_column($this->roster($type, 'fulfilled')['members'], 'userId')
        );
        $this->assertSame(
            array((int) $short['usr_id']),
            array_column($this->roster($type, 'missing')['members'], 'userId')
        );
    }

    /**
     * The contributions behind a row are what a reviewer actually works on, so they have to carry
     * the claim, the acceptance and the id the accepted-credit field is named after.
     */
    public function testARowCarriesTheContributionsItIsMadeOf(): void
    {
        $member = $this->createMember('req_rev_contributions');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 6.0);
        $this->createCreditedActivity($member['usr_id'], $type, '2026-05-01', 5.0, 2.0);

        $row = $this->memberRow($this->roster($type), $member['usr_id']);

        $this->assertCount(2, $row['contributions']);
        $this->assertSame(array(6.0, 5.0), array_column($row['contributions'], 'claimedCredits'));
        $this->assertSame(array(null, 2.0), array_column($row['contributions'], 'acceptedCredits'));
        // Accepted beats claimed, so 6 + 2 rather than 6 + 5.
        $this->assertSame(8.0, $row['attained']);
        foreach ($row['contributions'] as $contribution) {
            $this->assertGreaterThan(0, $contribution['creditId']);
        }
    }

    /**
     * §8.3: a recurring requirement has no period to lay members side by side in, so the roster
     * answers the renewal question instead - when it was last met and when it is due again.
     */
    public function testARecurringRequirementReportsTheRenewalOfEachMember(): void
    {
        $member = $this->createMember('req_rev_recurring');
        $type = $this->createRequirement('First aid', $member['rol_id'], 1.0, array(), array(
            'rqt_period_anchor' => 'last_fulfilment',
            'rqt_period_unit' => 'year',
            'rqt_period_count' => 2
        ));
        $this->createCreditedActivity($member['usr_id'], $type, '2020-04-01', 1.0);

        $roster = $this->roster($type);
        $row = $this->memberRow($roster, $member['usr_id']);

        $this->assertSame('last_fulfilment', $roster['anchor']);
        $this->assertSame('2020-04-01', $row['lastFulfilment']);
        $this->assertSame('2022-04-01', $row['nextDue']);
        $this->assertSame('overdue', $row['status']);
    }

    // ---------------------------------------------------------------- decisions on credits

    /**
     * A requirement whose every submitted credit waits for a reviewer, with the current user as its
     * reviewer: the simplest setting in which a credit is decided on.
     *
     * @return array{0:array{usr_id:int,rol_id:int},1:RequirementType,2:int} member, requirement, credit id
     */
    private function queuedCredit(string $login, float $claimed = 8.0): array
    {
        $this->enableReviews(array((int) $GLOBALS['gCurrentUser']->getValue('usr_id')));
        $member = $this->createMember($login);
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0, array(),
            array('rqt_review_mode' => ReviewService::MODE_APPROVAL_QUEUE));
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', $claimed);

        return array($member, $type, $this->memberRow($this->roster($type), $member['usr_id'])['contributions'][0]['creditId']);
    }

    public function testDecidingOnACreditWritesTheReviewersNumber(): void
    {
        [$member, $type, $creditId] = $this->queuedCredit('req_rev_accept');

        $before = $this->memberRow($this->roster($type), $member['usr_id']);
        $this->assertSame(0.0, $before['attained'], 'In the queue mode a credit counts once accepted.');
        $this->assertSame(8.0, $before['pending']);

        $changed = (new ReviewService($this->getDatabase()))->saveDecisions(array(
            $creditId => array('accepted' => 3.0, 'status' => '', 'comment' => '')
        ));

        $this->assertSame(ReviewService::CREDIT_PARTIALLY_ACCEPTED, $changed[$creditId]['status'], 'The status follows the amount.');
        $this->assertSame(3.0, $this->memberRow($this->roster($type), $member['usr_id'])['attained']);
    }

    /**
     * §6.6: an accepted zero is an explicit rejection; taking a decision back leaves the credit open,
     * because a credit under review stays under review.
     */
    public function testAnAcceptedZeroRejectsAndTakingTheDecisionBackLeavesItOpen(): void
    {
        [$member, $type, $creditId] = $this->queuedCredit('req_rev_accept_zero');
        $service = new ReviewService($this->getDatabase());

        $service->saveDecisions(array($creditId => array('accepted' => 0.0, 'status' => '', 'comment' => '')));
        $credit = new ActivityCredit($this->getDatabase(), $creditId);
        $this->assertSame(ReviewService::CREDIT_NOT_ACCEPTED, $credit->getValue('rac_review_status'));
        $this->assertSame(0.0, $this->memberRow($this->roster($type), $member['usr_id'])['attained']);

        $service->saveDecisions(array($creditId => array('accepted' => null, 'status' => '', 'comment' => '')));
        $credit = new ActivityCredit($this->getDatabase(), $creditId);
        $this->assertSame(ReviewService::CREDIT_OPEN, $credit->getValue('rac_review_status'));
        $this->assertSame(8.0, $this->memberRow($this->roster($type), $member['usr_id'])['pending']);
    }

    public function testAnUnchangedDecisionIsNotWrittenAgain(): void
    {
        [, , $creditId] = $this->queuedCredit('req_rev_accept_same');
        $service = new ReviewService($this->getDatabase());
        $decision = array($creditId => array('accepted' => 5.0, 'status' => '', 'comment' => 'Half a day only'));

        $this->assertCount(1, $service->saveDecisions($decision));
        $this->assertSame(array(), $service->saveDecisions($decision));
    }

    /**
     * §14: the decisions of a row are one save, so a refused credit must not leave the credits
     * before it committed.
     *
     * Outside the per-test transaction, because the rollback under test unwinds every open one and
     * would otherwise take the fixtures with it rather than only the write.
     */
    public function testOneRefusedCreditRollsTheWholeRowBack(): void
    {
        $this->outsideTheTestTransaction(function (): void {
            [$member, $type, $creditId] = $this->queuedCredit('req_rev_accept_atomic');

            $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($creditId): void {
                (new ReviewService($this->getDatabase()))->saveDecisions(array(
                    $creditId => array('accepted' => 4.0, 'status' => '', 'comment' => ''),
                    $creditId + 100000 => array('accepted' => 1.0, 'status' => '', 'comment' => '')
                ));
            });

            $this->assertNull((new ActivityCredit($this->getDatabase(), $creditId))->getValue('rac_credits_accepted', 'database'));
            $this->assertSame(0.0, $this->memberRow($this->roster($type), $member['usr_id'])['attained']);
        });
    }

    /**
     * Administrators of the plugin administer the organization's requirements; deciding on the
     * members' credits is the reviewers'.
     */
    public function testAnAdministratorWhoIsNoReviewerCannotDecide(): void
    {
        [, , $creditId] = $this->queuedCredit('req_rev_accept_admin');
        $this->enableReviews(array());

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($creditId): void {
            (new ReviewService($this->getDatabase()))->saveDecisions(array(
                $creditId => array('accepted' => 1.0, 'status' => '', 'comment' => '')
            ));
        });
    }

    public function testANormalMemberCannotDecide(): void
    {
        [$member, , $creditId] = $this->queuedCredit('req_rev_accept_rights');

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($member, $creditId): void {
            $this->asMember($member['usr_id'], function () use ($creditId): void {
                (new ReviewService($this->getDatabase()))->saveDecisions(array(
                    $creditId => array('accepted' => 1.0, 'status' => '', 'comment' => '')
                ));
            });
        });
    }

    /**
     * §7.6: the activity list names the member it is narrowed to, so the `user` parameter has to be
     * answerable only for somebody this organisation knows - a member of it, or somebody who left it
     * but whose activities are still here.
     */
    public function testOnlyAMemberThisOrganisationKnowsCanNarrowTheActivityList(): void
    {
        global $gCurrentOrgId;

        $member = $this->createMember('req_rev_known');
        $service = new ActivityService($this->getDatabase());

        $this->assertTrue($service->isKnownMember($member['usr_id']));

        $otherOrgId = (int) $this->fixture->createAndSaveOrganization(
            'Unknown Member Organisation',
            'UNKMEM'
        )['org_id'];
        $stranger = (int) $this->fixture->createAndSaveUser(
            'req_rev_stranger',
            'req_rev_stranger@example.local',
            $otherOrgId
        )['usr_id'];

        $this->assertFalse($service->isKnownMember($stranger));

        // An activity of this organisation is the other half: somebody who has left it still has the
        // records the review sends an administrator here to look at. The row is written directly,
        // because the save path refuses a target user who is not a member any more - which is what
        // makes this the interesting case.
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activities()
            . ' (rqa_uuid, rqa_org_id, rqa_user_id, rqa_title, rqa_begin_date, rqa_status)
               VALUES (?, ?, ?, ?, ?, ?)',
            array('eeeeeeee-0000-0000-0000-000000000001', (int) $gCurrentOrgId, $stranger,
                'Left the organisation', '2026-03-01', 'submitted')
        );

        $this->assertTrue($service->isKnownMember($stranger));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed>
     */
    private function roster(RequirementType $type, string $status = ''): array
    {
        return (new FulfilmentService($this->getDatabase()))->coverageForMembers(
            (int) $type->getValue('rqt_id'),
            self::PERIOD,
            $status
        );
    }

    /**
     * @param array<string,mixed> $roster
     * @return array<string,mixed>
     */
    private function memberRow(array $roster, int $userId): array
    {
        $row = $this->findMemberRow($roster, $userId);
        if ($row === null) {
            $this->fail('No roster row for user ' . $userId . '.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $roster
     * @return array<string,mixed>|null
     */
    private function findMemberRow(array $roster, int $userId): ?array
    {
        foreach ($roster['members'] as $member) {
            if ((int) $member['userId'] === $userId) {
                return $member;
            }
        }

        return null;
    }

    /**
     * An activity of another organisation, crediting this organisation's requirement - the shape the
     * organisation filter of the candidate query exists for.
     */
    private function insertForeignCredit(int $userId, int $requirementTypeId, string $periodReference): void
    {
        $db = $this->getDatabase();
        $otherOrgId = (int) $this->fixture->createAndSaveOrganization(
            'Foreign Activity Organisation',
            'FRGACT'
        )['org_id'];

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activities()
            . ' (rqa_uuid, rqa_org_id, rqa_user_id, rqa_title, rqa_begin_date, rqa_status)
               VALUES (?, ?, ?, ?, ?, ?)',
            array('dddddddd-0000-0000-0000-000000000001', $otherOrgId, $userId,
                'Foreign activity', $periodReference, 'submitted')
        );
        $activityId = (int) $db->queryPrepared(
            'SELECT rqa_id FROM ' . RequirementsTables::activities() . ' WHERE rqa_uuid = ?',
            array('dddddddd-0000-0000-0000-000000000001')
        )->fetchColumn();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::activityCredits()
            . ' (rac_uuid, rac_activity_id, rac_requirement_type_id, rac_period_reference, rac_credits_claimed)
               VALUES (?, ?, ?, ?, ?)',
            array('dddddddd-0000-0000-0000-000000000002', $activityId, $requirementTypeId,
                $periodReference, '5.00')
        );
    }
}
