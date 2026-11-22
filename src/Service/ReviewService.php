<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Entity\Review;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use DateTimeImmutable;
use Throwable;

/**
 * The review process: who reviews, what a requirement's review mode means, which credits a review
 * covers, and the actions of the member and of the reviewer.
 *
 * Three things decide how a review works. The global settings say whether reviews exist at all, who
 * reviews and how a member may pick a reviewer. The review mode of a requirement says whether its
 * credits count only once accepted and who starts a review. The decision itself is stored on each
 * credit: its review status, the accepted amount and a comment.
 *
 * A review (req_reviews) is one member, one requirement and one credited period, or one activity of
 * the member. It covers the credits of the member's submitted activities in that scope; mode
 * `approval_queue` needs no review rows at all, every submitted credit of it is in the reviewers'
 * queue as soon as it exists.
 */
final class ReviewService
{
    public const MODE_NONE = 'none';
    public const MODE_OPTIONAL = 'optional';
    public const MODE_APPROVAL_ON_SUBMISSION = 'approval_on_submission';
    public const MODE_APPROVAL_QUEUE = 'approval_queue';
    public const MODE_PERIOD = 'period';
    public const MODE_AUDIT = 'audit';

    public const MODES = array(
        self::MODE_NONE,
        self::MODE_OPTIONAL,
        self::MODE_APPROVAL_ON_SUBMISSION,
        self::MODE_APPROVAL_QUEUE,
        self::MODE_PERIOD,
        self::MODE_AUDIT
    );

    /** Modes in which a credit counts only once a reviewer accepted it. */
    public const APPROVAL_MODES = array(self::MODE_APPROVAL_ON_SUBMISSION, self::MODE_APPROVAL_QUEUE);

    /** Modes in which a member submits a single activity. */
    public const ACTIVITY_SUBMISSION_MODES = array(self::MODE_OPTIONAL, self::MODE_APPROVAL_ON_SUBMISSION);

    /** Modes in which a member submits a whole credited period. */
    public const PERIOD_SUBMISSION_MODES = array(self::MODE_OPTIONAL, self::MODE_APPROVAL_ON_SUBMISSION, self::MODE_PERIOD);

    public const CREDIT_OPEN = 'open';
    public const CREDIT_ACCEPTED = 'accepted';
    public const CREDIT_PARTIALLY_ACCEPTED = 'partially_accepted';
    public const CREDIT_NOT_ACCEPTED = 'not_accepted';
    public const CREDIT_CLARIFICATION = 'clarification';

    public const CREDIT_STATUSES = array(
        self::CREDIT_OPEN,
        self::CREDIT_ACCEPTED,
        self::CREDIT_PARTIALLY_ACCEPTED,
        self::CREDIT_NOT_ACCEPTED,
        self::CREDIT_CLARIFICATION
    );

    /** The statuses of a credit a reviewer has decided on. */
    public const DECIDED_STATUSES = array(
        self::CREDIT_ACCEPTED,
        self::CREDIT_PARTIALLY_ACCEPTED,
        self::CREDIT_NOT_ACCEPTED
    );

    public const CHOICE_CONFIGURED = 'configured';
    public const CHOICE_PICK_CONFIGURED = 'pick_configured';
    public const CHOICE_ANY = 'any';

    public const EDIT_LOCKED = 'locked';
    public const EDIT_ALLOWED = 'allowed';

    /** @var array<string,bool> memberships answered in this request, by "user|role" */
    private static array $membershipCache = array();

    /** @var array<int,string> effective review modes answered in this request, by requirement id */
    private static array $modeCache = array();

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Forget what this request has learnt about memberships and modes. Tests change both between
     * two questions; a page never does.
     */
    public static function resetCaches(): void
    {
        self::$membershipCache = array();
        self::$modeCache = array();
    }

    // ---------------------------------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------------------------------

