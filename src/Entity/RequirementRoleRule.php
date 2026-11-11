<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Language;
use Admidio\Roles\Entity\Role;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class RequirementRoleRule extends Entity
{
    use OrganizationScope;
    use Loggable;

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

    /**
     * A member's own target and the rules of a requirement the member tracks for themselves stay out
     * of the change history.
     */
    protected function isPrivateRecord(): bool
    {
        return (int) $this->getValue('rrr_usr_id') > 0
            || $this->isOwnRequirementType((int) $this->getValue('rrr_requirement_type_id'));
    }

    /**
     * A rule is named by its requirement. The role it applies to is the related object, and the
     * columns that say which requirement and which role are not fields of the rule.
     */
    public function readableName(): string
    {
        $type = new RequirementType($this->db, (int) $this->getValue('rrr_requirement_type_id'));

        return $this->filterReadableName((string) $type->readableName());
    }

    public function getIgnoredLogColumns(): array
    {
        return array_merge(parent::getIgnoredLogColumns(), array('rrr_requirement_type_id', 'rrr_role_id'));
    }

    protected function adjustLogEntry(LogChanges $logEntry): void
    {
        $roleId = (int) $this->getValue('rrr_role_id');
        if ($roleId > 0) {
            $role = new Role($this->db, $roleId);
            $logEntry->setLogRelated(
                (string) $role->getValue('rol_uuid'),
                Language::translateIfTranslationStrId($role->readableName())
            );
        }
    }
}
