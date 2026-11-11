<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Service\FulfilmentService;

final class ActivityCredit extends Entity
{
    use OrganizationScope;
    use Loggable;

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::activityCredits(), 'rac', $id);
    }

    /**
     * A credit has no organization of its own; it belongs to the one of its activity.
     */
    protected static function organizationScopeCondition(): string
    {
        return 'rac_activity_id IN (SELECT rqa_id FROM '
            . RequirementsTables::activities() . ' WHERE rqa_org_id = ?)';
    }

    public function getHookId(): ?string
    {
        return 'requirements_activity_credit';
    }

    /**
     * A credit towards a requirement a member tracks for themselves stays out of the change history.
     */
    protected function isPrivateRecord(): bool
    {
        return $this->isOwnRequirementType((int) $this->getValue('rac_requirement_type_id'));
    }

    public function getIgnoredLogColumns(): array
    {
        return array_merge(parent::getIgnoredLogColumns(), array('rac_activity_id', 'rac_requirement_type_id'));
    }

    /**
     * What a credit is called in the change history: the activity it was earned in. The requirement
     * it counts towards, with its period, is the related object, and the entry links to the activity,
     * as a credit has no page of its own.
     */
    protected function adjustLogEntry(LogChanges $logEntry): void
    {
        $activity = new Activity($this->db, (int) $this->getValue('rac_activity_id'));
        if ((int) $activity->getValue('rqa_id') > 0) {
            $logEntry->setValue('log_record_name', $activity->readableName());
            $logEntry->setValue('log_record_uuid', (string) $activity->getValue('rqa_uuid'));
            $logEntry->setLogLinkID((int) $activity->getValue('rqa_id'));
        }

        $type = new RequirementType($this->db, (int) $this->getValue('rac_requirement_type_id'));
        if ((int) $type->getValue('rqt_id') > 0) {
            $name = $type->readableName();
            $reference = (string) $this->getValue('rac_period_reference', 'database');
            if ($reference !== '') {
                $kind = FulfilmentService::periodControlKind($type);
                $parts = FulfilmentService::periodControlValue($kind, $reference);
                $name .= ' (' . ($parts['quarter'] !== '' ? $parts['value'] . ' Q' . $parts['quarter'] : $parts['value']) . ')';
            }
            $logEntry->setLogRelated((string) $type->getValue('rqt_uuid'), $name);
        }
    }

    /**
     * §6.6: the accepted value wins whenever a reviewer has set one, and an accepted zero is an
     * explicit rejection rather than "no answer yet".
     *
     * `''` counts as "not set" alongside `null`, because Entity::getValue() answers an unset
     * decimal column with an empty string until the record has been read back from the database.
     */
    public function getEffectiveCredits(): float
    {
        $accepted = $this->getValue('rac_credits_accepted', 'database');

        return (float)($accepted === null || $accepted === '' ?
            $this->getValue('rac_credits_claimed', 'database') : $accepted);
    }
}