    public static function isEnabled(): bool
    {
        return (bool) RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_ENABLED);
    }

    /** @return array<int,int> */
    public static function reviewerRoleIds(): array
    {
        $value = RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_ROLES);
        $ids = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** @return array<int,int> */
    public static function reviewerUserIds(): array
    {
        $value = RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_USERS);
        $ids = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public static function reviewerChoice(): string
    {
        $choice = (string) RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_REVIEWER_CHOICE);
        return in_array($choice, array(self::CHOICE_PICK_CONFIGURED, self::CHOICE_ANY), true)
            ? $choice : self::CHOICE_CONFIGURED;
    }

    public static function editDuringReview(): string
    {
        return (string) RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_EDIT_DURING) === self::EDIT_ALLOWED
            ? self::EDIT_ALLOWED : self::EDIT_LOCKED;
    }

    public static function editAfterDecision(): string
    {
        return (string) RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_EDIT_AFTER) === self::EDIT_LOCKED
            ? self::EDIT_LOCKED : self::EDIT_ALLOWED;
    }

    public static function notificationsEnabled(): bool
    {
        $value = RequirementsPlugin::setting(RequirementsPlugin::SETTING_REVIEW_NOTIFICATIONS);
        return $value === null || (bool) $value;
    }

    // ---------------------------------------------------------------------------------------------
    // Review modes
    // ---------------------------------------------------------------------------------------------

    /**
     * The review mode a requirement works in: its own, or that of its parent for a part, so that a
     * parent and its parts always count the same way. A member's own requirement is never reviewed,
     * and without reviews every requirement is `none`.
     */
    public function effectiveMode(int $requirementTypeId): string
    {
        if (!self::isEnabled() || $requirementTypeId <= 0) {
            return self::MODE_NONE;
        }
        if (isset(self::$modeCache[$requirementTypeId])) {
            return self::$modeCache[$requirementTypeId];
        }

        $row = $this->db->queryPrepared(
            'SELECT rqt.rqt_usr_id, rqt.rqt_review_mode, rpt.rqt_review_mode AS parent_mode, rpt.rqt_usr_id AS parent_owner,
                    rqt.rqt_parent_id
               FROM ' . RequirementsTables::requirementTypes() . ' AS rqt
          LEFT JOIN ' . RequirementsTables::requirementTypes() . ' AS rpt ON rpt.rqt_id = rqt.rqt_parent_id
              WHERE rqt.rqt_id = ?',
            array($requirementTypeId)
        )->fetch();

        $mode = self::MODE_NONE;
        if ($row && (int) $row['rqt_usr_id'] === 0) {
            $mode = (int) $row['rqt_parent_id'] > 0
                ? ((int) $row['parent_owner'] === 0 ? (string) $row['parent_mode'] : self::MODE_NONE)
                : (string) $row['rqt_review_mode'];
        }
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_NONE;
        }

        return self::$modeCache[$requirementTypeId] = $mode;
    }

    public static function isApprovalMode(string $mode): bool
    {
        return in_array($mode, self::APPROVAL_MODES, true);
    }

    /**
     * Whether a credit in this mode counts towards fulfilment at all. In an approval mode it counts
     * once a reviewer gave it an accepted amount - also while a new review of it is under way, so
     * that the earlier decision counts until the reviewer decides again. In every other mode it counts
     * as claimed until a reviewer changes it.
     */
    public static function creditCounts(string $mode, mixed $accepted): bool
    {
        return !self::isApprovalMode($mode) || ($accepted !== null && $accepted !== '');
    }

    // ---------------------------------------------------------------------------------------------
    // Reviewers
    // ---------------------------------------------------------------------------------------------

    public static function currentUserId(): int
    {
        return isset($GLOBALS['gCurrentUser']) ? (int) $GLOBALS['gCurrentUser']->getValue('usr_id') : 0;
    }

    /**
     * Whether somebody is one of the configured reviewers: named in the setting, or a current member
     * of one of its roles. Administrators of the plugin are not reviewers by being administrators.
     */
    public function isConfiguredReviewer(?int $userId = null): bool
    {
        $userId ??= self::currentUserId();
        if ($userId <= 0 || !self::isEnabled()) {
            return false;
        }
        if (in_array($userId, self::reviewerUserIds(), true)) {
            return true;
        }
        foreach (self::reviewerRoleIds() as $roleId) {
            if ($this->isMemberOfRole($userId, $roleId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether somebody reviews anything at all: a configured reviewer, or the reviewer named in a
     * review that is not over.
     */
    public function isAnyReviewer(?int $userId = null): bool
    {
        global $gCurrentOrgId;

        $userId ??= self::currentUserId();
        if ($this->isConfiguredReviewer($userId)) {
            return true;
        }
        if ($userId <= 0 || !self::isEnabled()) {
            return false;
        }

        $rows = $this->db->getArrayFromSql(
            'SELECT rrv_reviewer_user_id, rrv_reviewer_role_id
               FROM ' . RequirementsTables::reviews() . '
              WHERE rrv_org_id = ?
                AND rrv_status IN (?, ?, ?)
                AND (rrv_reviewer_user_id IS NOT NULL OR rrv_reviewer_role_id IS NOT NULL)',
            array((int) $gCurrentOrgId, Review::STATUS_REQUESTED, Review::STATUS_OPEN, Review::STATUS_CLARIFICATION)
        );
        foreach ($rows as $row) {
            if ($this->isNamedReviewer($row, $userId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a review names this member as its reviewer, directly or through a role.
     *
     * @param array<string,mixed> $review a row with rrv_reviewer_user_id and rrv_reviewer_role_id
     */
    public function isNamedReviewer(array $review, ?int $userId = null): bool
    {
        $userId ??= self::currentUserId();
        if ((int) ($review['rrv_reviewer_user_id'] ?? 0) > 0) {
            return (int) $review['rrv_reviewer_user_id'] === $userId;
        }
        if ((int) ($review['rrv_reviewer_role_id'] ?? 0) > 0) {
            return $this->isMemberOfRole($userId, (int) $review['rrv_reviewer_role_id']);
        }
        return false;
    }

    /**
     * Whether somebody handles this review: a configured reviewer, or the reviewer it names.
     *
     * @param array<string,mixed>|Review $review
     */
    public function mayHandleReview(array|Review $review, ?int $userId = null): bool
    {
        $userId ??= self::currentUserId();
        $row = $review instanceof Review ? self::rowOf($review) : $review;
        if (!self::isEnabled() || (string) $row['rrv_status'] === Review::STATUS_WITHDRAWN) {
            return false;
        }
        return $this->isConfiguredReviewer($userId) || $this->isNamedReviewer($row, $userId);
    }

    public function isMemberOfRole(int $userId, int $roleId): bool
    {
        global $gCurrentOrgId;

        $key = $userId . '|' . $roleId;
        if (!isset(self::$membershipCache[$key])) {
            self::$membershipCache[$key] = (int) $this->db->queryPrepared(
                'SELECT COUNT(*)
                   FROM ' . TBL_MEMBERS . ' AS mem
             INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id
             INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
                  WHERE mem.mem_usr_id = ?
                    AND mem.mem_rol_id = ?
                    AND mem.mem_begin <= ?
                    AND mem.mem_end >= ?
                    AND rol.rol_valid = true
                    AND (cat.cat_org_id = ? OR cat.cat_org_id IS NULL)',
                array($userId, $roleId, DATE_NOW, DATE_NOW, (int) $gCurrentOrgId)
            )->fetchColumn() > 0;
        }
        return self::$membershipCache[$key];
    }

    /**
     * The configured reviewers as members, for the reviewer choice of a member and for the mail.
     *
     * @return array<int,int> user ids
     */
    public function configuredReviewerIds(): array
    {
        $ids = self::reviewerUserIds();
        $roleIds = self::reviewerRoleIds();
        if ($roleIds !== array()) {
            $rows = $this->db->queryPrepared(
                'SELECT DISTINCT mem_usr_id
                   FROM ' . TBL_MEMBERS . '
                  WHERE mem_rol_id IN (' . implode(', ', array_fill(0, count($roleIds), '?')) . ')
                    AND mem_begin <= ?
                    AND mem_end >= ?',
                array_merge($roleIds, array(DATE_NOW, DATE_NOW))
            )->fetchAll(\PDO::FETCH_COLUMN);
            $ids = array_merge($ids, array_map('intval', $rows));
        }
        return array_values(array_unique($ids));
    }

    // ---------------------------------------------------------------------------------------------
    // Scope: which credits a review covers
    // ---------------------------------------------------------------------------------------------

    /**
     * The SQL condition that ties a credit (rac, its activity rqa, its requirement rqt and that
     * requirement's parent rpt) to a review rrv: the single activity, for the credits in the modes a
     * member submits activities in; or the requirement with its parts and the credited period.
     */
    private const SCOPE_CONDITION = '(
            (rrv.rrv_activity_id = rqa.rqa_id
             AND COALESCE(rpt.rqt_review_mode, rqt.rqt_review_mode) IN (\'optional\', \'approval_on_submission\'))
         OR (rrv.rrv_activity_id IS NULL
             AND COALESCE(rqt.rqt_parent_id, rqt.rqt_id) = rrv.rrv_requirement_type_id
             AND rac.rac_period_reference BETWEEN rrv.rrv_period_reference AND rrv.rrv_period_to)
        )';

    /** The joins from a credit rac to its activity, its requirement and that requirement's parent. */
    private static function creditJoins(): string
    {
        return ' INNER JOIN ' . RequirementsTables::activities() . ' AS rqa ON rqa.rqa_id = rac.rac_activity_id
                 INNER JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt ON rqt.rqt_id = rac.rac_requirement_type_id
                  LEFT JOIN ' . RequirementsTables::requirementTypes() . ' AS rpt ON rpt.rqt_id = rqt.rqt_parent_id ';
    }

    /**
     * The credits a review covers: those of the member's submitted activities in its scope, towards
     * the organization's requirements. A member's own requirement is never under review.
     *
     * @return array<int,array<string,mixed>>
     */
    public function creditsOfReview(Review|array $review): array
    {
        $row = $review instanceof Review ? self::rowOf($review) : $review;

        return $this->db->getArrayFromSql(
            'SELECT rac.*, rqa.rqa_uuid, rqa.rqa_title, rqa.rqa_user_id, rqa.rqa_begin_date, rqa.rqa_status,
                    rqt.rqt_name, rqt.rqt_short_name, rqt.rqt_parent_id
               FROM ' . RequirementsTables::activityCredits() . ' AS rac' . self::creditJoins() . '
         INNER JOIN ' . RequirementsTables::reviews() . ' AS rrv ON rrv.rrv_id = ?
              WHERE rqa.rqa_org_id = rrv.rrv_org_id
                AND rqa.rqa_user_id = rrv.rrv_user_id
                AND rqa.rqa_status = ?
                AND rqt.rqt_usr_id IS NULL
                AND ' . self::SCOPE_CONDITION . '
           ORDER BY rac.rac_period_reference, rqa.rqa_begin_date, rqa.rqa_title, rqt.rqt_sequence',
            array((int) $row['rrv_id'], 'submitted')
        );
    }

    /**
     * The reviews that cover any of these credits, by credit id.
     *
     * @param array<int,int> $creditIds
     * @param array<int,string> $statuses only reviews in these statuses; empty for all of them
     * @return array<int,array<int,array<string,mixed>>>
     */
    public function reviewsCoveringCredits(array $creditIds, array $statuses = array()): array
    {
        $creditIds = array_values(array_unique(array_filter(array_map('intval', $creditIds))));
        if ($creditIds === array()) {
            return array();
        }

        $params = $creditIds;
        $statusCondition = '';
        if ($statuses !== array()) {
            $statusCondition = ' AND rrv.rrv_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params = array_merge($params, $statuses);
        }

        $rows = $this->db->getArrayFromSql(
            'SELECT rac.rac_id AS covered_credit_id, rrv.*
               FROM ' . RequirementsTables::activityCredits() . ' AS rac' . self::creditJoins() . '
         INNER JOIN ' . RequirementsTables::reviews() . ' AS rrv
                 ON rrv.rrv_org_id = rqa.rqa_org_id AND rrv.rrv_user_id = rqa.rqa_user_id
              WHERE rac.rac_id IN (' . implode(', ', array_fill(0, count($creditIds), '?')) . ')
                AND rqt.rqt_usr_id IS NULL
                AND ' . self::SCOPE_CONDITION . $statusCondition . '
           ORDER BY rrv.rrv_id DESC',
            $params
        );

        $byCredit = array();
        foreach ($rows as $row) {
            $byCredit[(int) $row['covered_credit_id']][] = $row;
        }
        return $byCredit;
    }

    /**
     * The credits of one activity with their review status and the effective mode of their
     * requirement, for the lock and the access checks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function creditsOfActivity(int $activityId): array
    {
        $rows = $this->db->getArrayFromSql(
            'SELECT rac.rac_id, rac.rac_requirement_type_id, rac.rac_period_reference, rac.rac_review_status,
                    rac.rac_credits_claimed, rac.rac_credits_accepted, rqa.rqa_status, rqt.rqt_usr_id,
                    COALESCE(rpt.rqt_review_mode, rqt.rqt_review_mode) AS review_mode
               FROM ' . RequirementsTables::activityCredits() . ' AS rac' . self::creditJoins() . '
              WHERE rac.rac_activity_id = ?',
            array($activityId)
        );
        foreach ($rows as $index => $row) {
            $rows[$index]['review_mode'] = $this->effectiveMode((int) $row['rac_requirement_type_id']);
        }
        return $rows;
    }

    // ---------------------------------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------------------------------

    /**
     * Whether the current user may look at an activity of somebody else because they review it: it
     * has a credit in the reviewers' queue, or one a review covers that has been shared with them.
     */
    public function mayViewActivity(Activity $activity, ?int $userId = null): bool
    {
        return $this->reviewableCreditIds((int) $activity->getValue('rqa_id'), $userId, false) !== array();
    }

    /**
     * The credits of an activity the current user reviews.
     *
     * Configured reviewers review the credits of the queue mode and those of every review that has
     * been shared, finished ones included, so that they can correct a decision. A reviewer named in a
     * review sees its credits once they are shared, and decides on them while the review is not done.
     *
     * @param bool $forDecision whether the question is "may decide" rather than "may see"
     * @return array<int,int>
     */
    public function reviewableCreditIds(int $activityId, ?int $userId = null, bool $forDecision = true): array
    {
        $userId ??= self::currentUserId();
        if (!self::isEnabled() || $activityId <= 0 || $userId <= 0) {
            return array();
        }

        $credits = $this->creditsOfActivity($activityId);
        $configured = $this->isConfiguredReviewer($userId);
        $ids = array();
        foreach ($credits as $credit) {
            if ($configured && (int) $credit['rqt_usr_id'] === 0
                && $credit['review_mode'] === self::MODE_APPROVAL_QUEUE
                && (string) $credit['rqa_status'] === 'submitted') {
                $ids[] = (int) $credit['rac_id'];
            }
        }

        $namedStatuses = $forDecision
            ? array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION)
            : Review::SHARED_STATUSES;
        foreach ($this->reviewsCoveringCredits(array_column($credits, 'rac_id'), Review::SHARED_STATUSES) as $creditId => $reviews) {
            foreach ($reviews as $review) {
                if ($configured
                    || ($this->isNamedReviewer($review, $userId) && in_array((string) $review['rrv_status'], $namedStatuses, true))) {
                    $ids[] = (int) $creditId;
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Whether the current user may decide on this credit.
     */
    public function mayDecideCredit(int $creditId, ?int $userId = null): bool
    {
        $credit = new ActivityCredit($this->db, $creditId);
        if ((int) $credit->getValue('rac_id') === 0) {
            return false;
        }
        return in_array($creditId, $this->reviewableCreditIds((int) $credit->getValue('rac_activity_id'), $userId, true), true);
    }

    /**
     * Whether the member's activity is locked by the review of its credits, and why.
     *
     * A credit in `clarification` unlocks the activity: the reviewer asked the member to change or
     * explain it. Otherwise an open credit locks it when editing during a review is not allowed,
     * and a decided one when editing after a decision is not.
     */
    public function isLockedForOwner(int $activityId): bool
    {
        if (!self::isEnabled() || $activityId <= 0) {
            return false;
        }

        $open = false;
        $decided = false;
        foreach ($this->creditsOfActivity($activityId) as $credit) {
            if ((int) $credit['rqt_usr_id'] > 0 || $credit['review_mode'] === self::MODE_NONE) {
                continue;
            }
            $status = (string) $credit['rac_review_status'];
            if ($status === self::CREDIT_CLARIFICATION) {
                return false;
            }
            $open = $open || $status === self::CREDIT_OPEN;
            $decided = $decided || in_array($status, self::DECIDED_STATUSES, true);
        }

        return ($open && self::editDuringReview() === self::EDIT_LOCKED)
            || ($decided && self::editAfterDecision() === self::EDIT_LOCKED);
    }

    /**
     * Whether an activity carries a credit a reviewer has decided on or is looking at. Such an
     * activity is not deleted by its member: the record under review does not simply vanish.
     */
    public function isUnderReview(int $activityId): bool
    {
        if (!self::isEnabled() || $activityId <= 0) {
            return false;
        }
        foreach ($this->creditsOfActivity($activityId) as $credit) {
            if ((int) $credit['rqt_usr_id'] === 0 && $credit['review_mode'] !== self::MODE_NONE
                && in_array((string) $credit['rac_review_status'], array_merge(self::DECIDED_STATUSES, array(self::CREDIT_OPEN)), true)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------------------------------
    // Credit status when a credit or an activity changes
    // ---------------------------------------------------------------------------------------------

    /**
     * The review status a new credit starts with: `open` in the queue mode once its activity is
     * submitted, nothing otherwise - it waits for a submission or a request.
     */
    public function initialCreditStatus(int $requirementTypeId, string $activityStatus): ?string
    {
        return $this->effectiveMode($requirementTypeId) === self::MODE_APPROVAL_QUEUE && $activityStatus === 'submitted'
            ? self::CREDIT_OPEN : null;
    }

    /**
     * An activity of the queue mode was submitted: its credits join the queue.
     */
    public function activitySubmitted(Activity $activity): void
    {
        foreach ($this->creditsOfActivity((int) $activity->getValue('rqa_id')) as $credit) {
            if ($credit['review_mode'] === self::MODE_APPROVAL_QUEUE && $credit['rac_review_status'] === null
                && (int) $credit['rqt_usr_id'] === 0) {
                $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_OPEN);
            }
        }
    }

    /**
     * The member changed an activity or one of its credits. A decision stays what it is - it is the
     * reviewer's - but the credit is marked as changed after the decision, so that a reviewer can look
     * again. An open credit is marked as changed since it was submitted, in the same way.
     *
     * @param array<int,int>|null $creditIds the credits that changed; null for all of the activity
     */
    public function markChangedByOwner(int $activityId, ?array $creditIds = null): void
    {
        if (!self::isEnabled()) {
            return;
        }
        foreach ($this->creditsOfActivity($activityId) as $credit) {
            if ($creditIds !== null && !in_array((int) $credit['rac_id'], $creditIds, true)) {
                continue;
            }
            $status = (string) $credit['rac_review_status'];
            if ($status === '' || $status === self::CREDIT_CLARIFICATION) {
                continue;
            }
            $entity = new ActivityCredit($this->db, (int) $credit['rac_id']);
            $entity->setValue('rac_review_changed', DATETIME_NOW);
            $entity->save();
        }
    }

    private function setCreditStatus(int $creditId, ?string $status): void
    {
        $credit = new ActivityCredit($this->db, $creditId);
        if ((int) $credit->getValue('rac_id') === 0) {
            return;
        }
        $credit->setValue('rac_review_status', $status);
        $credit->save();
    }

    // ---------------------------------------------------------------------------------------------
    // Member: submit, share, withdraw, ask again
    // ---------------------------------------------------------------------------------------------

    /**
     * Submit a single activity for review: its credits towards requirements in which a member
     * submits activities become open, in one review of the activity.
     *
     * @throws Exception
     */
    public function submitActivity(Activity $activity, int $reviewerUserId = 0, int $reviewerRoleId = 0, string $message = ''): Review
    {
        $this->assertOwner($activity);

        $credits = array_filter(
            $this->creditsOfActivity((int) $activity->getValue('rqa_id')),
            static fn(array $credit): bool => (int) $credit['rqt_usr_id'] === 0
                && in_array($credit['review_mode'], self::ACTIVITY_SUBMISSION_MODES, true)
                && in_array($credit['rac_review_status'], array(null, self::CREDIT_CLARIFICATION), true)
        );
        if ($credits === array()) {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_NOTHING_TO_SUBMIT');
        }
        [$reviewerUserId, $reviewerRoleId] = $this->checkedReviewerChoice($reviewerUserId, $reviewerRoleId);

        $this->db->startTransaction();
        try {
            if ((string) $activity->getValue('rqa_status') === 'draft') {
                $activity->setValue('rqa_status', 'submitted');
                $activity->save();
                $this->activitySubmitted($activity);
            }

            $review = $this->findActive(array('rrv_activity_id' => (int) $activity->getValue('rqa_id')));
            if ($review === null) {
                $review = $this->newReview((int) $activity->getValue('rqa_user_id'), Review::KIND_SUBMISSION);
                $review->setValue('rrv_activity_id', (int) $activity->getValue('rqa_id'));
            }
            $this->openReview($review, $reviewerUserId, $reviewerRoleId, $message);
            foreach ($credits as $credit) {
                $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_OPEN);
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        (new ReviewNotifier($this->db, $this))->submitted($review);
        return $review;
    }

    /**
     * Submit a whole credited period of one requirement for review. A requested review of the same
     * period is shared by this; a review still open takes the newly submitted credits.
     *
     * @throws Exception
     */
    public function submitPeriod(
        RequirementType $type,
        string $periodReference,
        int $reviewerUserId = 0,
        int $reviewerRoleId = 0,
        string $message = '',
        ?int $userId = null
    ): Review {
        $userId ??= self::currentUserId();
        if (!self::isEnabled() || $userId !== self::currentUserId() || !RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $mode = $this->effectiveMode((int) $type->getValue('rqt_id'));
        if ((int) $type->getValue('rqt_parent_id') > 0 || (string) $type->getValue('rqt_period_anchor') !== 'calendar') {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        [$start, $end] = FulfilmentService::periodFor($type, self::date($periodReference));

        $existing = $this->findActive(array(
            'rrv_user_id' => $userId,
            'rrv_requirement_type_id' => (int) $type->getValue('rqt_id'),
            'rrv_period_reference' => $start->format('Y-m-d')
        ));
        $isShare = $existing !== null && (string) $existing->getValue('rrv_status') === Review::STATUS_REQUESTED;
        if (!$isShare && !in_array($mode, self::PERIOD_SUBMISSION_MODES, true)) {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_NOT_SUBMITTABLE');
        }
        if (!$isShare) {
            [$reviewerUserId, $reviewerRoleId] = $this->checkedReviewerChoice($reviewerUserId, $reviewerRoleId);
        }

        // A period whose credits are all decided is submitted again for a new decision: the member
        // has further arguments why it should count more. That needs the arguments, and the decided
        // credits are open again - their accepted amounts keep counting until the reviewer decides anew.
        $state = $this->periodState($type, $userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $again = !$isShare && $state['undecided'] === 0 && $state['decided'] > 0;
        if ($again && trim($message) === '') {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_AGAIN_NEEDS_REASON');
        }
        // A period of mode `period` is submitted even when nothing was earned in it - the submission
        // is the obligation. Anywhere else an empty submission asks for nothing.
        if (!$isShare && !$again && $state['undecided'] === 0 && $existing === null && $mode !== self::MODE_PERIOD) {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_NOTHING_TO_SUBMIT');
        }

        $this->db->startTransaction();
        try {
            $review = $existing ?? $this->newReview($userId, Review::KIND_SUBMISSION);
            if ($existing === null) {
                $review->setValue('rrv_requirement_type_id', (int) $type->getValue('rqt_id'));
                $review->setValue('rrv_period_reference', $start->format('Y-m-d'));
                $review->setValue('rrv_period_to', $end->format('Y-m-d'));
            }
            if ($isShare) {
                $review->setValue('rrv_shared_at', DATETIME_NOW);
                $this->openReview($review, 0, 0, $message, false);
            } else {
                $this->openReview($review, $reviewerUserId, $reviewerRoleId, $message);
            }

            foreach ($this->creditsOfReview($review) as $credit) {
                $status = $credit['rac_review_status'];
                if ($status === null || $status === self::CREDIT_CLARIFICATION
                    || ($again && in_array((string) $status, self::DECIDED_STATUSES, true))) {
                    $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_OPEN);
                }
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        $notifier = new ReviewNotifier($this->db, $this);
        $isShare ? $notifier->shared($review) : $notifier->submitted($review);
        return $review;
    }

    /**
     * How many of the member's submitted credits in a period of a requirement (with its parts) wait
     * for a submission or an answer, and how many are decided.
     *
     * @return array{undecided:int,decided:int}
     */
    public function periodState(RequirementType $type, int $userId, string $periodStart, string $periodEnd): array
    {
        global $gCurrentOrgId;

        $rows = $this->db->getArrayFromSql(
            'SELECT rac.rac_review_status
               FROM ' . RequirementsTables::activityCredits() . ' AS rac' . self::creditJoins() . '
              WHERE rqa.rqa_org_id = ?
                AND rqa.rqa_user_id = ?
                AND rqa.rqa_status = ?
                AND rqt.rqt_usr_id IS NULL
                AND COALESCE(rqt.rqt_parent_id, rqt.rqt_id) = ?
                AND rac.rac_period_reference BETWEEN ? AND ?',
            array((int) $gCurrentOrgId, $userId, 'submitted', (int) $type->getValue('rqt_id'), $periodStart, $periodEnd)
        );
        $state = array('undecided' => 0, 'decided' => 0);
        foreach ($rows as $row) {
            if ($row['rac_review_status'] === null || $row['rac_review_status'] === self::CREDIT_CLARIFICATION) {
                ++$state['undecided'];
            } elseif (in_array((string) $row['rac_review_status'], self::DECIDED_STATUSES, true)) {
                ++$state['decided'];
            }
        }
        return $state;
    }

    /**
     * The member shares the activities a reviewer asked for. They become visible to the reviewer.
     *
     * @throws Exception
     */
    public function share(Review $review, string $message = ''): void
    {
        $this->assertOwnReview($review);
        if ((string) $review->getValue('rrv_status') !== Review::STATUS_REQUESTED) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $this->db->startTransaction();
        try {
            $review->setValue('rrv_shared_at', DATETIME_NOW);
            $this->openReview($review, 0, 0, $message, false);
            foreach ($this->creditsOfReview($review) as $credit) {
                if ($credit['rac_review_status'] === null) {
                    $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_OPEN);
                }
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        (new ReviewNotifier($this->db, $this))->shared($review);
    }

    /**
     * The member takes back a submission that nobody has decided on completely. Credits still open
     * go back to waiting for a submission; decisions already made stay. A review a reviewer asked
     * for cannot be withdrawn.
     *
     * @throws Exception
     */
    public function withdraw(Review $review): void
    {
        $this->assertOwnReview($review);
        if ((string) $review->getValue('rrv_kind') !== Review::KIND_SUBMISSION
            || !in_array((string) $review->getValue('rrv_status'), array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION), true)) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $this->db->startTransaction();
        try {
            $this->releaseCredits($review);
            $review->setValue('rrv_status', Review::STATUS_WITHDRAWN);
            $review->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        (new ReviewNotifier($this->db, $this))->withdrawn($review);
    }

    /**
     * A configured reviewer or an administrator calls off a review that is under way - a request the
     * member has not shared yet, or any other review. Credits still waiting for a decision wait for
     * a submission again; decisions already made stay. The member is told.
     *
     * @throws Exception
     */
    public function cancel(Review $review): void
    {
        if (!self::isEnabled() || !$review->isActive()
            || (!$this->isConfiguredReviewer() && !self::mayAdministrate())) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->db->startTransaction();
        try {
            $this->releaseCredits($review);
            $review->setValue('rrv_status', Review::STATUS_WITHDRAWN);
            $review->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        (new ReviewNotifier($this->db, $this))->cancelled($review);
    }

    /**
     * The credits of a review that ends without a decision go back to waiting for a submission. A
     * credit of the queue mode stays in the queue, which it is in without any review.
     */
    private function releaseCredits(Review $review): void
    {
        foreach ($this->creditsOfReview($review) as $credit) {
            if (in_array($credit['rac_review_status'], array(self::CREDIT_OPEN, self::CREDIT_CLARIFICATION), true)
                && $this->effectiveMode((int) $credit['rac_requirement_type_id']) !== self::MODE_APPROVAL_QUEUE) {
                $this->setCreditStatus((int) $credit['rac_id'], null);
            }
        }
    }

    /**
     * The member asks for a different decision on an activity: its decided credits go to
     * `clarification`, which unlocks them, and the reviewer is told why. The earlier decision keeps
     * counting until the reviewer decides again. There is no limit on how often a member asks.
     *
     * @throws Exception
     */
    public function requestReReview(Activity $activity, string $reason): Review
    {
        $this->assertOwner($activity);
        if (trim($reason) === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('PLG_REQUIREMENTS_REVIEW_REASON'));
        }

        $credits = array_filter(
            $this->creditsOfActivity((int) $activity->getValue('rqa_id')),
            static fn(array $credit): bool => (int) $credit['rqt_usr_id'] === 0
                && $credit['review_mode'] !== self::MODE_NONE
                && in_array((string) $credit['rac_review_status'], self::DECIDED_STATUSES, true)
        );
        if ($credits === array()) {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_NOTHING_DECIDED');
        }

        $this->db->startTransaction();
        try {
            // The review the decision was made in, if there is one; otherwise a review of the activity.
            $covering = $this->reviewsCoveringCredits(array_column($credits, 'rac_id'),
                array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION, Review::STATUS_FINISHED));
            $review = null;
            foreach ($covering as $reviews) {
                $review = new Review($this->db, (int) $reviews[0]['rrv_id']);
                break;
            }
            if ($review === null) {
                $review = $this->newReview((int) $activity->getValue('rqa_user_id'), Review::KIND_SUBMISSION);
                $review->setValue('rrv_activity_id', (int) $activity->getValue('rqa_id'));
            }
            $review->setValue('rrv_status', Review::STATUS_CLARIFICATION);
            $review->setValue('rrv_message', trim($reason));
            $review->setValue('rrv_finished_by', null);
            $review->setValue('rrv_finished_at', null);
            $review->save();

            foreach ($credits as $credit) {
                $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_CLARIFICATION);
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        (new ReviewNotifier($this->db, $this))->reReviewRequested($review, $activity, trim($reason));
        return $review;
    }

    /**
     * The member answers a clarification: the credits in question are open again, and so is the
     * review they are in.
     *
     * @throws Exception
     */
    public function resubmit(Activity $activity, string $message = ''): void
    {
        $this->assertOwner($activity);

        $credits = array_filter(
            $this->creditsOfActivity((int) $activity->getValue('rqa_id')),
            static fn(array $credit): bool => (string) $credit['rac_review_status'] === self::CREDIT_CLARIFICATION
        );
        if ($credits === array()) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $reviews = array();
        $this->db->startTransaction();
        try {
            foreach ($this->reviewsCoveringCredits(array_column($credits, 'rac_id'), array(Review::STATUS_CLARIFICATION)) as $covering) {
                foreach ($covering as $row) {
                    $reviews[(int) $row['rrv_id']] = true;
                }
            }
            foreach ($credits as $credit) {
                $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_OPEN);
            }
            foreach (array_keys($reviews) as $reviewId) {
                $review = new Review($this->db, $reviewId);
                $this->refreshReviewStatus($review);
                if (trim($message) !== '') {
                    $review->setValue('rrv_message', trim($message));
                    $review->save();
                }
                $reviews[$reviewId] = $review;
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        $notifier = new ReviewNotifier($this->db, $this);
        foreach ($reviews as $review) {
            if ($review instanceof Review) {
                $notifier->resubmitted($review);
            }
        }
    }

    /**
     * The member answers the questions of a whole review: its credits in clarification are open
     * again, and so is the review.
     *
     * @throws Exception
     */
    public function resubmitReview(Review $review, string $message = ''): void
    {
        $this->assertOwnReview($review);
        if ((string) $review->getValue('rrv_status') !== Review::STATUS_CLARIFICATION) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $this->db->startTransaction();
        try {
            foreach ($this->creditsOfReview($review) as $credit) {
                if ($credit['rac_review_status'] === self::CREDIT_CLARIFICATION) {
                    $this->setCreditStatus((int) $credit['rac_id'], self::CREDIT_OPEN);
                }
            }
            if (trim($message) !== '') {
                $review->setValue('rrv_message', trim($message));
            }
            $review->setValue('rrv_status', Review::STATUS_OPEN);
            $review->save();
            $this->refreshReviewStatus($review);
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        (new ReviewNotifier($this->db, $this))->resubmitted($review);
    }

    /**
     * What the member can do about the review of one of their activities.
     *
     * @return array{submit:bool,reReview:bool,resubmit:bool}
     */
    public function activityActions(int $activityId): array
    {
        $actions = array('submit' => false, 'reReview' => false, 'resubmit' => false);
        if (!self::isEnabled() || $activityId <= 0) {
            return $actions;
        }
        foreach ($this->creditsOfActivity($activityId) as $credit) {
            if ((int) $credit['rqt_usr_id'] > 0 || $credit['review_mode'] === self::MODE_NONE) {
                continue;
            }
            $status = $credit['rac_review_status'];
            if ($status === null && in_array($credit['review_mode'], self::ACTIVITY_SUBMISSION_MODES, true)) {
                $actions['submit'] = true;
            }
            if (in_array((string) $status, self::DECIDED_STATUSES, true)) {
                $actions['reReview'] = true;
            }
            if ($status === self::CREDIT_CLARIFICATION) {
                $actions['resubmit'] = true;
            }
        }
        return $actions;
    }

    /**
     * What the member is asked to do or has under way: their reviews that are not over, and the
     * periods of requirements in mode `period` they still have to submit.
     *
     * @return array{reviews:array<int,array<string,mixed>>,due:array<int,array<string,mixed>>}
     * @throws Exception
     */
    public function memberAgenda(int $userId): array
    {
        global $gCurrentOrgId;

        $agenda = array('reviews' => array(), 'due' => array());
        if (!self::isEnabled()) {
            return $agenda;
        }

        $agenda['reviews'] = $this->db->getArrayFromSql(
            'SELECT * FROM ' . RequirementsTables::reviews() . '
              WHERE rrv_org_id = ? AND rrv_user_id = ? AND rrv_status IN (?, ?, ?)
           ORDER BY rrv_due_date, rrv_id',
            array_merge(array((int) $gCurrentOrgId, $userId), Review::ACTIVE_STATUSES)
        );

        $types = $this->db->getArrayFromSql(
            'SELECT * FROM ' . RequirementsTables::requirementTypes() . '
              WHERE rqt_org_id = ? AND rqt_usr_id IS NULL AND rqt_parent_id IS NULL
                AND rqt_review_mode = ? AND rqt_period_anchor = ? AND rqt_active = true',
            array((int) $gCurrentOrgId, self::MODE_PERIOD, 'calendar')
        );
        $fulfilment = new FulfilmentService($this->db);
        $today = new DateTimeImmutable(DATE_NOW);
        foreach ($types as $row) {
            $type = new RequirementType($this->db);
            $type->setArray($row);
            [$current] = FulfilmentService::periodFor($type, $today);
            [$start, $end, $label] = FulfilmentService::periodFor($type, $current->modify('-1 day'));

            if ($this->db->queryPrepared(
                'SELECT COUNT(*) FROM ' . RequirementsTables::reviews() . '
                  WHERE rrv_org_id = ? AND rrv_user_id = ? AND rrv_requirement_type_id = ? AND rrv_period_reference = ?
                    AND rrv_status <> ?',
                array((int) $gCurrentOrgId, $userId, (int) $row['rqt_id'], $start->format('Y-m-d'), Review::STATUS_WITHDRAWN)
            )->fetchColumn() > 0) {
                continue;
            }
            $coverage = $fulfilment->memberPeriodCoverage($type, $userId, $start);
            if (!$coverage['hasObligation']) {
                continue;
            }
            $due = self::dueDateOf($type, $end);
            $agenda['due'][] = array(
                'type' => $row,
                'periodStart' => $start->format('Y-m-d'),
                'periodLabel' => $label,
                'dueDate' => $due,
                'overdue' => $due !== null && $due < DATE_NOW
            );
        }
        return $agenda;
    }

    // ---------------------------------------------------------------------------------------------
    // Reviewer: decide, finish, request, remind, reassign
    // ---------------------------------------------------------------------------------------------

    /**
     * Save the reviewer's decisions on several credits at once - one member row of the review page.
     *
     * Each entry gives the accepted amount (null: none), the status ('' to derive it from the
     * amount) and the comment. The status follows the amount unless it is given: the claimed amount
     * is accepted, less is partially accepted, zero is not accepted. A clarification asks the member
     * something and therefore needs a comment.
     *
     * @param array<int,array{accepted:?float,status:string,comment:string}> $decisions by credit id
     * @return array<int,array<string,mixed>> the credits that changed, with their new values
     * @throws Exception
     */
    public function saveDecisions(array $decisions): array
    {
        if (!self::isEnabled() || !RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        // Everything that can be refused is checked before anything is written.
        $resolved = array();
        foreach ($decisions as $creditId => $decision) {
            $creditId = (int) $creditId;
            if (!$this->mayDecideCredit($creditId)) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $credit = new ActivityCredit($this->db, $creditId);
            $claimed = (float) $credit->getValue('rac_credits_claimed', 'database');
            [$accepted, $status] = self::resolveDecision($claimed, $decision['accepted'], (string) $decision['status']);
            $comment = trim((string) $decision['comment']);
            $currentStatus = $credit->getValue('rac_review_status', 'database');
            // A credit under review stays under review: taking a decision back leaves it open.
            if ($status === null && $currentStatus !== null && $currentStatus !== '') {
                $status = self::CREDIT_OPEN;
            }
            if ($status === self::CREDIT_CLARIFICATION && $comment === '') {
                throw new Exception('PLG_REQUIREMENTS_REVIEW_CLARIFICATION_NEEDS_COMMENT');
            }
            $resolved[$creditId] = array($credit, $claimed, $accepted, $status, $comment);
        }

        $changed = array();
        $touchedActivities = array();
        $this->db->startTransaction();
        try {
            foreach ($resolved as $creditId => [$credit, $claimed, $accepted, $status, $comment]) {
                $storedAccepted = $credit->getValue('rac_credits_accepted', 'database');
                $storedAccepted = ($storedAccepted === null || $storedAccepted === '') ? null : (float) $storedAccepted;
                $storedStatus = $credit->getValue('rac_review_status', 'database');
                $storedStatus = ($storedStatus === null || $storedStatus === '') ? null : (string) $storedStatus;
                $storedComment = trim((string) $credit->getValue('rac_review_comment', 'database'));
                if ($storedAccepted === $accepted && $storedStatus === $status && $storedComment === $comment) {
                    continue;
                }

                $credit->setValue('rac_credits_accepted', $accepted === null ? null : number_format($accepted, 2, '.', ''));
                $credit->setValue('rac_review_status', $status);
                $credit->setValue('rac_review_comment', $comment === '' ? null : $comment);
                // The reviewer has looked at it again.
                $credit->setValue('rac_review_changed', null);
                $credit->save();

                $activityId = (int) $credit->getValue('rac_activity_id');
                $touchedActivities[$activityId] = true;
                $changed[$creditId] = array(
                    'activityId' => $activityId,
                    'requirementTypeId' => (int) $credit->getValue('rac_requirement_type_id'),
                    'claimed' => $claimed,
                    'accepted' => $accepted,
                    'status' => $status,
                    'comment' => $comment
                );
            }

            $activityService = new ActivityService($this->db);
            foreach (array_keys($touchedActivities) as $activityId) {
                $activityService->assertSubRequirements(new Activity($this->db, $activityId));
            }

            // A review waits for the member while any of its credits asks the member something.
            $reviews = array();
            foreach ($this->reviewsCoveringCredits(array_keys($changed), array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION)) as $covering) {
                foreach ($covering as $row) {
                    $reviews[(int) $row['rrv_id']] = true;
                }
            }
            foreach (array_keys($reviews) as $reviewId) {
                $this->refreshReviewStatus(new Review($this->db, $reviewId));
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        if ($changed !== array()) {
            (new ReviewNotifier($this->db, $this))->decided($changed);
        }
        return $changed;
    }

    /**
     * The accepted amount and the status a decision stores, from what the reviewer entered.
     *
     * @return array{0:?float,1:?string}
     * @throws Exception
     */
    public static function resolveDecision(float $claimed, ?float $accepted, string $status): array
    {
        if ($accepted !== null && $accepted < 0) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        if ($status !== '' && !in_array($status, self::CREDIT_STATUSES, true)) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        switch ($status) {
            case self::CREDIT_ACCEPTED:
                return array($accepted ?? $claimed, $status);
            case self::CREDIT_NOT_ACCEPTED:
                return array($accepted ?? 0.0, $status);
            case self::CREDIT_PARTIALLY_ACCEPTED:
                if ($accepted === null) {
                    throw new Exception('PLG_REQUIREMENTS_REVIEW_PARTIAL_NEEDS_AMOUNT');
                }
                return array($accepted, $status);
            case self::CREDIT_CLARIFICATION:
            case self::CREDIT_OPEN:
                return array($accepted, $status);
            default:
                if ($accepted === null) {
                    return array(null, null);
                }
                return array($accepted, self::statusForAmount($claimed, $accepted));
        }
    }

    /** The status an accepted amount stands for. */
    public static function statusForAmount(float $claimed, float $accepted): string
    {
        if ($accepted <= 0.0) {
            return self::CREDIT_NOT_ACCEPTED;
        }
        return $accepted + 0.005 < $claimed ? self::CREDIT_PARTIALLY_ACCEPTED : self::CREDIT_ACCEPTED;
    }

    /**
     * The reviewer is done: the member gets the summary.
     *
     * @throws Exception
     */
    public function finish(Review $review, string $comment = ''): void
    {
        if (!$this->mayHandleReview($review)
            || !in_array((string) $review->getValue('rrv_status'), array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION), true)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $review->setValue('rrv_status', Review::STATUS_FINISHED);
        $review->setValue('rrv_result_comment', trim($comment) === '' ? null : trim($comment));
        $review->setValue('rrv_finished_by', self::currentUserId());
        $review->setValue('rrv_finished_at', DATETIME_NOW);
        $review->save();

        (new ReviewNotifier($this->db, $this))->finished($review);
    }

    /**
     * A reviewer asks members to share their activities of a requirement for some periods (an
     * audit). One review per member and period; a member who already has a review of that period
     * under way is not asked twice.
     *
     * @param array<int,int> $userIds
     * @param array<int,string> $periodReferences any day of each period
     * @return array<int,Review> the reviews created
     * @throws Exception
     */
    public function requestReviews(
        RequirementType $type,
        array $periodReferences,
        array $userIds,
        string $dueDate = '',
        string $message = '',
        int $reviewerUserId = 0,
        int $reviewerRoleId = 0
    ): array {
        if (!$this->isConfiguredReviewer() || !RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if ((int) $type->getValue('rqt_id') === 0 || (int) $type->getValue('rqt_parent_id') > 0
            || (string) $type->getValue('rqt_period_anchor') !== 'calendar'
            || $this->effectiveMode((int) $type->getValue('rqt_id')) === self::MODE_NONE) {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_NOT_REQUESTABLE');
        }
        if ($dueDate !== '') {
            self::date($dueDate);
        }

        $activityService = new ActivityService($this->db);
        $created = array();
        $this->db->startTransaction();
        try {
            foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                if (!$activityService->isKnownMember($userId)) {
                    throw new Exception('SYS_NO_RIGHTS');
                }
                foreach (array_unique($periodReferences) as $reference) {
                    [$start, $end] = FulfilmentService::periodFor($type, self::date($reference));
                    if ($this->findActive(array(
                        'rrv_user_id' => $userId,
                        'rrv_requirement_type_id' => (int) $type->getValue('rqt_id'),
                        'rrv_period_reference' => $start->format('Y-m-d')
                    )) !== null) {
                        continue;
                    }
                    $review = $this->newReview($userId, Review::KIND_REQUEST);
                    $review->setValue('rrv_requirement_type_id', (int) $type->getValue('rqt_id'));
                    $review->setValue('rrv_period_reference', $start->format('Y-m-d'));
                    $review->setValue('rrv_period_to', $end->format('Y-m-d'));
                    $review->setValue('rrv_status', Review::STATUS_REQUESTED);
                    $review->setValue('rrv_due_date', $dueDate === '' ? null : $dueDate);
                    $review->setValue('rrv_message', trim($message) === '' ? null : trim($message));
                    $review->setValue('rrv_reviewer_user_id', $reviewerUserId > 0 ? $reviewerUserId : null);
                    $review->setValue('rrv_reviewer_role_id', $reviewerUserId <= 0 && $reviewerRoleId > 0 ? $reviewerRoleId : null);
                    $review->save();
                    $created[] = $review;
                }
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        $notifier = new ReviewNotifier($this->db, $this);
        foreach ($created as $review) {
            $notifier->requested($review);
        }
        return $created;
    }

    /**
     * Remind a member of a review that waits for them.
     *
     * @throws Exception
     */
    public function remind(Review $review): void
    {
        if (!$this->mayHandleReview($review)
            || !in_array((string) $review->getValue('rrv_status'), array(Review::STATUS_REQUESTED, Review::STATUS_CLARIFICATION), true)) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        (new ReviewNotifier($this->db, $this))->reminded($review);
    }

    /**
     * Remind a member to submit a period of a requirement in mode `period`.
     *
     * @throws Exception
     */
    public function remindSubmission(RequirementType $type, string $periodReference, int $userId): void
    {
        if (!$this->isConfiguredReviewer()
            || $this->effectiveMode((int) $type->getValue('rqt_id')) !== self::MODE_PERIOD
            || !(new ActivityService($this->db))->isKnownMember($userId)) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        [$start, $end, $label] = FulfilmentService::periodFor($type, self::date($periodReference));
        (new ReviewNotifier($this->db, $this))->submissionReminder($type, $start->format('Y-m-d'), $label, self::dueDateOf($type, $end), $userId);
    }

    /**
     * A configured reviewer hands a review to another reviewer.
     *
     * @throws Exception
     */
    public function reassign(Review $review, int $reviewerUserId, int $reviewerRoleId): void
    {
        if (!$this->isConfiguredReviewer() || !$review->isActive()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $previous = self::rowOf($review);
        $review->setValue('rrv_reviewer_user_id', $reviewerUserId > 0 ? $reviewerUserId : null);
        $review->setValue('rrv_reviewer_role_id', $reviewerUserId <= 0 && $reviewerRoleId > 0 ? $reviewerRoleId : null);
        $review->save();

        (new ReviewNotifier($this->db, $this))->reassigned($review, $previous);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * The day a period of a requirement in mode `period` has to be submitted by, or null without a
     * deadline.
     */
    public static function dueDateOf(RequirementType $type, DateTimeImmutable $periodEnd): ?string
    {
        $days = (int) $type->getValue('rqt_review_due_days');
        return $days > 0 ? $periodEnd->modify('+' . $days . ' days')->format('Y-m-d') : null;
    }

    /**
     * The review that is not over yet and matches these columns, the latest one first.
     *
     * @param array<string,int|string> $columns
     */
    public function findActive(array $columns): ?Review
    {
        global $gCurrentOrgId;

        $conditions = array('rrv_org_id = ?');
        $params = array((int) $gCurrentOrgId);
        foreach ($columns as $column => $value) {
            $conditions[] = $column . ' = ?';
            $params[] = $value;
        }
        $params = array_merge($params, Review::ACTIVE_STATUSES);

        $id = $this->db->queryPrepared(
            'SELECT rrv_id FROM ' . RequirementsTables::reviews() . '
              WHERE ' . implode(' AND ', $conditions) . '
                AND rrv_status IN (?, ?, ?)
           ORDER BY rrv_id DESC',
            $params
        )->fetchColumn();

        return $id === false ? null : new Review($this->db, (int) $id);
    }

    /**
     * The status a review has from its credits: waiting for the member while one of them asks
     * something, open otherwise. A review that is requested, finished or withdrawn keeps its status.
     */
    private function refreshReviewStatus(Review $review): void
    {
        $status = (string) $review->getValue('rrv_status');
        if (!in_array($status, array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION), true)) {
            return;
        }
        $clarification = false;
        foreach ($this->creditsOfReview($review) as $credit) {
            if ($credit['rac_review_status'] === self::CREDIT_CLARIFICATION) {
                $clarification = true;
                break;
            }
        }
        $review->setValue('rrv_status', $clarification ? Review::STATUS_CLARIFICATION : Review::STATUS_OPEN);
        $review->save();
    }

    private function newReview(int $userId, string $kind): Review
    {
        global $gCurrentOrgId;

        $review = new Review($this->db);
        $review->setValue('rrv_org_id', (int) $gCurrentOrgId);
        $review->setValue('rrv_user_id', $userId);
        $review->setValue('rrv_kind', $kind);
        $review->setValue('rrv_requested_by', self::currentUserId());
        return $review;
    }

    private function openReview(Review $review, int $reviewerUserId, int $reviewerRoleId, string $message, bool $setReviewer = true): void
    {
        $review->setValue('rrv_status', Review::STATUS_OPEN);
        if ($setReviewer) {
            $review->setValue('rrv_reviewer_user_id', $reviewerUserId > 0 ? $reviewerUserId : null);
            $review->setValue('rrv_reviewer_role_id', $reviewerUserId <= 0 && $reviewerRoleId > 0 ? $reviewerRoleId : null);
        }
        if (trim($message) !== '') {
            $review->setValue('rrv_message', trim($message));
        }
        $review->save();
    }

    /**
     * The reviewer a member picked, checked against what the settings allow: nobody when every
     * review goes to the configured reviewers, one of them, or - for peer review - any member or role
     * of the organization. The member is never their own reviewer.
     *
     * @return array{0:int,1:int} user id and role id, at most one of them set
     * @throws Exception
     */
    public function checkedReviewerChoice(int $userId, int $roleId): array
    {
        $choice = self::reviewerChoice();
        if ($choice === self::CHOICE_CONFIGURED || ($userId <= 0 && $roleId <= 0)) {
            return array(0, 0);
        }
        if ($userId > 0) {
            if ($userId === self::currentUserId()) {
                throw new Exception('PLG_REQUIREMENTS_REVIEW_NOT_OWN_REVIEWER');
            }
            $allowed = $choice === self::CHOICE_ANY
                ? (new ActivityService($this->db))->isKnownMember($userId)
                : in_array($userId, $this->configuredReviewerIds(), true);
            if (!$allowed) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            return array($userId, 0);
        }

        $allowed = $choice === self::CHOICE_ANY
            ? $this->isRoleOfOrganization($roleId)
            : in_array($roleId, self::reviewerRoleIds(), true);
        if (!$allowed) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        return array(0, $roleId);
    }

    private function isRoleOfOrganization(int $roleId): bool
    {
        global $gCurrentOrgId;

        return (int) $this->db->queryPrepared(
            'SELECT COUNT(*)
               FROM ' . TBL_ROLES . ' AS rol
         INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
              WHERE rol.rol_id = ?
                AND rol.rol_valid = true
                AND (cat.cat_org_id = ? OR cat.cat_org_id IS NULL)',
            array($roleId, (int) $gCurrentOrgId)
        )->fetchColumn() > 0;
    }

    /**
     * @throws Exception
     */
    private function assertOwner(Activity $activity): void
    {
        global $gCurrentOrgId;

        if (!self::isEnabled()
            || !RequirementsPlugin::isUsableByCurrentUser()
            || (int) $activity->getValue('rqa_id') === 0
            || (int) $activity->getValue('rqa_org_id') !== (int) $gCurrentOrgId
            || (int) $activity->getValue('rqa_user_id') !== self::currentUserId()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * @throws Exception
     */
    private function assertOwnReview(Review $review): void
    {
        if (!self::isEnabled()
            || !RequirementsPlugin::isUsableByCurrentUser()
            || (int) $review->getValue('rrv_id') === 0
            || (int) $review->getValue('rrv_user_id') !== self::currentUserId()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * The columns of a review as a row, the shape the queries return.
     *
     * @return array<string,mixed>
     */
    public static function rowOf(Review $review): array
    {
        $row = array();
        foreach (array('rrv_id', 'rrv_uuid', 'rrv_user_id', 'rrv_requirement_type_id', 'rrv_activity_id', 'rrv_kind',
            'rrv_reviewer_user_id', 'rrv_reviewer_role_id', 'rrv_status', 'rrv_message', 'rrv_result_comment',
            'rrv_requested_by') as $column) {
            $row[$column] = $review->getValue($column, 'database');
        }
        foreach (array('rrv_period_reference', 'rrv_period_to', 'rrv_due_date') as $column) {
            $row[$column] = $review->getValue($column, 'database');
        }
        return $row;
    }

    /**
     * @throws Exception
     */
    public static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false) {
            throw new Exception('SYS_DATE_INVALID', array('SYS_DATE', 'YYYY-MM-DD'));
        }
        return $date;
    }

    /**
     * Whether the plugin administrator may look at the review page at all, even without being a
     * reviewer: they see the roster, but decide nothing.
     */
    public static function mayAdministrate(): bool
    {
        return Component::isAdministrable(RequirementsPlugin::ID);
    }
}
