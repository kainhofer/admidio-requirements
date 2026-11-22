<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Entity\Review;
use DateTimeImmutable;

/**
 * What the review page lists: one row per member, requirement and credited period.
 *
 * A row is identified by its key "userId|requirementId|periodStart" - the period start is empty for
 * a requirement that recurs after its last fulfilment, which has no calendar periods. The review
 * statuses of the filter each find their rows differently: from the reviews, from the credits of the
 * queue mode, or from the members who owe a submission and have not made it.
 */
final class ReviewListService
{
    public const FILTER_NEEDS_REVIEW = 'needs_review';
    public const FILTER_REQUESTED = 'requested';
    public const FILTER_NOT_SUBMITTED = 'not_submitted';
    public const FILTER_OVERDUE = 'overdue';
    public const FILTER_FINISHED = 'finished';
    public const FILTER_ALL_REVIEWS = 'all_reviews';

    public const REVIEW_FILTERS = array(
        self::FILTER_NEEDS_REVIEW,
        self::FILTER_REQUESTED,
        self::FILTER_NOT_SUBMITTED,
        self::FILTER_OVERDUE,
        self::FILTER_FINISHED,
        self::FILTER_ALL_REVIEWS
    );

    /** @var array<int,RequirementType> */
    private array $types = array();

    public function __construct(private readonly Database $db, private readonly ReviewService $reviews)
    {
    }

    public static function key(int $userId, int $typeId, string $periodStart): string
    {
        return $userId . '|' . $typeId . '|' . $periodStart;
    }

    /**
     * @return array{0:int,1:int,2:string}
     */
    public static function splitKey(string $key): array
    {
        [$userId, $typeId, $period] = array_pad(explode('|', $key, 3), 3, '');
        return array((int) $userId, (int) $typeId, $period);
    }

    /**
     * The rows a review status filter finds, narrowed by requirement and period.
     *
     * @param int $typeId 0 for every requirement
     * @param string $periodStart any day of the period, '' for every period
     * @return array<int,string> keys
     * @throws Exception
     */
    public function keysFor(string $filter, int $typeId, string $periodStart): array
    {
        $keys = array();
        if (in_array($filter, array(self::FILTER_NEEDS_REVIEW, self::FILTER_ALL_REVIEWS), true)) {
            $keys = array_merge($keys, $this->queueKeys());
        }
        if ($filter === self::FILTER_NOT_SUBMITTED || $filter === self::FILTER_OVERDUE) {
            $keys = array_merge($keys, $this->unsubmittedKeys($typeId, $periodStart, $filter === self::FILTER_OVERDUE));
        }

        $statuses = match ($filter) {
            self::FILTER_NEEDS_REVIEW => array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION),
            self::FILTER_REQUESTED, self::FILTER_OVERDUE => array(Review::STATUS_REQUESTED),
            self::FILTER_FINISHED => array(Review::STATUS_FINISHED),
            self::FILTER_ALL_REVIEWS => array(Review::STATUS_REQUESTED, Review::STATUS_OPEN,
                Review::STATUS_CLARIFICATION, Review::STATUS_FINISHED),
            default => array()
        };
        foreach ($this->findReviews($statuses) as $review) {
            if ($filter === self::FILTER_OVERDUE
                && ((string) $review['rrv_due_date'] === '' || (string) $review['rrv_due_date'] >= DATE_NOW)) {
                continue;
            }
            if (!$this->reviews->mayHandleReview($review) && !ReviewService::mayAdministrate()) {
                continue;
            }
            $keys = array_merge($keys, $this->keysOfReview($review));
        }

