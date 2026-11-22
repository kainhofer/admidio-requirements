<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Components\Entity\Component;
use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ReviewService;

final class Activity extends Entity
{
    use OrganizationScope;

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

    /**
     * Its member and the administrators see an activity, and so does a reviewer who reviews one of
     * its credits - read-only, through the review page.
     */
    public function isVisible(): bool
    {
        global $gCurrentOrgId, $gCurrentUser, $gValidLogin;

        if (!$gValidLogin || (int)$this->getValue('rqa_org_id') !== (int)$gCurrentOrgId) {
            return false;
        }

        return $this->isOwnedOrAdministered() || (new ReviewService($this->db))->mayViewActivity($this);
    }

    /**
     * Whether the activity belongs to the current user, or the current user administers the plugin:
     * the two who see the whole activity and its edit form, rather than what a review covers of it.
     */
    public function isOwnedOrAdministered(): bool
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

        if (!$this->isOwnedOrAdministered()) {
            return false;
        }
        if (Component::isAdministrable(RequirementsPlugin::ID)) {
            return true;
        }
        if ((int)$this->getValue('rqa_user_id') !== (int)$gCurrentUser->getValue('usr_id')) {
            return false;
        }

        return $this->isEditableByPolicy() && !$this->isLockedByReview();
    }

    /**
     * Whether the edit policy alone lets the member change the activity: always, or while it is a
     * draft.
     */
    private function isEditableByPolicy(): bool
    {
        return RequirementsPlugin::setting(RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY)
            !== RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
            || (string)$this->getValue('rqa_status') === 'draft';
    }

    /**
     * Whether the review of a credit keeps the member from changing the activity, as the settings
     * for editing during a review and after a decision say.
     */
    public function isLockedByReview(): bool
    {
        return (new ReviewService($this->db))->isLockedForOwner((int) $this->getValue('rqa_id'));
    }

    /**
     * A member may delete their activity while they may edit it, unless a reviewer is looking at one
     * of its credits or has decided on it: the record under review does not simply vanish.
     */
    public function isDeletable(): bool
    {
        if (!$this->isEditable()) {
            return false;
        }
        return Component::isAdministrable(RequirementsPlugin::ID)
            || !(new ReviewService($this->db))->isUnderReview((int) $this->getValue('rqa_id'));
    }

    /**
     * Certificates may be added whenever the activity may be edited, and by its member also while a
     * review locks it: evidence is always welcome, and adding it changes no claim.
     */
    public function mayAddFiles(): bool
    {
        global $gCurrentUser;

        return $this->isEditable()
            || ((int)$this->getValue('rqa_id') > 0
                && $this->isOwnedOrAdministered()
                && (int)$this->getValue('rqa_user_id') === (int)$gCurrentUser->getValue('usr_id')
                && $this->isLockedByReview());
    }
}
