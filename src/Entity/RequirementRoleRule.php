<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class RequirementRoleRule extends Entity
{
    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::requirementRoleRules(), 'rrr', $id);
    }

    public function getHookId(): ?string
    {
        return 'requirements_requirement_role_rule';
    }
}
