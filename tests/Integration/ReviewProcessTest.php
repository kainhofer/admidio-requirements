<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Entity\Review;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Service\ReviewListService;
use AdmidioPlugin\Requirements\Service\ReviewService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;
use DateTimeImmutable;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The review process (concept of 2026-11-20): the review mode of a requirement, the reviewers, and
 * the use cases from the member's and the reviewer's side.
 *
 * The current user of a test is an administrator. Unless a test says otherwise they are also the one
 * configured reviewer, which is the setting the use cases are easiest to follow in.
 */
final class ReviewProcessTest extends RequirementsDatabaseTestCase
{
    private const DAY = '2026-03-01';

    private function reviewerId(): int
    {
        return (int) $GLOBALS['gCurrentUser']->getValue('usr_id');
    }

    private function reviews(): ReviewService
    {
        return new ReviewService($this->getDatabase());
    }

    private function requirement(int $roleId, string $mode, array $typeValues = array()): RequirementType
    {
        return $this->createRequirement('CPD', $roleId, 10.0, array(), array_merge(array('rqt_review_mode' => $mode), $typeValues));
    }

    private function creditOf(Activity $activity): ActivityCredit
    {
        $id = (int) $this->getDatabase()->queryPrepared(
            'SELECT rac_id FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_activity_id = ? ORDER BY rac_id',
            array((int) $activity->getValue('rqa_id'))
        )->fetchColumn();
        return new ActivityCredit($this->getDatabase(), $id);
    }

    /** The review status of the first credit of an activity, as the database has it. */
    private function statusOf(Activity $activity): ?string
    {
        $status = $this->getDatabase()->queryPrepared(
            'SELECT rac_review_status FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_activity_id = ? ORDER BY rac_id',
            array((int) $activity->getValue('rqa_id'))
        )->fetchColumn();
        return $status === false ? null : $status;
    }

    /** @return array{attained:float,pending:float} */
    private function coverage(int $userId, RequirementType $type, string $day = self::DAY): array
    {
        $row = (new FulfilmentService($this->getDatabase()))->memberPeriodCoverage($type, $userId, new DateTimeImmutable($day));
        return array('attained' => (float) $row['attained'], 'pending' => (float) $row['pending']);
    }

    private function decide(int $creditId, ?float $accepted, string $status = '', string $comment = ''): array
    {
        return $this->reviews()->saveDecisions(array($creditId => array('accepted' => $accepted, 'status' => $status, 'comment' => $comment)));
    }

    private function reload(Activity $activity): Activity
    {
        return new Activity($this->getDatabase(), (int) $activity->getValue('rqa_id'));
    }

    // ---------------------------------------------------------------- settings and modes

    public function testWithoutReviewsEveryCreditCountsAsClaimed(): void
    {
        $member = $this->createMember('req_rp_off');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);