        $keys = array_values(array_unique($keys));
        return array_values(array_filter($keys, function (string $key) use ($typeId, $periodStart): bool {
            [, $keyType, $keyPeriod] = self::splitKey($key);
            return ($typeId === 0 || $keyType === $typeId)
                && ($periodStart === '' || $keyPeriod === $this->periodStartOf($keyType, $periodStart));
        }));
    }

    /**
     * The reviews of the current organization in these statuses, newest first.
     *
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    public function findReviews(array $statuses, array $userIds = array()): array
    {
        global $gCurrentOrgId;

        if ($statuses === array()) {
            return array();
        }
        $sql = 'SELECT * FROM ' . RequirementsTables::reviews() . '
                 WHERE rrv_org_id = ?
                   AND rrv_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge(array((int) $gCurrentOrgId), $statuses);
        if ($userIds !== array()) {
            $sql .= ' AND rrv_user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')';
            $params = array_merge($params, array_values($userIds));
        }
        return $this->db->getArrayFromSql($sql . ' ORDER BY rrv_id DESC', $params);
    }

    /**
     * The rows a review touches: its requirement and period, or - for a review of one activity - the
     * requirement and period of each credit it covers.
     *
     * @param array<string,mixed> $review
     * @return array<int,string>
     * @throws Exception
     */
    public function keysOfReview(array $review): array
    {
        $userId = (int) $review['rrv_user_id'];
        if ((int) $review['rrv_activity_id'] === 0) {
            return array(self::key($userId, (int) $review['rrv_requirement_type_id'], (string) $review['rrv_period_reference']));
        }

        $keys = array();
        foreach ($this->reviews->creditsOfReview($review) as $credit) {
            $typeId = (int) ($credit['rqt_parent_id'] ?: $credit['rac_requirement_type_id']);
            $keys[] = self::key($userId, $typeId, $this->periodStartOf($typeId, (string) $credit['rac_period_reference']));
        }
        return $keys;
    }

    /**
     * The reviews of the rows, by key: those of the requirement and period, and those of single
     * activities with a credit in them. Withdrawn reviews are left out.
     *
     * @param array<int,string> $keys
     * @return array<string,array<int,array<string,mixed>>>
     * @throws Exception
     */
    public function reviewsByKey(array $keys): array
    {
        $userIds = array();
        foreach ($keys as $key) {
            $userIds[self::splitKey($key)[0]] = true;
        }
        if ($userIds === array()) {
            return array();
        }

        $byKey = array();
        $wanted = array_flip($keys);
        foreach ($this->findReviews(array(Review::STATUS_REQUESTED, Review::STATUS_OPEN,
            Review::STATUS_CLARIFICATION, Review::STATUS_FINISHED), array_keys($userIds)) as $review) {
            foreach (array_unique($this->keysOfReview($review)) as $key) {
                if (isset($wanted[$key])) {
                    $byKey[$key][] = $review;
                }
            }
        }
        return $byKey;
    }

    /**
     * The rows of the queue mode: members with credits waiting for a decision.
     *
     * @return array<int,string>
     * @throws Exception
     */
    private function queueKeys(): array
    {
        global $gCurrentOrgId;

        if (!$this->reviews->isConfiguredReviewer() && !ReviewService::mayAdministrate()) {
            return array();
        }

        $rows = $this->db->getArrayFromSql(
            'SELECT DISTINCT rqa.rqa_user_id, COALESCE(rqt.rqt_parent_id, rqt.rqt_id) AS type_id, rac.rac_period_reference
               FROM ' . RequirementsTables::activityCredits() . ' AS rac
         INNER JOIN ' . RequirementsTables::activities() . ' AS rqa ON rqa.rqa_id = rac.rac_activity_id
         INNER JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt ON rqt.rqt_id = rac.rac_requirement_type_id
          LEFT JOIN ' . RequirementsTables::requirementTypes() . ' AS rpt ON rpt.rqt_id = rqt.rqt_parent_id
              WHERE rqa.rqa_org_id = ?
                AND rqa.rqa_status = ?
                AND rqt.rqt_usr_id IS NULL
                AND COALESCE(rpt.rqt_review_mode, rqt.rqt_review_mode) = ?
                AND rac.rac_review_status IN (?, ?)',
            array((int) $gCurrentOrgId, 'submitted', ReviewService::MODE_APPROVAL_QUEUE,
                ReviewService::CREDIT_OPEN, ReviewService::CREDIT_CLARIFICATION)
        );

        $keys = array();
        foreach ($rows as $row) {
            $typeId = (int) $row['type_id'];
            $keys[] = self::key((int) $row['rqa_user_id'], $typeId, $this->periodStartOf($typeId, (string) $row['rac_period_reference']));
        }
        return $keys;
    }

    /**
     * The members who owe the submission of a finished period of a requirement in mode `period` and
     * have not made it: everybody the requirement applied to in that period, without a review of it.
     * Without a period, the last finished one of each requirement.
     *
     * @return array<int,string>
     * @throws Exception
     */
    private function unsubmittedKeys(int $typeId, string $periodStart, bool $overdueOnly): array
    {
        global $gCurrentOrgId;

        if (!$this->reviews->isConfiguredReviewer() && !ReviewService::mayAdministrate()) {
            return array();
        }

        $types = $this->db->getArrayFromSql(
            'SELECT * FROM ' . RequirementsTables::requirementTypes() . '
              WHERE rqt_org_id = ? AND rqt_usr_id IS NULL AND rqt_parent_id IS NULL
                AND rqt_review_mode = ? AND rqt_period_anchor = ?',
            array((int) $gCurrentOrgId, ReviewService::MODE_PERIOD, 'calendar')
        );

        $fulfilment = new FulfilmentService($this->db);
        $today = new DateTimeImmutable(DATE_NOW);
        $keys = array();
        foreach ($types as $row) {
            if ($typeId !== 0 && (int) $row['rqt_id'] !== $typeId) {
                continue;
            }
            $type = new RequirementType($this->db);
            $type->setArray($row);

            if ($periodStart !== '') {
                [$start, $end] = FulfilmentService::periodFor($type, ReviewService::date($periodStart));
            } else {
                [$current] = FulfilmentService::periodFor($type, $today);
                [$start, $end] = FulfilmentService::periodFor($type, $current->modify('-1 day'));
            }
            if ($end >= $today) {
                continue;
            }
            $due = ReviewService::dueDateOf($type, $end);
            if ($overdueOnly && ($due === null || $due >= DATE_NOW)) {
                continue;
            }

            $submitted = array_map('intval', $this->db->queryPrepared(
                'SELECT DISTINCT rrv_user_id FROM ' . RequirementsTables::reviews() . '
                  WHERE rrv_org_id = ? AND rrv_requirement_type_id = ? AND rrv_period_reference = ?
                    AND rrv_status IN (?, ?, ?)',
                array((int) $gCurrentOrgId, (int) $row['rqt_id'], $start->format('Y-m-d'),
                    Review::STATUS_OPEN, Review::STATUS_CLARIFICATION, Review::STATUS_FINISHED)
            )->fetchAll(\PDO::FETCH_COLUMN));

            $coverage = $fulfilment->coverageForMembers((int) $row['rqt_id'], $start->format('Y-m-d'));
            foreach ($coverage['members'] as $member) {
                if ($member['required'] === null && $member['status'] !== 'no-credits-required') {
                    continue;
                }
                if (!in_array((int) $member['userId'], $submitted, true)) {
                    $keys[] = self::key((int) $member['userId'], (int) $row['rqt_id'], $start->format('Y-m-d'));
                }
            }
        }
        return $keys;
    }

    /**
     * The start of the period a credited period reference lies in, or '' for a requirement without
     * calendar periods.
     *
     * @throws Exception
     */
    public function periodStartOf(int $typeId, string $reference): string
    {
        $type = $this->type($typeId);
        if ((string) $type->getValue('rqt_period_anchor') !== 'calendar' || $reference === '') {
            return '';
        }
        return FulfilmentService::periodFor($type, ReviewService::date($reference))[0]->format('Y-m-d');
    }

    public function type(int $typeId): RequirementType
    {
        return $this->types[$typeId] ??= new RequirementType($this->db, $typeId);
    }
}
