<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use Throwable;

final class ActivityService
{
    /** The date columns of an activity, mapped to the label an error message names them by. */
    private const DATE_FIELDS = array(
        'rqa_begin_date' => 'PLG_REQUIREMENTS_BEGIN_DATE',
        'rqa_end_date' => 'SYS_END',
        'rqa_certificate_date' => 'PLG_REQUIREMENTS_CERTIFICATE_DATE'
    );

    /** The time columns, likewise. */
    private const TIME_FIELDS = array(
        'rqa_begin_time' => 'SYS_START',
        'rqa_end_time' => 'SYS_END'
    );

    public const STATUSES = array('draft', 'submitted', 'withdrawn');
    public const REVIEW_STATUSES = array('', 'open', 'accepted', 'partially_accepted', 'not_accepted', 'clarification');

    /** Nesting depth of credit writes whose sub-requirement check waits for the end of the batch. */
    private int $deferredChecks = 0;

    public function __construct(private readonly Database $db)
    {
    }

    public function save(Activity $activity, array $values, ?int $targetUserId = null): Activity
    {
        global $gCurrentOrgId, $gCurrentUser;

        $isNew = $activity->isNewRecord();
        if ($isNew && !RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if (!$isNew && !$activity->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if ($isNew) {
            $targetUserId ??= (int)$gCurrentUser->getValue('usr_id');
            if ($targetUserId !== (int)$gCurrentUser->getValue('usr_id')
                && !Component::isAdministrable(RequirementsPlugin::ID)) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $this->assertUserInCurrentOrganization($targetUserId);
            $activity->setValue('rqa_org_id', $gCurrentOrgId);
            $activity->setValue('rqa_user_id', $targetUserId);
            $activity->setValue(
                'rqa_status',
                RequirementsPlugin::setting(RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY)
                    === RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED ? 'draft' : 'submitted'
            );
        } elseif ((int)$activity->getValue('rqa_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        foreach (array(
            'rqa_title', 'rqa_description', 'rqa_url', 'rqa_location',
            'rqa_attendance', 'rqa_comments'
        ) as $field) {
            if (array_key_exists($field, $values)) {
                $activity->setValue($field, is_string($values[$field]) ? trim($values[$field]) : $values[$field]);
            }
        }

        if (trim((string)$activity->getValue('rqa_title', 'database')) === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_TITLE'));
        }
        $this->applyDatesAndTimes($activity, $values, $isNew);

        if (array_key_exists('rqa_provider_id', $values)) {
            $this->setProvider($activity, $values['rqa_provider_id']);
        } elseif ($isNew) {
            $this->setProvider($activity, null);
        }

        if (Component::isAdministrable(RequirementsPlugin::ID)) {
            if (array_key_exists('rqa_status', $values)) {
                $status = (string)$values['rqa_status'];
                if (!in_array($status, self::STATUSES, true)) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $activity->setValue('rqa_status', $status);
            }
            if (array_key_exists('rqa_review_status', $values)) {
                $reviewStatus = (string)$values['rqa_review_status'];
                if (!in_array($reviewStatus, self::REVIEW_STATUSES, true)) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $activity->setValue('rqa_review_status', $reviewStatus === '' ? null : $reviewStatus);
            }
            if (array_key_exists('rqa_review_comments', $values)) {
                $activity->setValue('rqa_review_comments', trim((string)$values['rqa_review_comments']));
            }
        }

        $this->db->startTransaction();
        try {
            $activity->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $activity;
    }

    /**
     * Save an activity together with all of its credited-period contributions, as one unit.
     *
     * The activity and its credits are one thing to the member who entered them, and concept §14
     * requires them to roll back as one. Saving each row on its own would leave the activity and the
     * first few credits committed when a later one is rejected. The database counts nested
     * transactions, so the inner save()/syncCredit() calls join this one instead of committing.
     *
     * @param array<int,array{period:string,quarter:string,claimed:?float,accepted:?float}> $creditInput
     *        keyed by requirement type id; `period`/`quarter` are the raw values of the
     *        credited-period control, resolved here because that needs the requirement type.
     * @throws Exception
     */
    public function saveWithCredits(
        Activity $activity,
        array $values,
        array $creditInput,
        ?int $targetUserId = null
    ): Activity {
        $this->db->startTransaction();
        ++$this->deferredChecks;
        try {
            $this->save($activity, $values, $targetUserId);

            foreach ($creditInput as $typeId => $input) {
                $type = new RequirementType($this->db, (int) $typeId);
                if ((int) $type->getValue('rqt_id') === 0) {
                    throw new Exception('SYS_NO_RIGHTS');
                }

                // Only resolve the period control when a value is actually being written; an
                // untouched row must not fail validation.
                $periodDate = null;
                if ($input['period'] !== '' && ($input['claimed'] !== null || $input['accepted'] !== null)) {
                    $periodDate = FulfilmentService::periodInputToDate($type, $input['period'], $input['quarter']);
                }

                $this->syncCredit($activity, $type, $periodDate, $input['claimed'], $input['accepted']);
            }

            // Whatever order the rows came in, the parts have to fit their parents once all are written.
            $this->assertSubRequirements($activity);
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        } finally {
            --$this->deferredChecks;
        }

        return $activity;
    }

    /**
     * Run a series of credit writes of one activity as one unit, and check the sub-requirements
     * once at the end instead of after every row - a part may be written before its parent.
     *
     * @throws Exception
     */
    public function withinCreditBatch(Activity $activity, callable $writes): void
    {
        $this->db->startTransaction();
        ++$this->deferredChecks;
        try {
            $writes();
            $this->assertSubRequirements($activity);
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        } finally {
            --$this->deferredChecks;
        }
    }

    /**
     * A sub-requirement is a part of its parent: on the same activity and in the same period its
     * credits need a parent credit, and neither the claimed nor the effective accepted amount of
     * the part may exceed that of the parent ("25 hours recognised, 15 of them in person").
     *
     * @throws Exception
     */
    public function assertSubRequirements(Activity $activity): void
    {
        $rows = $this->db->getArrayFromSql(
            'SELECT rac.rac_requirement_type_id, rac.rac_period_reference,
                    rac.rac_credits_claimed, rac.rac_credits_accepted,
                    rqt.rqt_parent_id, rqt.rqt_name, rpt.rqt_name AS parent_name
               FROM ' . RequirementsTables::activityCredits() . ' AS rac
         INNER JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt ON rqt.rqt_id = rac.rac_requirement_type_id
          LEFT JOIN ' . RequirementsTables::requirementTypes() . ' AS rpt ON rpt.rqt_id = rqt.rqt_parent_id
              WHERE rac.rac_activity_id = ?',
            array((int) $activity->getValue('rqa_id'))
        );

        $byKey = array();
        foreach ($rows as $row) {
            $byKey[$row['rac_requirement_type_id'] . '|' . $row['rac_period_reference']] = $row;
        }
        $effective = static fn(array $row): float => $row['rac_credits_accepted'] === null
            ? (float) $row['rac_credits_claimed'] : (float) $row['rac_credits_accepted'];

        foreach ($rows as $row) {
            if ((int) $row['rqt_parent_id'] === 0) {
                continue;
            }
            $parent = $byKey[$row['rqt_parent_id'] . '|' . $row['rac_period_reference']] ?? null;
            if ($parent === null) {
                throw new Exception('PLG_REQUIREMENTS_THEREOF_NEEDS_PARENT', array($row['rqt_name'], $row['parent_name']));
            }
            if ((float) $row['rac_credits_claimed'] > (float) $parent['rac_credits_claimed'] + 0.005
                || $effective($row) > $effective($parent) + 0.005) {
                throw new Exception('PLG_REQUIREMENTS_THEREOF_EXCEEDS', array($row['rqt_name'], $row['parent_name']));
            }
        }
    }

    public function saveCredit(
        ActivityCredit $credit,
        Activity $activity,
        RequirementType $requirementType,
        string $periodReference,
        float $claimed,
        ?float $accepted = null
    ): ActivityCredit {
        global $gCurrentOrgId;

        if (!$activity->isEditable()
            || (int)$activity->getValue('rqa_id') === 0
            || (int)$activity->getValue('rqa_org_id') !== (int)$gCurrentOrgId
            || (int)$requirementType->getValue('rqt_id') === 0
            || (int)$requirementType->getValue('rqt_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        // A member's own requirement takes credits from their own activities only. This is the one
        // place every credit is written through, the form, the import and the command line alike.
        $owner = RequirementTypeService::ownerOf($requirementType);
        if ($owner !== null && $owner !== (int)$activity->getValue('rqa_user_id')) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if (!$credit->isNewRecord()) {
            if ((int)$credit->getValue('rac_activity_id') !== (int)$activity->getValue('rqa_id')
                || (int)$credit->getValue('rac_requirement_type_id')
                    !== (int)$requirementType->getValue('rqt_id')) {
                throw new Exception('SYS_NO_RIGHTS');
            }
        } elseif (!(bool)$requirementType->getValue('rqt_active')) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        if ($claimed < 0 || ($accepted !== null && $accepted < 0)) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $periodReference = FulfilmentService::normalizePeriodReference($requirementType, $periodReference);

        $credit->setValue('rac_activity_id', (int)$activity->getValue('rqa_id'));
        $credit->setValue('rac_requirement_type_id', (int)$requirementType->getValue('rqt_id'));
        $credit->setValue('rac_period_reference', $periodReference);
        $credit->setValue('rac_credits_claimed', number_format($claimed, 2, '.', ''));

        if (Component::isAdministrable(RequirementsPlugin::ID)) {
            $credit->setValue(
                'rac_credits_accepted',
                $accepted === null ? null : number_format($accepted, 2, '.', '')
            );
        } elseif (!$credit->isNewRecord() && $credit->getValue('rac_credits_accepted', 'database') !== null) {
            // A member editing an existing claim must never clear or replace the reviewer's accepted value.
        } else {
            $credit->setValue('rac_credits_accepted', null);
        }

        $this->db->startTransaction();
        try {
            $credit->save();
            if ($this->deferredChecks === 0) {
                $this->assertSubRequirements($activity);
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $credit;
    }


    public function submit(Activity $activity): void
    {
        global $gCurrentUser;

        if (!$activity->isVisible()
            || (
                !Component::isAdministrable(RequirementsPlugin::ID)
                && (int)$activity->getValue('rqa_user_id') !== (int)$gCurrentUser->getValue('usr_id')
            )) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if ((string)$activity->getValue('rqa_status') !== 'draft') {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $this->db->startTransaction();
        try {
            $activity->setValue('rqa_status', 'submitted');
            $activity->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function delete(Activity $activity): void
    {
        if (!$activity->isDeletable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->db->startTransaction();
        try {
            $activity->delete();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * List activities. A normal member sees only their own; an administrator sees the whole
     * organization, optionally filtered to one user.
     *
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    public function findActivities(
        ?int $userId = null,
        string $query = '',
        ?int $requirementTypeId = null,
        ?array $periodRange = null
    ): array {
        global $gCurrentOrgId, $gCurrentUser, $gProfileFields;

        $creditJoin = '';
        $params = array(
            (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id')
        );
        $creditColumns = 'NULL AS rac_period_reference, NULL AS rac_credits_claimed, NULL AS rac_credits_accepted';
        if ($requirementTypeId !== null) {
            $creditColumns = 'rac.rac_period_reference, rac.rac_credits_claimed, rac.rac_credits_accepted';

            // Narrowed to a requirement, the list is the activities that count towards it, and with
            // a period those that made up that bar: an activity without a credit for it is not
            // part of the answer.
            $creditJoin = ' INNER JOIN ' . RequirementsTables::activityCredits() . ' AS rac
                    ON rac.rac_activity_id = rqa.rqa_id AND rac.rac_requirement_type_id = ?';
            $params[] = $requirementTypeId;

            if ($periodRange !== null) {
                // A range: a period may be a rolling window of several units, and a credit is
                // booked to the single unit it happened in.
                $creditJoin .= ' AND rac.rac_period_reference BETWEEN ? AND ?';
                $params[] = $periodRange[0];
                $params[] = $periodRange[1];
            }
        }

        $sql = 'SELECT rqa.rqa_id, rqa.rqa_uuid, rqa.rqa_org_id, rqa.rqa_user_id, rqa.rqa_title, rqa.rqa_begin_date,
                       rqa.rqa_end_date, rqa.rqa_status, rqa.rqa_review_status,
                       rqa.rqa_provider_name, rqa.rqa_provider_short_name,
                       usr.usr_uuid AS user_uuid,
                       first_name.usd_value AS first_name, last_name.usd_value AS last_name,
                       ' . $creditColumns . '
                  FROM ' . RequirementsTables::activities() . ' AS rqa
            INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = rqa.rqa_user_id
             LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                    ON first_name.usd_usr_id = rqa.rqa_user_id AND first_name.usd_usf_id = ?
             LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                    ON last_name.usd_usr_id = rqa.rqa_user_id AND last_name.usd_usf_id = ?'
             . $creditJoin . '
                 WHERE rqa.rqa_org_id = ?';

        $params[] = (int) $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            $sql .= ' AND rqa.rqa_user_id = ?';
            $params[] = (int) $gCurrentUser->getValue('usr_id');
        } elseif ($userId !== null) {
            $sql .= ' AND rqa.rqa_user_id = ?';
            $params[] = $userId;
        }
        if ($query !== '') {
            $sql .= ' AND (rqa.rqa_title LIKE ? OR rqa.rqa_provider_name LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY rqa.rqa_begin_date DESC, rqa.rqa_title, rqa.rqa_id';

        return $this->db->getArrayFromSql($sql, $params);
    }

    /** The columns the activity table may be sorted by, as the SQL each one sorts on. */
    public const LIST_SORT_COLUMNS = array(
        'period' => array('rac.rac_period_reference', 'rqt.rqt_sequence'),
        'date' => array('rqa.rqa_begin_date', 'rqa.rqa_begin_time'),
        'title' => array('rqa.rqa_title'),
        'requirement' => array('rqt.rqt_sequence', 'rqt.rqt_name'),
        'credits' => array('COALESCE(rac.rac_credits_accepted, rac.rac_credits_claimed)'),
        'provider' => array(self::LIST_PROVIDER_SQL),
        'member' => array('last_name.usd_value', 'first_name.usd_value'),
        'attendance' => array('rqa.rqa_attendance'),
        'certificate' => array('rqa.rqa_certificate_date')
    );

    /**
     * What the table can be grouped by, as the SQL that orders the rows into their groups: dates
     * newest first, as the list itself, names alphabetically. A row without a value (no credit, no
     * certificate date) sorts after all the others. The period groups by requirement as well,
     * because "2026" of a yearly and "2026 Q1" of a quarterly requirement start on the same day.
     */
    public const LIST_GROUP_COLUMNS = array(
        'period' => array('rac.rac_period_reference DESC', 'rqt.rqt_sequence', 'rqt.rqt_id'),
        'provider' => array(self::LIST_PROVIDER_SQL),
        'requirement' => array('rqt.rqt_sequence', 'rqt.rqt_name', 'rqt.rqt_id'),
        'begin' => array('rqa.rqa_begin_date DESC'),
        'end' => array('COALESCE(rqa.rqa_end_date, rqa.rqa_begin_date) DESC'),
        'certificate' => array('rqa.rqa_certificate_date DESC')
    );

    /** The provider name the list shows: the short name where there is one. */
    private const LIST_PROVIDER_SQL = 'COALESCE(NULLIF(rqa.rqa_provider_short_name, \'\'), rqa.rqa_provider_name)';

    /**
     * One page of the activity table, for its server-side DataTables endpoint.
     *
     * A row is one credit of an activity, not the activity: an activity that counts towards two
     * requirements is two rows, because period, requirement and credits are what the list is read
     * for, and an activity has one of each per requirement. An activity without any credit is still
     * one row with those cells empty, so nothing the member entered disappears from their full list.
     * A requirement or a period filter asks which activities count towards it, so they keep only
     * the activities with a credit for it.
     *
     * @param array{userId?:?int,query?:string,requirementTypeId?:?int,periodRange?:?array,
     *     providerId?:?int,dateFrom?:string,dateTo?:string} $filter
     * @param string $groupBy a key of LIST_GROUP_COLUMNS, or '' for no grouping; the groups come
     *     first in the order so that every group is one run of consecutive rows
     * @param array<int,array{0:string,1:string}> $order keys of LIST_SORT_COLUMNS with 'asc'/'desc'
     * @param int $length rows per page, or -1 for all of them
     * @return array{count:int,rows:array<int,array<string,mixed>>}
     * @throws Exception
     */
    public function findActivityRows(
        array $filter,
        string $groupBy = '',
        array $order = array(),
        int $start = 0,
        int $length = -1
    ): array {
        global $gCurrentOrgId, $gCurrentUser, $gProfileFields;

        $params = array(
            (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id')
        );

        $requirementTypeId = $filter['requirementTypeId'] ?? null;
        $periodRange = $filter['periodRange'] ?? null;
        $creditJoin = ($periodRange === null && $requirementTypeId === null ? ' LEFT JOIN ' : ' INNER JOIN ')
            . RequirementsTables::activityCredits() . ' AS rac ON rac.rac_activity_id = rqa.rqa_id';
        if ($requirementTypeId !== null) {
            $creditJoin .= ' AND rac.rac_requirement_type_id = ?';
            $params[] = $requirementTypeId;
        }
        if ($periodRange !== null) {
            // A range: a period may be a rolling window of several units, and a credit is booked to
            // the single unit it happened in.
            $creditJoin .= ' AND rac.rac_period_reference BETWEEN ? AND ?';
            $params[] = $periodRange[0];
            $params[] = $periodRange[1];
        }

        $from = ' FROM ' . RequirementsTables::activities() . ' AS rqa
            INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = rqa.rqa_user_id
             LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                    ON first_name.usd_usr_id = rqa.rqa_user_id AND first_name.usd_usf_id = ?
             LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                    ON last_name.usd_usr_id = rqa.rqa_user_id AND last_name.usd_usf_id = ?'
            . $creditJoin . '
             LEFT JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt
                    ON rqt.rqt_id = rac.rac_requirement_type_id
                 WHERE rqa.rqa_org_id = ?';
        $params[] = (int) $gCurrentOrgId;

        $userId = $filter['userId'] ?? null;
        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            $from .= ' AND rqa.rqa_user_id = ?';
            $params[] = (int) $gCurrentUser->getValue('usr_id');
        } elseif ($userId !== null) {
            $from .= ' AND rqa.rqa_user_id = ?';
            $params[] = $userId;
        }
        $query = trim((string) ($filter['query'] ?? ''));
        if ($query !== '') {
            $from .= ' AND (rqa.rqa_title LIKE ? OR rqa.rqa_provider_name LIKE ? OR rqa.rqa_provider_short_name LIKE ?)';
            array_push($params, '%' . $query . '%', '%' . $query . '%', '%' . $query . '%');
        }
        if (($filter['providerId'] ?? null) !== null) {
            $from .= ' AND rqa.rqa_provider_id = ?';
            $params[] = (int) $filter['providerId'];
        }
        // The date range keeps every activity that touches it, so a course running over the turn of
        // the year is found from either side.
        if (($filter['dateFrom'] ?? '') !== '') {
            $from .= ' AND COALESCE(rqa.rqa_end_date, rqa.rqa_begin_date) >= ?';
            $params[] = $filter['dateFrom'];
        }
        if (($filter['dateTo'] ?? '') !== '') {
            $from .= ' AND rqa.rqa_begin_date <= ?';
            $params[] = $filter['dateTo'];
        }

        $count = (int) $this->db->queryPrepared('SELECT COUNT(*) AS cnt' . $from, $params)->fetchColumn();

        $orderBy = array();
        if (isset(self::LIST_GROUP_COLUMNS[$groupBy])) {
            $orderBy[] = preg_replace('/ DESC$/', '', self::LIST_GROUP_COLUMNS[$groupBy][0]) . ' IS NULL';
            array_push($orderBy, ...self::LIST_GROUP_COLUMNS[$groupBy]);
        }
        foreach ($order as [$column, $direction]) {
            if (!isset(self::LIST_SORT_COLUMNS[$column])) {
                continue;
            }
            $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
            foreach (self::LIST_SORT_COLUMNS[$column] as $expression) {
                $orderBy[] = $expression . ' ' . $direction;
            }
        }
        // A stable tail, so that paging never shows a row twice or skips one.
        array_push($orderBy, 'rqa.rqa_begin_date DESC', 'rqa.rqa_id DESC', 'rac.rac_id');

        $sql = 'SELECT rqa.rqa_id, rqa.rqa_uuid, rqa.rqa_org_id, rqa.rqa_user_id, rqa.rqa_title,
                       rqa.rqa_begin_date, rqa.rqa_end_date, rqa.rqa_certificate_date, rqa.rqa_attendance,
                       rqa.rqa_status, rqa.rqa_review_status, rqa.rqa_provider_name, rqa.rqa_provider_short_name,
                       usr.usr_uuid AS user_uuid,
                       first_name.usd_value AS first_name, last_name.usd_value AS last_name,
                       rac.rac_id, rac.rac_period_reference, rac.rac_credits_claimed, rac.rac_credits_accepted,
                       rqt.rqt_id, rqt.rqt_name, rqt.rqt_short_name, rqt.rqt_period_anchor, rqt.rqt_period_unit'
            . $from . ' ORDER BY ' . implode(', ', $orderBy);
        if ($length > 0) {
            $sql .= ' LIMIT ' . $length . ' OFFSET ' . max(0, $start);
        }

        return array('count' => $count, 'rows' => $this->db->getArrayFromSql($sql, $params));
    }

    /**
     * Whether an activity list may name this member: somebody of this organization, or somebody who
     * has left it but still has an activity here - the population the review view covers (§10.3).
     *
     * The list itself is scoped by `rqa_org_id` and would come back empty for anybody else, but the
     * page also prints the member's name beside it, and a name from another organization is exactly
     * what §7.6 says must not leak.
     *
     * @throws Exception
     */
    public function isKnownMember(int $userId): bool
    {
        global $gCurrentOrgId;

        $user = new User($this->db, null, $userId);
        $user->setOrganization((int) $gCurrentOrgId);
        if ((int) $user->getValue('usr_id') !== 0 && $user->isMemberOfOrganization()) {
            return true;
        }

        return (int) $this->db->queryPrepared(
            'SELECT COUNT(*)
               FROM ' . RequirementsTables::activities() . '
              WHERE rqa_org_id = ?
                AND rqa_user_id = ?',
            array((int) $gCurrentOrgId, $userId)
        )->fetchColumn() > 0;
    }

    /**
     * The credit rows of one activity, keyed by requirement type id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function creditsForActivity(int $activityId): array
    {
        $rows = $this->db->getArrayFromSql(
            'SELECT rac_id, rac_requirement_type_id, rac_period_reference,
                    rac_credits_claimed, rac_credits_accepted
               FROM ' . RequirementsTables::activityCredits() . '
              WHERE rac_activity_id = ?',
            array($activityId)
        );

        $byType = array();
        foreach ($rows as $row) {
            $byType[(int) $row['rac_requirement_type_id']] = $row;
        }
        return $byType;
    }

    /**
     * Create, update or remove the credit contribution of one activity for one requirement type.
     * An empty or non-positive claimed value with no reviewer acceptance removes the row.
     *
     * @throws Exception
     */
    public function syncCredit(
        Activity $activity,
        RequirementType $requirementType,
        ?string $periodReference,
        ?float $claimed,
        ?float $accepted = null
    ): void {
        $existing = $this->creditsForActivity((int) $activity->getValue('rqa_id'));
        $current = $existing[(int) $requirementType->getValue('rqt_id')] ?? null;

        $wantsRow = ($claimed !== null && $claimed > 0.0) || $accepted !== null;

        if (!$wantsRow) {
            if ($current !== null) {
                $this->deleteCredit(new ActivityCredit($this->db, (int) $current['rac_id']), $activity);
            }
            return;
        }

        $credit = new ActivityCredit($this->db, $current === null ? 0 : (int) $current['rac_id']);
        $this->saveCredit(
            $credit,
            $activity,
            $requirementType,
            $periodReference === null || $periodReference === ''
                ? (string) $activity->getValue('rqa_begin_date', 'database')
                : $periodReference,
            $claimed ?? 0.0,
            $accepted
        );
    }

    /**
     * Set the accepted credits of several contributions at once - the write half of the review view.
     *
     * §14 restricts accepted-credit writes to administrators, and the rows go through saveCredit(),
     * so the record checks, the normalisation and the changelog entry are the ones the activity form
     * already produces. What this adds is the transaction around the loop: a review pass is one
     * decision about a period, and half of it landing is worse than none of it.
     *
     * A value that already stands is skipped rather than rewritten, so an untouched row produces no
     * changelog entry and the returned count says what the pass actually changed.
     *
     * @param array<int,float|null> $accepted credit id => accepted credits, null clears the acceptance
     * @return int how many rows changed
     * @throws Exception
     */
    public function acceptCredits(array $accepted): int
    {
        if (!RequirementsPlugin::isUsableByCurrentUser()
            || !Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $changed = 0;
        $touched = array();
        $this->db->startTransaction();
        ++$this->deferredChecks;
        try {
            foreach ($accepted as $creditId => $value) {
                // OrganizationScope answers a credit of another organization with an empty record.
                $credit = new ActivityCredit($this->db, (int) $creditId);
                if ((int) $credit->getValue('rac_id') === 0) {
                    throw new Exception('SYS_NO_RIGHTS');
                }

                $stored = $credit->getValue('rac_credits_accepted', 'database');
                // An unset decimal column reads as '' until the record has been read back.
                $stored = ($stored === null || $stored === '') ? null : (float) $stored;
                if ($stored === $value) {
                    continue;
                }

                $this->saveCredit(
                    $credit,
                    new Activity($this->db, (int) $credit->getValue('rac_activity_id')),
                    new RequirementType($this->db, (int) $credit->getValue('rac_requirement_type_id')),
                    (string) $credit->getValue('rac_period_reference', 'database'),
                    (float) $credit->getValue('rac_credits_claimed', 'database'),
                    $value
                );
                $touched[(int) $credit->getValue('rac_activity_id')] = true;
                ++$changed;
            }
            foreach (array_keys($touched) as $activityId) {
                $this->assertSubRequirements(new Activity($this->db, $activityId));
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        } finally {
            --$this->deferredChecks;
        }

        return $changed;
    }

    /**
     * Remove one credited-period contribution. Editing the activity is what permits it, exactly as
     * for creating or changing the same row.
     *
     * @throws Exception
     */
    public function deleteCredit(ActivityCredit $credit, Activity $activity): void
    {
        global $gCurrentOrgId;

        if ((int) $credit->getValue('rac_id') === 0
            || (int) $activity->getValue('rqa_id') === 0
            || (int) $activity->getValue('rqa_org_id') !== (int) $gCurrentOrgId
            || (int) $credit->getValue('rac_activity_id') !== (int) $activity->getValue('rqa_id')
            || !$activity->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->db->startTransaction();
        try {
            $credit->delete();
            if ($this->deferredChecks === 0) {
                $this->assertSubRequirements($activity);
            }
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    private function setProvider(Activity $activity, mixed $providerId): void
    {
        global $gCurrentOrgId;

        if ($providerId === null || $providerId === '' || (int)$providerId === 0) {
            $activity->setValue('rqa_provider_id', null);
            $activity->setValue('rqa_provider_name', null);
            $activity->setValue('rqa_provider_short_name', null);
            return;
        }

        $provider = new Provider($this->db, (int)$providerId);
        if ((int)$provider->getValue('rqp_id') === 0
            || (int)$provider->getValue('rqp_org_id') !== (int)$gCurrentOrgId
            || !$provider->isVisible()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $activity->setValue('rqa_provider_id', (int)$provider->getValue('rqp_id'));
        $activity->setValue('rqa_provider_name', $provider->getValue('rqp_name', 'database'));
        $activity->setValue('rqa_provider_short_name', $provider->getValue('rqp_short_name', 'database'));
    }

    private function assertUserInCurrentOrganization(int $userId): void
    {
        global $gCurrentOrgId;

        $user = new User($this->db, null, $userId);
        $user->setOrganization((int)$gCurrentOrgId);
        if ((int)$user->getValue('usr_id') === 0 || !$user->isMemberOfOrganization()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * Validate the submitted dates and times, then write them.
     *
     * The order matters. Entity::setValue() on a NOT NULL date column turns an empty string into
     * 1970-01-01, so an activity saved with no begin date would come back dated to the epoch and
     * land in the wrong credited period, with nothing left for a later check to notice. The values
     * are therefore validated as they arrived and only then handed to the entity - which also
     * catches a time column, where the entity stores whatever string it is given.
     *
     * @param array<string,mixed> $values
     * @throws Exception
     */
    private function applyDatesAndTimes(Activity $activity, array $values, bool $isNew): void
    {
        foreach (self::DATE_FIELDS as $field => $label) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $raw = is_string($values[$field]) ? trim($values[$field]) : $values[$field];

            if ($raw === null || $raw === '') {
                if ($field === 'rqa_begin_date') {
                    throw new Exception('SYS_FIELD_EMPTY', array($label));
                }
                $activity->setValue($field, null);
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$raw);
            if ($date === false || $date->format('Y-m-d') !== (string)$raw) {
                throw new Exception('SYS_DATE_INVALID', array($label, 'YYYY-MM-DD'));
            }
            $activity->setValue($field, $date->format('Y-m-d'));
        }

        foreach (self::TIME_FIELDS as $field => $label) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $raw = is_string($values[$field]) ? trim($values[$field]) : $values[$field];

            if ($raw === null || $raw === '') {
                $activity->setValue($field, null);
                continue;
            }
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$raw) !== 1) {
                throw new Exception('SYS_TIME_INVALID', array($label, 'HH:MM'));
            }
            $activity->setValue($field, strlen((string)$raw) === 5 ? $raw . ':00' : $raw);
        }

        // A record that was never given a begin date, for instance an update that only touched the
        // title, still has to end up with one.
        $begin = (string)$activity->getValue('rqa_begin_date', 'database');
        if ($begin === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('PLG_REQUIREMENTS_BEGIN_DATE'));
        }

        $end = (string)$activity->getValue('rqa_end_date', 'database');
        if ($end !== '' && $end < $begin) {
            throw new Exception('PLG_REQUIREMENTS_INVALID_ACTIVITY_PERIOD');
        }

        // Within one day the times decide the order; across days the dates already did.
        $beginTime = (string)$activity->getValue('rqa_begin_time', 'database');
        $endTime = (string)$activity->getValue('rqa_end_time', 'database');
        if ($end === $begin && $beginTime !== '' && $endTime !== '' && $endTime < $beginTime) {
            throw new Exception('PLG_REQUIREMENTS_INVALID_ACTIVITY_PERIOD');
        }
    }
}
