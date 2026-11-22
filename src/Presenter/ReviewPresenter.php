<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Entity\Review;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Service\ReviewListService;
use AdmidioPlugin\Requirements\Service\ReviewNotifier;
use AdmidioPlugin\Requirements\Service\ReviewService;
use DateTimeImmutable;

/**
 * The review page: one row per member, requirement and credited period, with the credits behind
 * each row and the reviewer's decision on each of them.
 *
 * The filter takes a review status - what needs review, what was requested, who has not submitted,
 * what is overdue, what is finished - or a fulfilment status, which lists the members a requirement
 * concerns in one period as the roster did before reviews existed. Each row is saved on its own,
 * without reloading the page, and a review is finished from its row.
 */
class ReviewPresenter extends RequirementsPresenter
{
    /**
     * The coverage statuses the filter offers, by requirement anchor. A calendar period and a
     * recurring cycle cannot produce the same answers - "overdue" needs a due date and "missing"
     * needs a period - so offering one fixed list would offer filters that never match.
     */
    private const STATUS_FILTERS = array(
        'calendar' => array('missing', 'fulfilled', 'no-credits-required', 'additional-credits', 'none'),
        'last_fulfilment' => array('overdue', 'never-fulfilled', 'fulfilled', 'additional-credits')
    );

    /** A fulfilment status of the filter is told from a review status by this prefix. */
    private const COVERAGE_PREFIX = 'coverage:';

    private ReviewService $reviews;

    /** @var array<int,array<int,int>> credit ids the current user may see, by activity */
    private array $viewable = array();

    /** @var array<int,array<int,int>> credit ids the current user may decide on, by activity */
    private array $decidable = array();

    public function __construct(string $objectUUID = '')
    {
        global $gDb;

        parent::__construct($objectUUID);
        $this->reviews = new ReviewService($gDb);
    }

    /**
     * Whether the current user may open the review page: administrators, to see the roster, and
     * reviewers, to review.
     */
    public static function mayOpen(): bool
    {
        global $gDb;

        return ReviewService::mayAdministrate() || (new ReviewService($gDb))->isAnyReviewer();
    }

