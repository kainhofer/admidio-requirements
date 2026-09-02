<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class ActivityCredit extends Entity
{
    use OrganizationScope;

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
