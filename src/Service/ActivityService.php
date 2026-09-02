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
    public const STATUSES = array('draft', 'submitted', 'withdrawn');
    public const REVIEW_STATUSES = array('', 'open', 'accepted', 'partially_accepted', 'not_accepted', 'clarification');

    public function __construct(private readonly Database $db)
    {
    }

    public function save(Activity $activity, array $values, ?int $targetUserId = null): Activity
    {
        global $gCurrentOrgId, $gCurrentUser;

        $isNew = $activity->isNewRecord();
        if ($isNew && !Component::isVisible(RequirementsPlugin::ID)) {
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
            'rqa_begin_date', 'rqa_begin_time', 'rqa_end_date', 'rqa_end_time',
            'rqa_certificate_date', 'rqa_attendance', 'rqa_comments'
        ) as $field) {
            if (array_key_exists($field, $values)) {
                $activity->setValue($field, is_string($values[$field]) ? trim($values[$field]) : $values[$field]);
            }
        }

        if (trim((string)$activity->getValue('rqa_title', 'database')) === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_TITLE'));
        }
        $this->validateDates($activity);

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
    public function findActivities(?int $userId = null, string $query = '', ?int $requirementTypeId = null): array
    {
        global $gCurrentOrgId, $gCurrentUser, $gProfileFields;

        $creditJoin = '';
        $params = array(
            (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id')
        );
        $creditColumns = 'NULL AS rac_period_reference, NULL AS rac_credits_claimed, NULL AS rac_credits_accepted';
        if ($requirementTypeId !== null) {
            $creditColumns = 'rac.rac_period_reference, rac.rac_credits_claimed, rac.rac_credits_accepted';
            $creditJoin = ' LEFT JOIN ' . RequirementsTables::activityCredits() . ' AS rac
                    ON rac.rac_activity_id = rqa.rqa_id AND rac.rac_requirement_type_id = ?';
            $params[] = $requirementTypeId;
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
        $sql .= ' ORDER BY rqa.rqa_begin_date DESC, rqa.rqa_title';

        return $this->db->getArrayFromSql($sql, $params);
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
                $credit = new ActivityCredit($this->db, (int) $current['rac_id']);
                if (!$activity->isEditable()) {
                    throw new Exception('SYS_NO_RIGHTS');
                }
                $this->db->startTransaction();
                try {
                    $credit->delete();
                    $this->db->endTransaction();
                } catch (Throwable $exception) {
                    $this->db->rollback();
                    throw $exception;
                }
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

    private function validateDates(Activity $activity): void
    {
        foreach (array('rqa_begin_date', 'rqa_end_date', 'rqa_certificate_date') as $field) {
            $value = $activity->getValue($field, 'database');
            if ($value === null || $value === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
            if ($date === false || $date->format('Y-m-d') !== (string)$value) {
                throw new Exception('SYS_DATE_INVALID');
            }
        }

        if ((string)$activity->getValue('rqa_begin_date', 'database') === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('PLG_REQUIREMENTS_BEGIN_DATE'));
        }

        $end = (string)$activity->getValue('rqa_end_date', 'database');
        if ($end !== '' && $end < (string)$activity->getValue('rqa_begin_date', 'database')) {
            throw new Exception('PLG_REQUIREMENTS_INVALID_ACTIVITY_PERIOD');
        }
    }
}