    /**
     * The status filter a reviewer starts with: what needs review. Without reviews, the roster.
     */
    public static function defaultStatus(): string
    {
        global $gDb;

        return ReviewService::isEnabled() && (new ReviewService($gDb))->isAnyReviewer()
            ? ReviewListService::FILTER_NEEDS_REVIEW : '';
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(
        string $requirementUuid = '',
        string $period = '',
        string $quarter = '',
        string $status = '',
        string $memberQuery = ''
    ): void {
        global $gDb, $gL10n;

        $this->setHtmlID('adm_requirements_review');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_REVIEW'));

        $this->addPageFunctionsMenuItem('menu_item_requirements_activities',
            $gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'), $this->pageUrl('index.php'), 'bi-clipboard-check');
        if ($this->isAdmin()) {
            $this->addPageFunctionsMenuItem('menu_item_requirements_rules',
                $gL10n->get('PLG_REQUIREMENTS_NAME'), $this->pageUrl('rules.php'), 'bi-list-check');
        }
        if ($this->reviews->isConfiguredReviewer()) {
            $this->addPageFunctionsMenuItem('menu_item_requirements_review_request',
                $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_REVIEWS'),
                $this->pageUrl('review.php', array('mode' => 'request')), 'bi-envelope-paper');
        }
        $this->addHistoryMenuItem('reviews', array('req_reviews', 'req_activity_credits'));

        $allTypes = array_values(array_filter(
            (new RequirementTypeService($gDb))->findTypes(),
            static fn(array $type): bool => (int) $type['rqt_usr_id'] === 0 && (int) $type['rqt_parent_id'] === 0
        ));
        if ($allTypes === array()) {
            $this->assignSmartyVariable('noRequirements', $gL10n->get('PLG_REQUIREMENTS_REVIEW_NO_REQUIREMENTS'));
            $this->addHtmlByTemplate('requirements.review.filter.tpl');
            return;
        }

        $reviewFilters = ReviewService::isEnabled() ? ReviewListService::REVIEW_FILTERS : array();
        // The roster - every member a requirement concerns, with their coverage - is for the
        // administrators and the configured reviewers. A member named as the reviewer of a single
        // review sees that review and nothing about the others.
        $mayRoster = $this->isAdmin() || $this->reviews->isConfiguredReviewer();
        if (!$mayRoster && !in_array($status, $reviewFilters, true)) {
            $status = ReviewListService::FILTER_NEEDS_REVIEW;
        }
        $isReviewFilter = in_array($status, $reviewFilters, true);
        $coverageStatus = str_starts_with($status, self::COVERAGE_PREFIX) ? substr($status, strlen(self::COVERAGE_PREFIX)) : '';
        if (!$isReviewFilter && $coverageStatus === '') {
            $status = '';
        }

        // The roster needs one requirement; the review filters may span all of them.
        $selectedType = null;
        foreach ($allTypes as $type) {
            if ($requirementUuid !== '' && $type['rqt_uuid'] === $requirementUuid) {
                $selectedType = $type;
                break;
            }
        }
        if ($selectedType === null && !$isReviewFilter) {
            $selectedType = $allTypes[0];
        }

        $kind = 'date';
        $anchor = 'calendar';
        if ($selectedType !== null) {
            $typeEntity = new RequirementType($gDb);
            $typeEntity->setArray($selectedType);
            $kind = FulfilmentService::periodControlKind($typeEntity);
            $anchor = (string) $selectedType['rqt_period_anchor'] === 'calendar' ? 'calendar' : 'last_fulfilment';
        }
        if ($coverageStatus !== '' && !in_array($coverageStatus, self::STATUS_FILTERS[$anchor], true)) {
            $status = '';
            $coverageStatus = '';
        }

        // A period the control cannot make sense of falls back to "none" rather than refusing the
        // page: the filter is a way of looking, and a bad one should not become an error message.
        $periodDate = '';
        if ($period !== '') {
            try {
                $periodDate = FulfilmentService::periodInputToDateForKind($kind, $period, $quarter);
            } catch (Exception) {
                $periodDate = '';
            }
        }

        $list = new ReviewListService($gDb, $this->reviews);
        if ($isReviewFilter) {
            $keys = $list->keysFor($status, $selectedType === null ? 0 : (int) $selectedType['rqt_id'], $periodDate);
            $periodLabel = '';
        } else {
            $coverage = (new FulfilmentService($gDb))->coverageForMembers((int) $selectedType['rqt_id'], $periodDate, $coverageStatus);
            $keys = array();
            foreach ($coverage['members'] as $member) {
                $keys[] = ReviewListService::key((int) $member['userId'], (int) $selectedType['rqt_id'],
                    $anchor === 'calendar' ? (string) $coverage['periodReference'] : '');
            }
            $periodDate = (string) $coverage['periodReference'];
            $periodLabel = (string) $coverage['periodLabel'];
        }

        $rows = $this->buildRows($keys, $list, $memberQuery);
        $this->assignFilter($allTypes, $selectedType, $anchor, $kind, $periodDate, $status, $memberQuery,
            $rows, $isReviewFilter, $periodLabel);

        // The decisions of a row are sent on their own, so that a reviewer working through a long
        // list never loses the rows they have not saved yet to a page reload.
        $this->addJavascript($this->rowScript(), true);

        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('messageNoData', $gL10n->get($isReviewFilter
            ? 'PLG_REQUIREMENTS_REVIEW_NOTHING_FOUND' : 'PLG_REQUIREMENTS_REVIEW_NO_MEMBERS'));
        $this->assignSmartyVariable('showRequirement', $selectedType === null);
        $this->assignSmartyVariable('statusOptions', $this->decisionOptions());
        $this->assignSmartyVariable('csrfToken', $GLOBALS['gCurrentSession']->getCsrfToken());
        $this->assignSmartyVariable('reviewsEnabled', ReviewService::isEnabled());
        $this->addHtmlByTemplate('requirements.review.list.tpl');
    }

    /**
     * One row, rendered on its own - what the page puts in place of a row after saving it.
     *
     * @throws Exception|\Smarty\Exception
     */
    public function renderRow(int $userId, int $typeId, string $periodStart): string
    {
        global $gDb;

        $list = new ReviewListService($gDb, $this->reviews);
        $rows = $this->buildRows(array(ReviewListService::key($userId, $typeId, $periodStart)), $list, '');
        if ($rows === array()) {
            return '';
        }
        $types = array_filter(
            (new RequirementTypeService($gDb))->findTypes(),
            static fn(array $type): bool => (int) $type['rqt_usr_id'] === 0 && (int) $type['rqt_parent_id'] === 0
        );

        return $this->plugin->renderTemplate($this, 'requirements.review.row.tpl', array(
            'row' => $rows[0],
            'showRequirement' => count($types) > 1,
            'statusOptions' => $this->decisionOptions(),
            'reviewsEnabled' => ReviewService::isEnabled(),
            'l10n' => $GLOBALS['gL10n']
        ));
    }

    /**
     * The rows for these keys, with their coverage, their reviews and their credits.
     *
     * @param array<int,string> $keys
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    private function buildRows(array $keys, ReviewListService $list, string $memberQuery): array
    {
        global $gDb;

        // One roster calculation per requirement and period, whatever the number of members.
        $groups = array();
        foreach ($keys as $key) {
            [$userId, $typeId, $period] = ReviewListService::splitKey($key);
            $groups[$typeId . '|' . $period][$userId] = true;
        }

        $reviewsByKey = $list->reviewsByKey($keys);
        $fulfilment = new FulfilmentService($gDb);
        $rows = array();
        foreach ($groups as $group => $userIds) {
            [$typeId, $period] = explode('|', $group, 2);
            $type = $list->type((int) $typeId);
            if ((int) $type->getValue('rqt_id') === 0) {
                continue;
            }
            $coverage = $fulfilment->coverageForMembers((int) $typeId, $period);
            $byUser = array_column($coverage['members'], null, 'userId');
            foreach (array_keys($userIds) as $userId) {
                $member = $byUser[$userId] ?? $this->emptyMember($userId);
                if ($memberQuery !== '' && mb_stripos((string) $member['name'], $memberQuery) === false) {
                    continue;
                }
                $key = ReviewListService::key($userId, (int) $typeId, $period);
                $rows[] = $this->memberRow($member, $type, $coverage, $period, $reviewsByKey[$key] ?? array());
            }
        }

        usort($rows, static fn(array $a, array $b): int
            => array($a['sortName'], $a['requirementName'], $b['periodStart']) <=> array($b['sortName'], $b['requirementName'], $a['periodStart']));
        return $rows;
    }

    /**
     * A member a requirement does not concern in that period and who booked nothing to it - which
     * a review of theirs can still name.
     *
     * @return array<string,mixed>
     */
    private function emptyMember(int $userId): array
    {
        global $gDb, $gProfileFields;

        $user = new User($gDb, $gProfileFields, $userId);
        return array(
            'userId' => $userId,
            'userUuid' => (string) $user->getValue('usr_uuid'),
            'name' => trim((string) $user->getValue('LAST_NAME') . ', ' . (string) $user->getValue('FIRST_NAME'), ', '),
            'required' => null,
            'attained' => 0.0,
            'pending' => 0.0,
            'percent' => null,
            'status' => 'none',
            'lastFulfilment' => null,
            'nextDue' => null,
            'warnings' => array(),
            'contributions' => array()
        );
    }

    /**
     * One row of the page.
     *
     * @param array<string,mixed> $member as coverageForMembers() returns it
     * @param array<string,mixed> $coverage
     * @param array<int,array<string,mixed>> $reviews the reviews of the row, newest first
     * @return array<string,mixed>
     * @throws Exception
     */
    private function memberRow(array $member, RequirementType $type, array $coverage, string $period, array $reviews): array
    {
        global $gDb, $gL10n;

        $isAdmin = $this->isAdmin();
        $currentUserId = ReviewService::currentUserId();
        $activityIds = array_values(array_unique(array_map(
            static fn(array $contribution): int => (int) $contribution['activityId'],
            $member['contributions']
        )));
        $filesByActivity = ActivityFileService::isEnabled() ? $this->filesFor($activityIds) : array();
        $partsByParent = (new ActivityService($gDb))->findPartCredits($activityIds);

        // A review a reviewer asked for shows nothing of the member's activities until the member
        // has shared them - not to the reviewers, and not to the administrators either: the row only
        // says that the review was requested.
        $awaitingShare = false;
        foreach ($reviews as $review) {
            if ((string) $review['rrv_status'] === Review::STATUS_REQUESTED) {
                $awaitingShare = true;
            }
        }

        $contributions = array();
        $claimedOfCounting = 0.0;
        $canDecide = false;
        foreach ($awaitingShare ? array() : $member['contributions'] as $contribution) {
            $activityId = (int) $contribution['activityId'];
            $viewable = $isAdmin || in_array((int) $contribution['creditId'], $this->viewableCredits($activityId), true);
            if (!$viewable) {
                continue;
            }
            if ($contribution['counts']) {
                $claimedOfCounting += (float) $contribution['claimedCredits'];
            }

            $line = $this->creditLine(
                (int) $contribution['creditId'],
                $activityId,
                (float) $contribution['claimedCredits'],
                $contribution['acceptedCredits'],
                (string) $contribution['reviewStatus'],
                (string) $contribution['reviewComment'],
                (string) $contribution['reviewChanged']
            );
            $canDecide = $canDecide || $line['decidable'];

            $parts = array();
            foreach ($partsByParent[$activityId . '|' . $type->getValue('rqt_id') . '|' . $contribution['periodReference']] ?? array() as $part) {
                // A member's own part is the member's business, not the reviewer's.
                if ((int) $part['rqt_usr_id'] > 0) {
                    continue;
                }
                $partLine = $this->creditLine(
                    (int) $part['rac_id'],
                    $activityId,
                    (float) $part['rac_credits_claimed'],
                    $part['rac_credits_accepted'] === null ? null : (float) $part['rac_credits_accepted'],
                    (string) $part['rac_review_status'],
                    (string) $part['rac_review_comment'],
                    (string) $part['rac_review_changed']
                );
                $partLine['name'] = SecurityUtils::encodeHTML((string) ($part['rqt_short_name'] ?: $part['rqt_name']));
                $canDecide = $canDecide || $partLine['decidable'];
                $parts[] = $partLine;
            }

            $contributions[] = $line + array(
                'title' => SecurityUtils::encodeHTML((string) $contribution['title']),
                'viewUrl' => $this->pageUrl('index.php', array(
                    'mode' => 'view',
                    'uuid' => $contribution['activityUuid'],
                    'requirement' => (string) $type->getValue('rqt_uuid')
                )),
                'date' => $this->formatDate((string) $contribution['date']),
                'periodReference' => $this->formatPeriod($type, (string) $contribution['periodReference']),
                'counts' => (bool) $contribution['counts'],
                'activityStatusText' => in_array((string) $contribution['status'], array('draft', 'withdrawn'), true)
                    ? $gL10n->get('PLG_REQUIREMENTS_STATUS_' . strtoupper((string) $contribution['status'])) : '',
                'files' => $filesByActivity[$activityId] ?? array(),
                'parts' => $parts
            );
        }

        // The reviews of the row: what is under way first, the latest finished one otherwise.
        $active = array_values(array_filter($reviews, static fn(array $r): bool => in_array((string) $r['rrv_status'], Review::ACTIVE_STATUSES, true)));
        $shownReviews = $active !== array() ? $active : array_slice(array_values(array_filter(
            $reviews,
            static fn(array $r): bool => (string) $r['rrv_status'] === Review::STATUS_FINISHED
        )), 0, 1);
        $reviewInfo = array();
        foreach ($shownReviews as $review) {
            $reviewInfo[] = $this->reviewInfo($review);
        }

        $typeId = (int) $type->getValue('rqt_id');
        $calendar = (string) $type->getValue('rqt_period_anchor') === 'calendar';
        $mode = $this->reviews->effectiveMode($typeId);
        $configured = $this->reviews->isConfiguredReviewer();
        [$periodEnd, $periodLabel] = array('', (string) $coverage['periodLabel']);
        if ($calendar && $period !== '') {
            [, $end, $periodLabel] = FulfilmentService::periodFor($type, ReviewService::date($period));
            $periodEnd = $end->format('Y-m-d');
        }

        $attained = (float) $member['attained'];
        $required = $member['required'] === null ? null : (float) $member['required'];
        $pending = (float) $member['pending'];

        $rowKey = $member['userId'] . '_' . $typeId . '_' . str_replace('-', '', $period);
        return array(
            'id' => 'adm_req_review_' . $rowKey,
            'sortName' => mb_strtolower((string) $member['name']),
            'periodStart' => $period,
            'userUuid' => (string) $member['userUuid'],
            'typeUuid' => (string) $type->getValue('rqt_uuid'),
            'period' => $period,
            'name' => SecurityUtils::encodeHTML((string) $member['name']),
            'isSelf' => (int) $member['userId'] === $currentUserId,
            'requirementName' => SecurityUtils::encodeHTML((string) ($type->getValue('rqt_short_name') ?: $type->getValue('rqt_name'))),
            'periodLabel' => SecurityUtils::encodeHTML($periodLabel),
            'activitiesUrl' => $isAdmin && !$awaitingShare ? $this->pageUrl('index.php', array_filter(array(
                'mode' => 'list',
                'requirement' => (string) $type->getValue('rqt_uuid'),
                'user' => (string) $member['userUuid'],
                'period' => $calendar ? $period : ''
            ))) : '',
            'awaitingShare' => $awaitingShare,
            'attained' => self::formatCredits($attained),
            'claimed' => abs($claimedOfCounting - $attained) > 0.005 ? self::formatCredits($claimedOfCounting) : '',
            'pending' => $pending > 0 ? self::formatCredits($pending) : '',
            'required' => $required === null ? '' : self::formatCredits($required),
            'percent' => $member['percent'],
            'pendingPercent' => FulfilmentService::pendingPercent($attained, $pending, (float) $required),
            'status' => (string) $member['status'],
            'statusText' => $gL10n->get(self::statusLabelKey((string) $member['status'])),
            'lastFulfilment' => (string) ($member['lastFulfilment'] ?? ''),
            'nextDue' => (string) ($member['nextDue'] ?? ''),
            'warnings' => array_map(
                static fn(string $warning): string => SecurityUtils::encodeHTML($warning),
                $member['warnings']
            ),
            'reviews' => $reviewInfo,
            'canDecide' => $canDecide,
            'canRequest' => $configured && $calendar && $period !== '' && $mode !== ReviewService::MODE_NONE && $active === array()
                ? $this->pageUrl('review.php', array('mode' => 'request', 'requirement' => (string) $type->getValue('rqt_uuid'),
                    'period' => $period, 'user' => (string) $member['userUuid'])) : '',
            'remindSubmission' => $configured && $mode === ReviewService::MODE_PERIOD && $active === array()
                && $periodEnd !== '' && $periodEnd < DATE_NOW && $reviewInfo === array(),
            'contributions' => $contributions
        );
    }

    /**
     * One credit with its decision, as the inputs of the row show it.
     *
     * @return array<string,mixed>
     */
    private function creditLine(int $creditId, int $activityId, float $claimed, ?float $accepted, string $status, string $comment, string $changed): array
    {
        global $gL10n;

        $decidable = in_array($creditId, $this->decidableCredits($activityId), true);
        return array(
            'creditId' => $creditId,
            'claimed' => self::formatCredits($claimed),
            'claimedRaw' => number_format($claimed, 2, '.', ''),
            'acceptedValue' => $accepted === null ? '' : self::formatCredits($accepted),
            'reviewStatus' => $status,
            'reviewStatusText' => $status === '' ? '' : $gL10n->get(ReviewNotifier::creditStatusLabelKey($status)),
            'reviewStatusClass' => self::creditStatusClass($status),
            'comment' => SecurityUtils::encodeHTML($comment),
            'changed' => $changed === '' ? '' : $gL10n->get('PLG_REQUIREMENTS_REVIEW_CHANGED_SINCE', array($this->formatDate($changed))),
            'decidable' => $decidable
        );
    }

    /**
     * What the Review column says about one review, and what can be done with it from the row.
     *
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private function reviewInfo(array $review): array
    {
        global $gDb, $gL10n, $gProfileFields;

        $status = (string) $review['rrv_status'];
        $reviewer = '';
        if ((int) $review['rrv_reviewer_user_id'] > 0) {
            $reviewer = (new User($gDb, $gProfileFields, (int) $review['rrv_reviewer_user_id']))->readableName();
        } elseif ((int) $review['rrv_reviewer_role_id'] > 0) {
            $reviewer = (string) (new \Admidio\Roles\Entity\Role($gDb, (int) $review['rrv_reviewer_role_id']))->getValue('rol_name');
        }
        $due = (string) ($review['rrv_due_date'] ?? '');
        $handles = $this->reviews->mayHandleReview($review);

        return array(
            'uuid' => (string) $review['rrv_uuid'],
            'status' => $status,
            'statusText' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_STATE_' . strtoupper($status)),
            'statusClass' => match ($status) {
                Review::STATUS_REQUESTED => 'text-bg-info',
                Review::STATUS_OPEN => 'text-bg-primary',
                Review::STATUS_CLARIFICATION => 'text-bg-warning',
                Review::STATUS_FINISHED => 'text-bg-success',
                default => 'text-bg-secondary'
            },
            'kindText' => (string) $review['rrv_kind'] === Review::KIND_REQUEST
                ? $gL10n->get('PLG_REQUIREMENTS_REVIEW_KIND_REQUEST') : $gL10n->get('PLG_REQUIREMENTS_REVIEW_KIND_SUBMISSION'),
            'isActivity' => (int) $review['rrv_activity_id'] > 0,
            'reviewer' => SecurityUtils::encodeHTML($reviewer),
            'due' => $due === '' ? '' : $this->formatDate($due),
            'overdue' => $due !== '' && $due < DATE_NOW && $status === Review::STATUS_REQUESTED,
            'message' => SecurityUtils::encodeHTML((string) ($review['rrv_message'] ?? '')),
            'resultComment' => SecurityUtils::encodeHTML((string) ($review['rrv_result_comment'] ?? '')),
            'canFinish' => $handles && in_array($status, array(Review::STATUS_OPEN, Review::STATUS_CLARIFICATION), true),
            'canRemind' => $handles && in_array($status, array(Review::STATUS_REQUESTED, Review::STATUS_CLARIFICATION), true),
            'canCancel' => in_array($status, Review::ACTIVE_STATUSES, true)
                && ($this->reviews->isConfiguredReviewer() || ReviewService::mayAdministrate())
        );
    }

    /**
     * @return array<int,int>
     */
    private function viewableCredits(int $activityId): array
    {
        return $this->viewable[$activityId] ??= $this->reviews->reviewableCreditIds($activityId, null, false);
    }

    /**
     * @return array<int,int>
     */
    private function decidableCredits(int $activityId): array
    {
        return $this->decidable[$activityId] ??= $this->reviews->reviewableCreditIds($activityId, null, true);
    }

    /**
     * The statuses a reviewer can set on a credit. Empty follows the amount entered.
     *
     * @return array<string,string>
     */
    private function decisionOptions(): array
    {
        global $gL10n;

        return array(
            '' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_STATUS_BY_AMOUNT'),
            ReviewService::CREDIT_OPEN => $gL10n->get('PLG_REQUIREMENTS_REVIEW_OPEN'),
            ReviewService::CREDIT_ACCEPTED => $gL10n->get('PLG_REQUIREMENTS_REVIEW_ACCEPTED'),
            ReviewService::CREDIT_PARTIALLY_ACCEPTED => $gL10n->get('PLG_REQUIREMENTS_REVIEW_PARTIALLY_ACCEPTED'),
            ReviewService::CREDIT_NOT_ACCEPTED => $gL10n->get('PLG_REQUIREMENTS_REVIEW_NOT_ACCEPTED'),
            ReviewService::CREDIT_CLARIFICATION => $gL10n->get('PLG_REQUIREMENTS_REVIEW_CLARIFICATION')
        );
    }

    public static function creditStatusClass(string $status): string
    {
        return match ($status) {
            ReviewService::CREDIT_ACCEPTED => 'text-bg-success',
            ReviewService::CREDIT_PARTIALLY_ACCEPTED => 'text-bg-warning',
            ReviewService::CREDIT_NOT_ACCEPTED => 'text-bg-danger',
            ReviewService::CREDIT_CLARIFICATION => 'text-bg-info',
            ReviewService::CREDIT_OPEN => 'text-bg-primary',
            default => 'text-bg-light'
        };
    }

    /**
     * The filter card above the table, and the summary of what it produced.
     *
     * @param array<int,array<string,mixed>> $allTypes
     * @param array<string,mixed>|null $selectedType
     * @param array<int,array<string,mixed>> $rows
     * @throws Exception|\Smarty\Exception
     */
    private function assignFilter(
        array $allTypes,
        ?array $selectedType,
        string $anchor,
        string $kind,
        string $periodDate,
        string $status,
        string $memberQuery,
        array $rows,
        bool $isReviewFilter,
        string $periodLabel
    ): void {
        global $gL10n;

        $typeOptions = array();
        foreach ($allTypes as $type) {
            $typeOptions[] = array(
                'uuid' => $type['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $type['rqt_name']),
                'selected' => $selectedType !== null && $type['rqt_uuid'] === $selectedType['rqt_uuid']
            );
        }

        $reviewEntries = array();
        if (ReviewService::isEnabled()) {
            foreach (ReviewListService::REVIEW_FILTERS as $option) {
                $reviewEntries[] = array(
                    'value' => $option,
                    'name' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_FILTER_' . strtoupper($option)),
                    'selected' => $option === $status
                );
            }
        }
        $coverageEntries = array();
        $mayRoster = $this->isAdmin() || $this->reviews->isConfiguredReviewer();
        foreach ($mayRoster ? self::STATUS_FILTERS[$anchor] : array() as $option) {
            $coverageEntries[] = array(
                'value' => self::COVERAGE_PREFIX . $option,
                'name' => $gL10n->get(self::statusLabelKey($option)),
                'selected' => self::COVERAGE_PREFIX . $option === $status
            );
        }

        $fulfilled = 0;
        $open = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'fulfilled') {
                ++$fulfilled;
            } elseif (in_array($row['status'], array('missing', 'overdue', 'never-fulfilled'), true)) {
                ++$open;
            }
        }

        $parts = $periodDate === '' ? array('value' => '', 'quarter' => '')
            : FulfilmentService::periodControlValue($kind, $periodDate);

        $this->assignSmartyVariable('noRequirements', '');
        $this->assignSmartyVariable('filterUrl', $this->plugin->getUrl('review.php'));
        $this->assignSmartyVariable('typeOptions', $typeOptions);
        $this->assignSmartyVariable('allowAllTypes', $isReviewFilter);
        $this->assignSmartyVariable('reviewEntries', $reviewEntries);
        $this->assignSmartyVariable('coverageEntries', $coverageEntries);
        $this->assignSmartyVariable('allMembersSelected', $status === '');
        $this->assignSmartyVariable('mayRoster', $mayRoster);
        $this->assignSmartyVariable('memberQuery', SecurityUtils::encodeHTML($memberQuery));
        $this->assignSmartyVariable('periodKind', $kind);
        $this->assignSmartyVariable('periodValue', SecurityUtils::encodeHTML($parts['value']));
        $this->assignSmartyVariable('quarterValue', $parts['quarter']);
        $this->assignSmartyVariable('maxYear', (string) ((int) date('Y') + 1));
        $this->assignSmartyVariable('periodOptional', $isReviewFilter);
        // A recurring requirement has no period to pick; the date says which day the cycles are
        // judged as of, which is a different question and gets a different label.
        $this->assignSmartyVariable('periodLabel', $anchor === 'calendar'
            ? $gL10n->get('SYS_PERIOD')
            : $gL10n->get('PLG_REQUIREMENTS_REVIEW_AS_OF'));
        $this->assignSmartyVariable('summary', $isReviewFilter
            ? $gL10n->get('PLG_REQUIREMENTS_REVIEW_SUMMARY_ROWS', array(count($rows)))
            : $gL10n->get('PLG_REQUIREMENTS_REVIEW_SUMMARY', array(count($rows), $fulfilled, $open)));
        // Admidio's Smarty does not escape by default, so a value that reaches a template escapes
        // itself on the way in.
        $this->assignSmartyVariable('coverageHeadline', $isReviewFilter || $selectedType === null
            ? $gL10n->get('PLG_REQUIREMENTS_REVIEW_FILTER_' . strtoupper($status === '' ? 'all_reviews' : $status))
            : $gL10n->get('PLG_REQUIREMENTS_REVIEW_FOR', array(
                SecurityUtils::encodeHTML((string) $selectedType['rqt_name']),
                SecurityUtils::encodeHTML($periodLabel)
            )));
        $this->addHtmlByTemplate('requirements.review.filter.tpl');
    }

    /**
     * The script of the rows: toggling the activities, following the amount with the status,
     * accepting everything as claimed, saving a row, finishing a review and the reminders.
     */
    private function rowScript(): string
    {
        global $gL10n;

        $config = array(
            'saveUrl' => $this->pageUrl('review.php', array('mode' => 'save-row')),
            'finishUrl' => $this->pageUrl('review.php', array('mode' => 'finish')),
            'remindUrl' => $this->pageUrl('review.php', array('mode' => 'remind')),
            'cancelUrl' => $this->pageUrl('review.php', array('mode' => 'cancel')),
            'cancelConfirm' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_CANCEL_CONFIRM'),
            'csrfToken' => $GLOBALS['gCurrentSession']->getCsrfToken(),
            'saved' => $gL10n->get('SYS_SAVE_DATA'),
            'unsaved' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_UNSAVED'),
            'sent' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_REMINDER_SENT')
        );

        return '
            const admReqReview = ' . json_encode($config) . ';
            function admReqReviewAlert(row, ok, text) {
                const box = row.find(".adm-req-row-alert");
                box.removeClass("text-success text-danger").addClass(ok ? "text-success" : "text-danger").text(text).show();
            }
            function admReqReviewDirty(row, dirty) {
                row.toggleClass("adm-req-dirty", dirty);
                row.find(".adm-req-save").toggleClass("btn-primary", dirty).toggleClass("btn-outline-primary", !dirty);
            }
            // The status follows the amount until the reviewer chooses one.
            $(document).on("input", ".adm-req-accepted", function () {
                const line = $(this).closest(".adm-req-credit");
                const select = line.find(".adm-req-decision");
                admReqReviewDirty($(this).closest("tbody.adm-req-review-row"), true);
                if (select.attr("data-manual") === "1") { return; }
                const value = $(this).val();
                const claimed = parseFloat(line.attr("data-claimed"));
                if (value === "") { select.val(""); return; }
                const accepted = parseFloat(value);
                select.val(accepted <= 0 ? "not_accepted" : (accepted + 0.005 < claimed ? "partially_accepted" : "accepted"));
            });
            $(document).on("change", ".adm-req-decision", function () {
                $(this).attr("data-manual", "1");
                const line = $(this).closest(".adm-req-credit");
                const amount = line.find(".adm-req-accepted");
                if ($(this).val() === "accepted" && amount.val() === "") { amount.val(line.attr("data-claimed")); }
                if ($(this).val() === "not_accepted" && amount.val() === "") { amount.val("0"); }
                admReqReviewDirty($(this).closest("tbody.adm-req-review-row"), true);
            });
            $(document).on("input", ".adm-req-comment", function () {
                admReqReviewDirty($(this).closest("tbody.adm-req-review-row"), true);
            });
            $(document).on("click", ".adm-req-accept-all", function () {
                const row = $(this).closest("tbody.adm-req-review-row");
                row.find(".adm-req-credit").each(function () {
                    const line = $(this);
                    const status = line.attr("data-status");
                    if (line.find(".adm-req-accepted").length === 0 || (status !== "" && status !== "open")) { return; }
                    line.find(".adm-req-accepted").val(line.attr("data-claimed"));
                    line.find(".adm-req-decision").val("accepted");
                });
                bootstrap.Collapse.getOrCreateInstance(row.find(".adm-req-contributions")[0], {toggle: false}).show();
                admReqReviewDirty(row, true);
            });
            function admReqReviewSave(row, then) {
                const data = {
                    adm_csrf_token: admReqReview.csrfToken,
                    user: row.attr("data-user"),
                    requirement: row.attr("data-requirement"),
                    period: row.attr("data-period")
                };
                row.find(".adm-req-credit").each(function () {
                    const line = $(this);
                    if (line.find(".adm-req-accepted").length === 0) { return; }
                    const id = line.attr("data-credit");
                    data["accepted[" + id + "]"] = line.find(".adm-req-accepted").val();
                    data["status[" + id + "]"] = line.find(".adm-req-decision").val();
                    data["comment[" + id + "]"] = line.find(".adm-req-comment").val();
                });
                row.find(".adm-req-save").prop("disabled", true);
                $.post(admReqReview.saveUrl, data, null, "json").done(function (result) {
                    if (result.status === "success") {
                        const open = row.find(".adm-req-contributions").hasClass("show");
                        const replacement = $(result.html);
                        row.replaceWith(replacement);
                        if (open) { replacement.find(".adm-req-contributions").addClass("show"); }
                        replacement.find("[data-bs-toggle=\"tooltip\"]").each(function () { new bootstrap.Tooltip(this); });
                        admReqReviewAlert(replacement, true, admReqReview.saved);
                        if (then) { then(replacement); }
                    } else {
                        admReqReviewAlert(row, false, result.message);
                        row.find(".adm-req-save").prop("disabled", false);
                    }
                }).fail(function (xhr) {
                    let message = xhr.statusText;
                    try { message = JSON.parse(xhr.responseText).message || message; } catch (e) {}
                    admReqReviewAlert(row, false, message);
                    row.find(".adm-req-save").prop("disabled", false);
                });
            }
            $(document).on("click", ".adm-req-save", function () {
                admReqReviewSave($(this).closest("tbody.adm-req-review-row"));
            });
            $(document).on("click", ".adm-req-finish", function () {
                const button = $(this);
                const modal = $("#adm_req_finish_modal");
                modal.find("textarea").val("");
                modal.attr("data-row", button.closest("tbody.adm-req-review-row").attr("id"));
                modal.attr("data-review", button.attr("data-review"));
                bootstrap.Modal.getOrCreateInstance(modal[0]).show();
            });
            $("#adm_req_finish_confirm").on("click", function () {
                const modal = $("#adm_req_finish_modal");
                const row = $("#" + modal.attr("data-row"));
                const finish = function (current) {
                    $.post(admReqReview.finishUrl, {
                        adm_csrf_token: admReqReview.csrfToken,
                        review: modal.attr("data-review"),
                        comment: modal.find("textarea").val(),
                        user: current.attr("data-user"),
                        requirement: current.attr("data-requirement"),
                        period: current.attr("data-period")
                    }, null, "json").done(function (result) {
                        bootstrap.Modal.getOrCreateInstance(modal[0]).hide();
                        if (result.status === "success") {
                            current.replaceWith($(result.html));
                        } else {
                            admReqReviewAlert(current, false, result.message);
                        }
                    }).fail(function (xhr) {
                        bootstrap.Modal.getOrCreateInstance(modal[0]).hide();
                        let message = xhr.statusText;
                        try { message = JSON.parse(xhr.responseText).message || message; } catch (e) {}
                        admReqReviewAlert(current, false, message);
                    });
                };
                if (row.hasClass("adm-req-dirty")) { admReqReviewSave(row, finish); } else { finish(row); }
            });
            $(document).on("click", ".adm-req-cancel", function () {
                const button = $(this);
                const row = button.closest("tbody.adm-req-review-row");
                if (!window.confirm(admReqReview.cancelConfirm)) { return; }
                $.post(admReqReview.cancelUrl, {
                    adm_csrf_token: admReqReview.csrfToken,
                    review: button.attr("data-review"),
                    user: row.attr("data-user"),
                    requirement: row.attr("data-requirement"),
                    period: row.attr("data-period")
                }, null, "json").done(function (result) {
                    if (result.status === "success") {
                        if (result.html) { row.replaceWith($(result.html)); } else { row.remove(); }
                    } else {
                        admReqReviewAlert(row, false, result.message);
                    }
                }).fail(function (xhr) {
                    let message = xhr.statusText;
                    try { message = JSON.parse(xhr.responseText).message || message; } catch (e) {}
                    admReqReviewAlert(row, false, message);
                });
            });
            $(document).on("click", ".adm-req-remind", function () {
                const button = $(this);
                const row = button.closest("tbody.adm-req-review-row");
                $.post(admReqReview.remindUrl, {
                    adm_csrf_token: admReqReview.csrfToken,
                    review: button.attr("data-review") || "",
                    user: row.attr("data-user"),
                    requirement: row.attr("data-requirement"),
                    period: row.attr("data-period")
                }, null, "json").done(function (result) {
                    admReqReviewAlert(row, result.status === "success", result.status === "success" ? admReqReview.sent : result.message);
                }).fail(function (xhr) {
                    let message = xhr.statusText;
                    try { message = JSON.parse(xhr.responseText).message || message; } catch (e) {}
                    admReqReviewAlert(row, false, message);
                });
            });
            $("#adm_req_toggle_all").on("click", function () {
                const button = $(this);
                const open = button.attr("data-open") === "1";
                document.querySelectorAll(".adm-req-contributions").forEach(function (element) {
                    const collapse = bootstrap.Collapse.getOrCreateInstance(element, {toggle: false});
                    if (open) { collapse.hide(); } else { collapse.show(); }
                });
                button.attr("data-open", open ? "0" : "1");
                button.find(".adm-req-toggle-label").text(button.attr(open ? "data-label-expand" : "data-label-collapse"));
            });
            window.addEventListener("beforeunload", function (e) {
                if ($("tbody.adm-req-dirty").length > 0) { e.preventDefault(); e.returnValue = admReqReview.unsaved; }
            });
        ';
    }

    /**
     * The form a reviewer asks members for their activities with (use case D): requirement,
     * period, members - picked, or drawn at random - due date, message and reviewer.
     *
     * @throws Exception|\Smarty\Exception
     */
    public function createRequestForm(string $requirementUuid, string $period, string $userUuid): void
    {
        global $gDb, $gL10n, $gCurrentSession, $gCurrentOrgId, $gProfileFields;

        if (!$this->reviews->isConfiguredReviewer()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $this->setHtmlID('adm_requirements_review_request');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_REVIEWS'));

        $types = array();
        foreach ((new RequirementTypeService($gDb))->findTypes() as $type) {
            if ((int) $type['rqt_usr_id'] === 0 && (int) $type['rqt_parent_id'] === 0
                && (string) $type['rqt_period_anchor'] === 'calendar'
                && $this->reviews->effectiveMode((int) $type['rqt_id']) !== ReviewService::MODE_NONE) {
                $types[(string) $type['rqt_uuid']] = (string) $type['rqt_name'];
            }
        }
        if ($types === array()) {
            throw new Exception('PLG_REQUIREMENTS_REVIEW_NOT_REQUESTABLE');
        }

        $form = new FormPresenter(
            'adm_requirements_review_request_form',
            'requirements.review.request.tpl',
            $this->pageUrl('review.php', array('mode' => 'request-save')),
            $this
        );
        $form->addSelectBox('requirement', $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'), $types, array(
            'defaultValue' => isset($types[$requirementUuid]) ? $requirementUuid : array_key_first($types),
            'showContextDependentFirstEntry' => false,
            'property' => FormPresenter::FIELD_REQUIRED
        ));
        $lastYear = (new DateTimeImmutable(DATE_NOW))->modify('-1 year')->format('Y-m-d');
        $form->addInput('period', $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_PERIOD'), $period !== '' ? $period : $lastYear,
            array('type' => 'date', 'property' => FormPresenter::FIELD_REQUIRED, 'helpTextId' => 'PLG_REQUIREMENTS_REVIEW_REQUEST_PERIOD_HELP'));
        $form->addInput('periods_back', $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_PERIODS'), '1',
            array('type' => 'number', 'minNumber' => 1, 'maxNumber' => 10, 'step' => 1,
                'helpTextId' => 'PLG_REQUIREMENTS_REVIEW_REQUEST_PERIODS_HELP'));

        $memberSql = $this->memberSql();
        $defaultMembers = array();
        if ($userUuid !== '') {
            $user = new User($gDb, $gProfileFields);
            $user->readDataByUuid($userUuid);
            if ((int) $user->getValue('usr_id') > 0) {
                $defaultMembers[] = (int) $user->getValue('usr_id');
            }
        }
        $form->addSelectBoxFromSql('members', $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_MEMBERS'), $gDb, $memberSql, array(
            'multiselect' => true,
            'defaultValue' => $defaultMembers,
            'showContextDependentFirstEntry' => false,
            'helpTextId' => 'PLG_REQUIREMENTS_REVIEW_REQUEST_MEMBERS_HELP'
        ));
        $form->addInput('sample', $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_SAMPLE'), '0',
            array('type' => 'number', 'minNumber' => 0, 'maxNumber' => 10000, 'step' => 1,
                'helpTextId' => 'PLG_REQUIREMENTS_REVIEW_REQUEST_SAMPLE_HELP'));
        $form->addInput('due_date', $gL10n->get('PLG_REQUIREMENTS_REVIEW_DUE_DATE'),
            (new DateTimeImmutable(DATE_NOW))->modify('+30 days')->format('Y-m-d'), array('type' => 'date'));
        $form->addMultilineTextInput('message', $gL10n->get('SYS_MESSAGE'), '', 4);
        self::addReviewerChoice($form, $this->reviews, true);
        $form->addSubmitButton('adm_button_save', $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_SEND'), array('icon' => 'bi-envelope-paper'));

        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * The members of the organization, by name, for a selection.
     *
     * @return array{query:string,params:array<int,mixed>}
     */
    public function memberSql(): array
    {
        global $gCurrentOrgId, $gProfileFields;

        return array('query' => 'SELECT DISTINCT usr.usr_id, CONCAT(last_name.usd_value, \', \', first_name.usd_value) AS name
                  FROM ' . TBL_USERS . ' AS usr
            INNER JOIN ' . TBL_MEMBERS . ' AS mem ON mem.mem_usr_id = usr.usr_id
            INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id
            INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
             LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                    ON last_name.usd_usr_id = usr.usr_id AND last_name.usd_usf_id = ?
             LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                    ON first_name.usd_usr_id = usr.usr_id AND first_name.usd_usf_id = ?
                 WHERE usr.usr_valid = true
                   AND mem.mem_begin <= ?
                   AND mem.mem_end >= ?
                   AND cat.cat_org_id = ?
              ORDER BY name', 'params' => array(
            (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
            (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            DATE_NOW,
            DATE_NOW,
            (int) $gCurrentOrgId
        ));
    }

    /**
     * The reviewer selection of a form, as the settings allow it: none when every review goes to the
     * configured reviewers, one of them, or any member or role of the organization.
     *
     * @throws Exception
     */
    public static function addReviewerChoice(FormPresenter $form, ReviewService $reviews, bool $forRequest = false): bool
    {
        global $gDb, $gL10n, $gProfileFields, $gCurrentOrgId;

        $choice = ReviewService::reviewerChoice();
        if ($choice === ReviewService::CHOICE_CONFIGURED) {
            return false;
        }

        $options = array('' => $gL10n->get('PLG_REQUIREMENTS_REVIEWER_CONFIGURED'));
        $self = ReviewService::currentUserId();
        $userIds = $choice === ReviewService::CHOICE_ANY ? null : $reviews->configuredReviewerIds();
        $roleIds = $choice === ReviewService::CHOICE_ANY ? null : ReviewService::reviewerRoleIds();

        $users = $gDb->getArrayFromSql(
            'SELECT DISTINCT usr.usr_id, CONCAT(last_name.usd_value, \', \', first_name.usd_value) AS name
               FROM ' . TBL_USERS . ' AS usr
         INNER JOIN ' . TBL_MEMBERS . ' AS mem ON mem.mem_usr_id = usr.usr_id
         INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id
         INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
          LEFT JOIN ' . TBL_USER_DATA . ' AS last_name ON last_name.usd_usr_id = usr.usr_id AND last_name.usd_usf_id = ?
          LEFT JOIN ' . TBL_USER_DATA . ' AS first_name ON first_name.usd_usr_id = usr.usr_id AND first_name.usd_usf_id = ?
              WHERE usr.usr_valid = true AND mem.mem_begin <= ? AND mem.mem_end >= ? AND cat.cat_org_id = ?
           ORDER BY name',
            array((int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
                (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'), DATE_NOW, DATE_NOW, (int) $gCurrentOrgId)
        );
        foreach ($users as $user) {
            if ((int) $user['usr_id'] === $self && !$forRequest) {
                continue;
            }
            if ($userIds === null || in_array((int) $user['usr_id'], $userIds, true)) {
                $options['u' . $user['usr_id']] = (string) $user['name'];
            }
        }
        $roles = $gDb->getArrayFromSql(
            'SELECT rol.rol_id, rol.rol_name
               FROM ' . TBL_ROLES . ' AS rol
         INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
              WHERE rol.rol_valid = true AND rol.rol_system = false AND cat.cat_org_id = ? AND cat.cat_name_intern <> ?
           ORDER BY rol.rol_name',
            array((int) $gCurrentOrgId, 'EVENTS')
        );
        foreach ($roles as $role) {
            if ($roleIds === null || in_array((int) $role['rol_id'], $roleIds, true)) {
                $options['r' . $role['rol_id']] = $gL10n->get('SYS_ROLE') . ': ' . (string) $role['rol_name'];
            }
        }

        $form->addSelectBox('reviewer', $gL10n->get('PLG_REQUIREMENTS_REVIEWER'), $options, array(
            'defaultValue' => '',
            'showContextDependentFirstEntry' => false,
            'helpTextId' => 'PLG_REQUIREMENTS_REVIEWER_HELP'
        ));
        return true;
    }

    /**
     * The reviewer a form submitted: "u12" is a member, "r3" a role.
     *
     * @return array{0:int,1:int}
     */
    public static function parseReviewerChoice(string $value): array
    {
        if (preg_match('/^u(\d+)$/', $value, $match) === 1) {
            return array((int) $match[1], 0);
        }
        if (preg_match('/^r(\d+)$/', $value, $match) === 1) {
            return array(0, (int) $match[1]);
        }
        return array(0, 0);
    }

    /**
     * The certificate files of these activities, as download links keyed by activity id.
     *
     * @param array<int,int> $activityIds
     * @return array<int,array<int,array{name:string,url:string}>>
     * @throws Exception
     */
    private function filesFor(array $activityIds): array
    {
        global $gDb;

        $byActivity = array();
        foreach ((new ActivityFileService($gDb))->listForActivities($activityIds) as $activityId => $files) {
            foreach ($files as $file) {
                $byActivity[$activityId][] = array(
                    'name' => SecurityUtils::encodeHTML((string) $file['raf_name']),
                    'url' => $this->pageUrl('file.php', array('uuid' => $file['raf_uuid']))
                );
            }
        }

        return $byActivity;
    }

    private function formatDate(string $value): string
    {
        global $gSettingsManager;

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date === false ? $value : $date->format($gSettingsManager->getString('system_date'));
    }

    private function formatPeriod(RequirementType $type, string $reference): string
    {
        if ($reference === '') {
            return '';
        }
        $parts = FulfilmentService::periodControlValue(FulfilmentService::periodControlKind($type), $reference);
        return $parts['quarter'] !== '' ? $parts['value'] . ' Q' . $parts['quarter'] : $parts['value'];
    }

    /**
     * The translation key a coverage status reads as. The statuses themselves are the calculator's
     * vocabulary (§8.1); this is the only place that turns them into a sentence.
     */
    public static function statusLabelKey(string $status): string
    {
        return match ($status) {
            'fulfilled' => 'PLG_REQUIREMENTS_COVERAGE_FULFILLED',
            'missing' => 'PLG_REQUIREMENTS_COVERAGE_MISSING',
            'overdue' => 'PLG_REQUIREMENTS_COVERAGE_OVERDUE',
            'never-fulfilled' => 'PLG_REQUIREMENTS_COVERAGE_NEVER_FULFILLED',
            'no-credits-required' => 'PLG_REQUIREMENTS_COVERAGE_NO_CREDITS_REQUIRED',
            'additional-credits' => 'PLG_REQUIREMENTS_COVERAGE_ADDITIONAL_CREDITS',
            'in-progress' => 'PLG_REQUIREMENTS_COVERAGE_MISSING',
            default => 'PLG_REQUIREMENTS_COVERAGE_NONE'
        };
    }

    /**
     * The coverage statuses that may be filtered for a requirement of this anchor.
     *
     * @return array<int,string>
     */
    public static function statusFilters(string $anchor): array
    {
        return self::STATUS_FILTERS[$anchor] ?? self::STATUS_FILTERS['calendar'];
    }
}
