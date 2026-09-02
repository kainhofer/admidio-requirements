<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

use Admidio\Components\Entity\Component;
use AdmidioPlugin\Requirements\RequirementsPlugin;

final class Provider extends Entity
{
    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::providers(), 'rqp', $id);
    }

    public function getHookId(): ?string
    {
        return 'requirements_provider';
    }

    public function isVisible(): bool
    {
        global $gCurrentOrgId, $gCurrentUserId, $gValidLogin;

        return $gValidLogin
            && (int)$this->getValue('rqp_org_id') === (int)$gCurrentOrgId
            && (
                (bool)$this->getValue('rqp_public')
                || Component::isAdministrable(RequirementsPlugin::ID)
                || (int)$this->getValue('rqp_usr_id_create') === (int)$gCurrentUserId
            );
    }

    public function isEditable(): bool
    {
        global $gCurrentOrgId, $gCurrentUserId, $gValidLogin;

        return $gValidLogin
            && (int)$this->getValue('rqp_org_id') === (int)$gCurrentOrgId
            && (
                Component::isAdministrable(RequirementsPlugin::ID)
                || (int)$this->getValue('rqp_usr_id_create') === (int)$gCurrentUserId
            );
    }

    public function isDeletable(): bool
    {
        return $this->isEditable();
    }

    public function getFieldTitle(string $field): string
    {
        return match ($field) {
            'rqp_name' => 'SYS_NAME',
            'rqp_short_name' => 'PLG_REQUIREMENTS_PROVIDER_SHORT_NAME',
            'rqp_address' => 'SYS_ADDRESS',
            'rqp_url' => 'SYS_WEBSITE',
            'rqp_description' => 'SYS_DESCRIPTION',
            'rqp_qualified' => 'PLG_REQUIREMENTS_PROVIDER_QUALIFIED',
            'rqp_public' => 'PLG_REQUIREMENTS_PROVIDER_PUBLIC',
            default => parent::getFieldTitle($field)
        };
    }
}
