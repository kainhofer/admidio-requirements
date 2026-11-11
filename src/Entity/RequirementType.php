<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class RequirementType extends Entity
{
    use OrganizationScope;
    use Loggable;

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

    protected function isPrivateRecord(): bool
    {
        return (int) $this->getValue('rqt_usr_id') > 0;
    }

    public function getIgnoredLogColumns(): array
    {
        return array_merge(parent::getIgnoredLogColumns(), array('rqt_org_id'));
    }

    /**
     * The parts and the rules of a requirement go with it, and the change history says so for each of
     * them: the database would remove the rules on its own and leave no trace of them.
     */
    public function delete(): bool
    {
        $typeId = (int) $this->getValue('rqt_id');
        if ($typeId > 0) {
            $this->deleteDependentRecords(
                new RequirementRoleRule($this->db),
                array('rrr_id'),
                'rrr_requirement_type_id = ?',
                array($typeId)
            );
        }

        return parent::delete();
    }
}