        $this->assertSame(ReviewService::MODE_NONE, $this->reviews()->effectiveMode((int) $type->getValue('rqt_id')));
        $this->assertSame(8.0, $this->coverage($member['usr_id'], $type)['attained']);
    }

    public function testAPartIsReviewedTheWayItsParentIs(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_part');
        $parent = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $part = $this->createRequirement('Thereof', $member['rol_id'], 2.0, array(), array(
            'rqt_parent_id' => (int) $parent->getValue('rqt_id'),
            'rqt_review_mode' => ReviewService::MODE_OPTIONAL
        ));

        $this->assertSame(ReviewService::MODE_NONE, (new RequirementType($this->getDatabase(), (int) $part->getValue('rqt_id')))->getValue('rqt_review_mode'),
            'A part keeps no mode of its own.');
        $this->assertSame(ReviewService::MODE_APPROVAL_QUEUE, $this->reviews()->effectiveMode((int) $part->getValue('rqt_id')));
    }

    public function testPeriodsAndAuditsNeedCalendarPeriods(): void
    {
        $member = $this->createMember('req_rp_calendar');
        $this->assertRefusedWith('PLG_REQUIREMENTS_REVIEW_MODE_NEEDS_CALENDAR', fn() => $this->requirement(
            $member['rol_id'], ReviewService::MODE_PERIOD, array('rqt_period_anchor' => 'last_fulfilment')
        ));
    }

    public function testAnAdministratorIsNoReviewerByBeingAnAdministrator(): void
    {
        $this->enableReviews(array());
        $this->assertFalse($this->reviews()->isConfiguredReviewer());

        $member = $this->createMember('req_rp_role_reviewer');
        $this->enableReviews(array(), array($member['rol_id']));
        $this->assertTrue($this->reviews()->isConfiguredReviewer($member['usr_id']), 'A member of a reviewer role reviews.');
    }

    // ---------------------------------------------------------------- A.b: every activity is reviewed

    public function testInTheQueueModeACreditCountsOnceAccepted(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_queue');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $credit = $this->creditOf($activity);

        $this->assertSame(ReviewService::CREDIT_OPEN, $credit->getValue('rac_review_status'), 'A submitted credit joins the queue.');
        $this->assertSame(array('attained' => 0.0, 'pending' => 8.0), $this->coverage($member['usr_id'], $type));

        $this->decide((int) $credit->getValue('rac_id'), null, ReviewService::CREDIT_ACCEPTED);
        $this->assertSame(array('attained' => 8.0, 'pending' => 0.0), $this->coverage($member['usr_id'], $type),
            'Accepting without an amount accepts the claim.');
    }

    public function testADraftJoinsTheQueueWhenItIsSubmitted(): void
    {
        $this->enableReviews(array($this->reviewerId()), array(), array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));
        $member = $this->createMember('req_rp_draft');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $this->assertNull($this->statusOf($activity));

        (new ActivityService($this->getDatabase()))->submit($this->reload($activity));
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->creditOf($activity)->getValue('rac_review_status'));
    }

    /**
     * A reviewer may record the decisions on their own activities: in some associations a meeting
     * decides, and one reviewer records it.
     */
    public function testAReviewerMayRecordTheDecisionOnTheirOwnActivity(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_clerk');
        $this->fixture->assignUserToRolePeriod($this->reviewerId(), $member['rol_id'], '1990-01-01', '9999-12-31');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $activity = $this->createCreditedActivity($this->reviewerId(), $type, self::DAY, 4.0);

        $this->assertCount(1, $this->decide((int) $this->creditOf($activity)->getValue('rac_id'), 4.0));
    }

    // ---------------------------------------------------------------- A.a: approval on submission

    public function testASubmittedActivityCountsOnceTheReviewerDecided(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_submit');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_ON_SUBMISSION);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $creditId = (int) $this->creditOf($activity)->getValue('rac_id');

        $this->assertNull($this->statusOf($activity), 'Nothing is reviewed before it is submitted.');
        $this->assertFalse($this->reviews()->mayDecideCredit($creditId), 'The reviewer sees nothing before the submission.');

        $review = $this->asMember($member['usr_id'], fn(): Review => $this->reviews()->submitActivity($this->reload($activity), 0, 0, 'Please check'));
        $this->assertSame(Review::STATUS_OPEN, $review->getValue('rrv_status'));
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->creditOf($activity)->getValue('rac_review_status'));
        $this->assertSame(0.0, $this->coverage($member['usr_id'], $type)['attained']);

        $this->decide($creditId, 5.0, '', 'Only the morning counts');
        $this->assertSame(5.0, $this->coverage($member['usr_id'], $type)['attained']);

        $this->reviews()->finish(new Review($this->getDatabase(), (int) $review->getValue('rrv_id')), 'Done');
        $this->assertSame(Review::STATUS_FINISHED, (new Review($this->getDatabase(), (int) $review->getValue('rrv_id')))->getValue('rrv_status'));
    }

    public function testAPeriodSubmissionCoversTheCreditsOfThePeriodAndTheirParts(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_period_parts');
        $parent = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_ON_SUBMISSION);
        $part = $this->createRequirement('Thereof', $member['rol_id'], 2.0, array(), array('rqt_parent_id' => (int) $parent->getValue('rqt_id')));
        $activity = $this->createCreditedActivity($member['usr_id'], $parent, self::DAY, 8.0);
        (new ActivityService($this->getDatabase()))->saveCredit(new ActivityCredit($this->getDatabase()), $activity, $part, self::DAY, 3.0);
        $other = $this->createCreditedActivity($member['usr_id'], $parent, '2025-03-01', 4.0);

        $review = $this->asMember($member['usr_id'], fn(): Review => $this->reviews()->submitPeriod($parent, self::DAY));

        $this->assertSame('2026-01-01', $review->getValue('rrv_period_reference', 'database'));
        $this->assertCount(2, $this->reviews()->creditsOfReview($review), 'The credit and its part, not the other year.');
        $this->assertNull($this->statusOf($other));
    }

    public function testWithdrawingASubmissionLeavesItWaitingForTheNextOne(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_withdraw');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_ON_SUBMISSION);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);

        $this->asMember($member['usr_id'], function () use ($activity): void {
            $review = $this->reviews()->submitActivity($this->reload($activity));
            $this->reviews()->withdraw($review);
            $this->assertSame(Review::STATUS_WITHDRAWN, $review->getValue('rrv_status'));
        });
        $this->assertNull($this->statusOf($activity));
    }

    // ---------------------------------------------------------------- B: optional review

    public function testAnOptionalReviewChangesWhatCountsOnlyWithTheDecision(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_optional');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_OPTIONAL);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);

        $this->assertSame(8.0, $this->coverage($member['usr_id'], $type)['attained'], 'Counts as claimed.');
        $this->asMember($member['usr_id'], fn() => $this->reviews()->submitActivity($this->reload($activity)));
        $this->assertSame(8.0, $this->coverage($member['usr_id'], $type)['attained'], 'Still counts while under review.');

        $this->decide((int) $this->creditOf($activity)->getValue('rac_id'), 6.0);
        $this->assertSame(6.0, $this->coverage($member['usr_id'], $type)['attained']);
    }

    // ---------------------------------------------------------------- C: submission after each period

    public function testAMemberIsAskedToSubmitTheLastPeriodUntilTheyDo(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_period');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_PERIOD, array('rqt_review_due_days' => 60));
        $lastYear = (new DateTimeImmutable(DATE_NOW))->modify('-1 year')->format('Y') . '-01-01';

        $due = $this->asMember($member['usr_id'], fn(): array => $this->reviews()->memberAgenda($member['usr_id'])['due']);
        $this->assertCount(1, $due);
        $this->assertSame($lastYear, $due[0]['periodStart']);
        $this->assertSame((new DateTimeImmutable(DATE_NOW))->modify('-1 year')->format('Y') . '-12-31',
            (new DateTimeImmutable($due[0]['dueDate']))->modify('-60 days')->format('Y-m-d'));

        $keys = (new ReviewListService($this->getDatabase(), $this->reviews()))->keysFor(ReviewListService::FILTER_NOT_SUBMITTED, (int) $type->getValue('rqt_id'), '');
        $this->assertContains(ReviewListService::key($member['usr_id'], (int) $type->getValue('rqt_id'), $lastYear), $keys);

        // Nothing earned is still a submission: the submission is the obligation.
        $this->asMember($member['usr_id'], fn() => $this->reviews()->submitPeriod($type, $lastYear));
        $this->assertSame(array(), $this->asMember($member['usr_id'], fn(): array => $this->reviews()->memberAgenda($member['usr_id'])['due']));
        $this->assertNotContains(
            ReviewListService::key($member['usr_id'], (int) $type->getValue('rqt_id'), $lastYear),
            (new ReviewListService($this->getDatabase(), $this->reviews()))->keysFor(ReviewListService::FILTER_NOT_SUBMITTED, (int) $type->getValue('rqt_id'), '')
        );
    }

    // ---------------------------------------------------------------- D: audits

    public function testARequestedReviewIsSeenOnlyOnceTheMemberSharedIt(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_audit');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_AUDIT);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2025-05-01', 8.0);
        $creditId = (int) $this->creditOf($activity)->getValue('rac_id');

        $created = $this->reviews()->requestReviews($type, array('2025-05-01'), array($member['usr_id']), '2026-12-31', 'Random audit');
        $this->assertCount(1, $created);
        $this->assertSame(Review::STATUS_REQUESTED, $created[0]->getValue('rrv_status'));
        $this->assertSame(array(), $this->reviews()->requestReviews($type, array('2025-12-31'), array($member['usr_id'])),
            'A member already asked for the period is not asked twice.');
        $this->assertFalse($this->reviews()->mayDecideCredit($creditId), 'Nothing is seen before the member shares it.');

        $this->asMember($member['usr_id'], fn() => $this->reviews()->share(new Review($this->getDatabase(), (int) $created[0]->getValue('rrv_id'))));

        $this->assertTrue($this->reviews()->mayDecideCredit($creditId));
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->creditOf($activity)->getValue('rac_review_status'));
        $this->assertSame(8.0, $this->coverage($member['usr_id'], $type, '2025-05-01')['attained'], 'An audit counts as claimed until decided.');
    }

    public function testOnlyAConfiguredReviewerRequestsReviews(): void
    {
        $this->enableReviews(array());
        $member = $this->createMember('req_rp_audit_rights');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_AUDIT);

        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $this->reviews()->requestReviews($type, array('2025-05-01'), array($member['usr_id'])));
    }

    // ---------------------------------------------------------------- peer review

    public function testAPeerReviewerSeesWhatTheyReviewAndNothingElse(): void
    {
        $this->enableReviews(array(), array(), array(RequirementsPlugin::SETTING_REVIEW_REVIEWER_CHOICE => ReviewService::CHOICE_ANY));
        $member = $this->createMember('req_rp_peer_member');
        $peer = $this->createMember('req_rp_peer_reviewer');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_OPTIONAL);
        $submitted = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $notSubmitted = $this->createCreditedActivity($member['usr_id'], $type, '2026-04-01', 3.0);

        $this->asMember($member['usr_id'], fn() => $this->reviews()->submitActivity($this->reload($submitted), $peer['usr_id']));

        $this->asMember($peer['usr_id'], function () use ($submitted, $notSubmitted): void {
            $this->assertTrue($this->reload($submitted)->isVisible());
            $this->assertFalse($this->reload($submitted)->isEditable(), 'A reviewer does not change the claim.');
            $this->assertFalse($this->reload($notSubmitted)->isVisible());
            $this->assertCount(1, $this->decide((int) $this->creditOf($submitted)->getValue('rac_id'), 8.0));
        });
    }

    public function testAMemberIsNotTheirOwnReviewer(): void
    {
        $this->enableReviews(array(), array(), array(RequirementsPlugin::SETTING_REVIEW_REVIEWER_CHOICE => ReviewService::CHOICE_ANY));
        $member = $this->createMember('req_rp_peer_self');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_OPTIONAL);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);

        $this->assertRefusedWith('PLG_REQUIREMENTS_REVIEW_NOT_OWN_REVIEWER', fn() => $this->asMember(
            $member['usr_id'],
            fn() => $this->reviews()->submitActivity($this->reload($activity), $member['usr_id'])
        ));
    }

    public function testOnlyAConfiguredReviewerMayBePickedWhenTheSettingsSaySo(): void
    {
        $this->enableReviews(array($this->reviewerId()), array(), array(
            RequirementsPlugin::SETTING_REVIEW_REVIEWER_CHOICE => ReviewService::CHOICE_PICK_CONFIGURED
        ));
        $member = $this->createMember('req_rp_pick');
        $other = $this->createMember('req_rp_pick_other');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_OPTIONAL);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);

        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $this->asMember(
            $member['usr_id'],
            fn() => $this->reviews()->submitActivity($this->reload($activity), $other['usr_id'])
        ));
        $reviewerId = $this->reviewerId();
        $review = $this->asMember($member['usr_id'], fn(): Review => $this->reviews()->submitActivity($this->reload($activity), $reviewerId));
        $this->assertSame($reviewerId, (int) $review->getValue('rrv_reviewer_user_id'));
    }

    // ---------------------------------------------------------------- clarification and locks

    public function testAClarificationNeedsAQuestionAndUnlocksTheActivity(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_clarify');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_ON_SUBMISSION);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $creditId = (int) $this->creditOf($activity)->getValue('rac_id');
        $review = $this->asMember($member['usr_id'], fn(): Review => $this->reviews()->submitActivity($this->reload($activity)));

        $this->assertTrue($this->asMember($member['usr_id'], fn(): bool => !$this->reload($activity)->isEditable()),
            'Locked while waiting for a decision.');
        $this->assertRefusedWith('PLG_REQUIREMENTS_REVIEW_CLARIFICATION_NEEDS_COMMENT',
            fn() => $this->decide($creditId, null, ReviewService::CREDIT_CLARIFICATION));

        $this->decide($creditId, null, ReviewService::CREDIT_CLARIFICATION, 'Where is the certificate?');
        $this->assertSame(Review::STATUS_CLARIFICATION, (new Review($this->getDatabase(), (int) $review->getValue('rrv_id')))->getValue('rrv_status'));
        $this->assertTrue($this->asMember($member['usr_id'], fn(): bool => $this->reload($activity)->isEditable()));

        $this->asMember($member['usr_id'], fn() => $this->reviews()->resubmit($this->reload($activity), 'Attached'));
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->creditOf($activity)->getValue('rac_review_status'));
        $this->assertSame(Review::STATUS_OPEN, (new Review($this->getDatabase(), (int) $review->getValue('rrv_id')))->getValue('rrv_status'));
    }

    public function testEditingDuringAReviewIsMarkedWhenAllowed(): void
    {
        $this->enableReviews(array($this->reviewerId()), array(), array(RequirementsPlugin::SETTING_REVIEW_EDIT_DURING => ReviewService::EDIT_ALLOWED));
        $member = $this->createMember('req_rp_edit_during');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);

        $this->asMember($member['usr_id'], function () use ($activity): void {
            $this->assertTrue($this->reload($activity)->isEditable());
            $this->assertFalse($this->reload($activity)->isDeletable(), 'An activity under review is not deleted by its member.');
            (new ActivityService($this->getDatabase()))->save($this->reload($activity), array('rqa_title' => 'Renamed'));
        });
        $this->assertNotNull($this->creditOf($activity)->getValue('rac_review_changed', 'database'));
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->creditOf($activity)->getValue('rac_review_status'));
    }

    public function testADecisionStaysWhenTheMemberChangesTheActivity(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_edit_after');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_OPTIONAL);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $this->asMember($member['usr_id'], fn() => $this->reviews()->submitActivity($this->reload($activity)));
        $this->decide((int) $this->creditOf($activity)->getValue('rac_id'), 6.0);

        $this->asMember($member['usr_id'], function () use ($activity, $type): void {
            $this->assertTrue($this->reload($activity)->isEditable(), 'Editing after a decision is allowed by default.');
            $credit = $this->creditOf($activity);
            (new ActivityService($this->getDatabase()))->saveCredit($credit, $this->reload($activity), $type, self::DAY, 9.0);
        });

        $credit = $this->creditOf($activity);
        $this->assertSame(ReviewService::CREDIT_PARTIALLY_ACCEPTED, $credit->getValue('rac_review_status'));
        $this->assertSame(6.0, (float) $credit->getValue('rac_credits_accepted', 'database'));
        $this->assertNotNull($credit->getValue('rac_review_changed', 'database'), 'The reviewer is shown that it changed.');
        $this->assertSame(6.0, $this->coverage($member['usr_id'], $type)['attained']);

        $this->enableReviews(array($this->reviewerId()), array(), array(RequirementsPlugin::SETTING_REVIEW_EDIT_AFTER => ReviewService::EDIT_LOCKED));
        $this->asMember($member['usr_id'], function () use ($activity): void {
            $this->assertFalse($this->reload($activity)->isEditable());
            $this->assertTrue($this->reload($activity)->mayAddFiles(), 'Certificates can always be added.');
        });
    }

    public function testAReReviewKeepsTheEarlierDecisionCountingUntilTheNextOne(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_rereview');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $this->decide((int) $this->creditOf($activity)->getValue('rac_id'), 5.0);

        $this->assertRefusedWith('SYS_FIELD_EMPTY', fn() => $this->asMember(
            $member['usr_id'], fn() => $this->reviews()->requestReReview($this->reload($activity), ' ')
        ), array('PLG_REQUIREMENTS_REVIEW_REASON'));

        $this->asMember($member['usr_id'], fn() => $this->reviews()->requestReReview($this->reload($activity), 'The whole day counts'));
        $this->assertSame(ReviewService::CREDIT_CLARIFICATION, $this->creditOf($activity)->getValue('rac_review_status'));
        $this->assertSame(5.0, $this->coverage($member['usr_id'], $type)['attained']);

        // As often as the member wants.
        $this->asMember($member['usr_id'], fn() => $this->reviews()->resubmit($this->reload($activity)));
        $this->decide((int) $this->creditOf($activity)->getValue('rac_id'), 8.0);
        $this->asMember($member['usr_id'], fn() => $this->reviews()->requestReReview($this->reload($activity), 'Once more'));
        $this->assertSame(8.0, $this->coverage($member['usr_id'], $type)['attained']);
    }

    /**
     * A decided period can be submitted again: the member has further arguments. That needs the
     * arguments, and the earlier decision counts until the reviewer decides anew.
     */
    public function testADecidedPeriodIsSubmittedAgainWithReasons(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_period_again');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_ON_SUBMISSION);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, self::DAY, 8.0);
        $first = $this->asMember($member['usr_id'], fn(): Review => $this->reviews()->submitPeriod($type, self::DAY));
        $this->decide((int) $this->creditOf($activity)->getValue('rac_id'), 5.0);
        $this->reviews()->finish(new Review($this->getDatabase(), (int) $first->getValue('rrv_id')));

        $this->assertRefusedWith('PLG_REQUIREMENTS_REVIEW_AGAIN_NEEDS_REASON', fn() => $this->asMember(
            $member['usr_id'], fn() => $this->reviews()->submitPeriod($type, self::DAY)
        ));

        $again = $this->asMember($member['usr_id'], fn(): Review => $this->reviews()->submitPeriod(
            $type, self::DAY, 0, 0, 'The afternoon was part of the course as well.'
        ));
        $this->assertNotSame((int) $first->getValue('rrv_id'), (int) $again->getValue('rrv_id'), 'A new review.');
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->statusOf($activity));
        $this->assertSame(5.0, $this->coverage($member['usr_id'], $type)['attained'], 'The earlier decision still counts.');
    }

    /**
     * A reviewer calls off a request the member has not shared yet - and one that is under way: the
     * credits waiting for a decision wait for a submission again, decisions stay.
     */
    public function testAReviewerCancelsAReview(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_cancel');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_AUDIT);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2025-05-01', 8.0);

        [$requested] = $this->reviews()->requestReviews($type, array('2025-05-01'), array($member['usr_id']));
        $this->reviews()->cancel($requested);
        $this->assertSame(Review::STATUS_WITHDRAWN, (new Review($this->getDatabase(), (int) $requested->getValue('rrv_id')))->getValue('rrv_status'));

        [$shared] = $this->reviews()->requestReviews($type, array('2025-05-01'), array($member['usr_id']));
        $this->asMember($member['usr_id'], fn() => $this->reviews()->share(new Review($this->getDatabase(), (int) $shared->getValue('rrv_id'))));
        $this->assertSame(ReviewService::CREDIT_OPEN, $this->statusOf($activity));
        $this->reviews()->cancel(new Review($this->getDatabase(), (int) $shared->getValue('rrv_id')));
        $this->assertNull($this->statusOf($activity));

        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $this->asMember(
            $member['usr_id'], fn() => $this->reviews()->cancel(new Review($this->getDatabase(), (int) $shared->getValue('rrv_id')))
        ));
    }

    /**
     * Withdrawing a submission does not take a credit of the queue mode out of the queue, where it
     * is without any review.
     */
    public function testAQueueCreditStaysInTheQueueWhenAReviewEnds(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_queue_cancel');
        $type = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $activity = $this->createCreditedActivity($member['usr_id'], $type, '2025-05-01', 8.0);

        [$review] = $this->reviews()->requestReviews($type, array('2025-05-01'), array($member['usr_id']));
        $this->asMember($member['usr_id'], fn() => $this->reviews()->share(new Review($this->getDatabase(), (int) $review->getValue('rrv_id'))));
        $this->reviews()->cancel(new Review($this->getDatabase(), (int) $review->getValue('rrv_id')));

        $this->assertSame(ReviewService::CREDIT_OPEN, $this->statusOf($activity));
    }

    // ---------------------------------------------------------------- the review page

    public function testNeedsReviewListsTheQueueAndTheSubmissions(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_list');
        $queue = $this->requirement($member['rol_id'], ReviewService::MODE_APPROVAL_QUEUE);
        $optional = $this->requirement($member['rol_id'], ReviewService::MODE_OPTIONAL);
        $this->createCreditedActivity($member['usr_id'], $queue, self::DAY, 8.0);
        $submitted = $this->createCreditedActivity($member['usr_id'], $optional, self::DAY, 3.0);
        $this->createCreditedActivity($member['usr_id'], $optional, '2025-03-01', 2.0);
        $this->asMember($member['usr_id'], fn() => $this->reviews()->submitActivity($this->reload($submitted)));

        $keys = (new ReviewListService($this->getDatabase(), $this->reviews()))->keysFor(ReviewListService::FILTER_NEEDS_REVIEW, 0, '');

        $this->assertContains(ReviewListService::key($member['usr_id'], (int) $queue->getValue('rqt_id'), '2026-01-01'), $keys);
        $this->assertContains(ReviewListService::key($member['usr_id'], (int) $optional->getValue('rqt_id'), '2026-01-01'), $keys);
        $this->assertNotContains(ReviewListService::key($member['usr_id'], (int) $optional->getValue('rqt_id'), '2025-01-01'), $keys,
            'A period nobody submitted is not under review.');
    }

    public function testAMembersOwnRequirementIsNeverReviewed(): void
    {
        $this->enableReviews(array($this->reviewerId()));
        $member = $this->createMember('req_rp_own');
        $own = $this->asMember($member['usr_id'], fn(): RequirementType => (new RequirementTypeService($this->getDatabase()))->saveOwnType(
            new RequirementType($this->getDatabase()),
            array('rqt_name' => 'Employer', 'rqt_period_unit' => 'year', 'rqt_review_mode' => ReviewService::MODE_APPROVAL_QUEUE,
                'rrr_required_credits' => 5.0, 'rrr_partial_period_mode' => 'full', 'rrr_valid_from' => '2026-01-01')
        ));

        $this->assertSame(ReviewService::MODE_NONE, $this->reviews()->effectiveMode((int) $own->getValue('rqt_id')));
    }
}
