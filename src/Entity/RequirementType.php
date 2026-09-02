<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

final class RequirementType extends Entity
{
    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::requirementTypes(), 'rqt', $id);
    }

    public function getHookId(): ?string
    {
        return 'requirements_requirement_type';
    }
}
