<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

use Admidio\Components\Entity\Component;
use AdmidioPlugin\Requirements\RequirementsPlugin;

final class Activity extends Entity
{
    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::activities(), 'rqa', $id);
    }

    public function getHookId(): ?string
    {
        return 'requirements_activity';
    }

    public function isVisible(): bool
    {
        global $gCurrentOrgId, $gCurrentUser, $gValidLogin;

        return $gValidLogin
            && (int)$this->getValue('rqa_org_id') === (int)$gCurrentOrgId
            && (
                Component::isAdministrable(RequirementsPlugin::ID)
                || (int)$this->getValue('rqa_user_id') === (int)$gCurrentUser->getValue('usr_id')
            );
    }

    public function isEditable(): bool
    {
        global $gCurrentUser;

        if (!$this->isVisible()) {
            return false;
        }
        if (Component::isAdministrable(RequirementsPlugin::ID)) {
            return true;
        }
        if ((int)$this->getValue('rqa_user_id') !== (int)$gCurrentUser->getValue('usr_id')) {
            return false;
        }

        return RequirementsPlugin::setting(RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY)
            !== RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
            || (string)$this->getValue('rqa_status') === 'draft';
    }

    public function isDeletable(): bool
    {
        return $this->isEditable();
    }
}
