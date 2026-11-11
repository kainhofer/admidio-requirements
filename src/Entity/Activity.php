<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Components\Entity\Component;
use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\RequirementsPlugin;

final class Activity extends Entity
{
    use OrganizationScope;
    use Loggable;

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::activities(), 'rqa', $id);
    }

    protected static function organizationScopeCondition(): string
    {
        return 'rqa_org_id = ?';
    }

    public function getHookId(): ?string
    {
        return 'requirements_activity';
    }

    protected function isPrivateRecord(): bool
    {
        return false;
    }

    /**
     * The member is shown as the related object of an entry, not as a field, and the provider's name
     * and short name that the activity keeps a copy of follow the provider and are not a change of
     * their own. The source key is the import's bookkeeping.
     */
    public function getIgnoredLogColumns(): array
    {
        return array_merge(parent::getIgnoredLogColumns(), array(
            'rqa_org_id', 'rqa_user_id', 'rqa_provider_name', 'rqa_provider_short_name', 'rqa_source_key'
        ));
    }

    protected function adjustLogEntry(LogChanges $logEntry): void
    {
        global $gProfileFields;

        $userId = (int) $this->getValue('rqa_user_id');
        if ($userId > 0) {
            $member = new User($this->db, $gProfileFields, $userId);
            $logEntry->setLogRelated((string) $member->getValue('usr_uuid'), $member->readableName());
        }
    }

    /**
     * Its credits and certificates go with it, and the change history says so for each of them: the
     * database would remove them on its own and leave no trace of what the activity had carried.
     */
    public function delete(): bool
    {
        $activityId = (int) $this->getValue('rqa_id');
        if ($activityId > 0) {
            $this->deleteDependentRecords(new ActivityCredit($this->db), array('rac_id'), 'rac_activity_id = ?', array($activityId));
            $this->deleteDependentRecords(new ActivityFile($this->db), array('raf_id'), 'raf_activity_id = ?', array($activityId));
        }

        return parent::delete();
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
