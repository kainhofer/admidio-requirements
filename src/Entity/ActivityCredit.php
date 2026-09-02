<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class ActivityCredit extends Entity
{
    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::activityCredits(), 'rac', $id);
    }

    public function getHookId(): ?string
    {
        return 'requirements_activity_credit';
    }

    public function getEffectiveCredits(): float
    {
        $accepted = $this->getValue('rac_credits_accepted', 'database');
        return (float)($accepted === null || $accepted === '' ?
            $this->getValue('rac_credits_claimed', 'database') : $accepted);
    }
}
