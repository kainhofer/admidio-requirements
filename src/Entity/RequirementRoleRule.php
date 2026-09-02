<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class RequirementRoleRule extends Entity
{
    use OrganizationScope;

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::requirementRoleRules(), 'rrr', $id);
    }

    /**
     * A rule has no organization of its own; it belongs to the one of its requirement type.
     */
    protected static function organizationScopeCondition(): string
    {
        return 'rrr_requirement_type_id IN (SELECT rqt_id FROM '
            . RequirementsTables::requirementTypes() . ' WHERE rqt_org_id = ?)';
    }

    public function getHookId(): ?string
    {
        return 'requirements_requirement_role_rule';
    }
}
