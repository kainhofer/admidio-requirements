<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

/**
 * A review: one member, one requirement and one credited period, or one single activity of the member.
 *
 * The member submits it, or a reviewer requests it and the member shares the activities it covers.
 * The decisions themselves are stored on the credits; the review is the unit of work around them -
 * who handles it, where it stands, and the message and closing comment that go with it.
 */
final class Review extends Entity
{
    use OrganizationScope;

    public const KIND_SUBMISSION = 'submission';
    public const KIND_REQUEST = 'request';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLARIFICATION = 'clarification';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = array(
        self::STATUS_REQUESTED,
        self::STATUS_OPEN,
        self::STATUS_CLARIFICATION,
        self::STATUS_FINISHED,
        self::STATUS_WITHDRAWN
    );

    /** The statuses in which the reviewer sees the activities of the review. */
    public const SHARED_STATUSES = array(self::STATUS_OPEN, self::STATUS_CLARIFICATION, self::STATUS_FINISHED);

    /** The statuses of a review that is not done yet. */
    public const ACTIVE_STATUSES = array(self::STATUS_REQUESTED, self::STATUS_OPEN, self::STATUS_CLARIFICATION);

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::reviews(), 'rrv', $id);
    }

    protected static function organizationScopeCondition(): string
    {
        return 'rrv_org_id = ?';
    }

    public function getHookId(): ?string
    {
        return 'requirements_review';
    }

    /**
     * The member is the related object of an entry, and the bookkeeping columns say nothing a reader
     * of the history needs.
     */
    public function getIgnoredLogColumns(): array
    {
        return array_merge(parent::getIgnoredLogColumns(), array(
            'rrv_org_id', 'rrv_user_id', 'rrv_requested_by', 'rrv_finished_by', 'rrv_shared_at', 'rrv_finished_at'
        ));
    }

    protected function adjustLogEntry(LogChanges $logEntry): void
    {
        global $gProfileFields;

        $userId = (int) $this->getValue('rrv_user_id');
        if ($userId > 0) {
            $member = new User($this->db, $gProfileFields, $userId);
            $logEntry->setLogRelated((string) $member->getValue('usr_uuid'), $member->readableName());
        }
        $logEntry->setValue('log_record_name', $this->readableName());
    }

    /**
     * What a review is called: its requirement (or its activity) and its period.
     */
    public function readableName(): string
    {
        $activityId = (int) $this->getValue('rrv_activity_id');
        if ($activityId > 0) {
            $activity = new Activity($this->db, $activityId);
            return (string) $activity->getValue('rqa_title');
        }

        $type = new RequirementType($this->db, (int) $this->getValue('rrv_requirement_type_id'));
        $name = (string) $type->getValue('rqt_name');
        $reference = (string) $this->getValue('rrv_period_reference', 'database');
        return $reference === '' ? $name : $name . ' (' . $reference . ')';
    }

    public function isActive(): bool
    {
        return in_array((string) $this->getValue('rrv_status'), self::ACTIVE_STATUSES, true);
    }
}
