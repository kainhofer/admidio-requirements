<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class RequirementType extends Entity
{
    use OrganizationScope;

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::requirementTypes(), 'rqt', $id);
    }

    protected static function organizationScopeCondition(): string
    {
        return 'rqt_org_id = ?';
    }

    public function getHookId(): ?string
    {
        return 'requirements_requirement_type';
    }
}
