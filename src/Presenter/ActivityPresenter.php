<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ProviderService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\RequirementsPlugin;

/**
 * Activity list (with the fulfilment overview widget on top) and activity edit form.
 */
class ActivityPresenter extends RequirementsPresenter
{
    /**
     * Requirement types that should have a credit input on the edit form: every active type plus
     * every type this activity already carries a credit for.
     *
     * @return array<int,array<string,mixed>>
     */
    private function creditableTypes(array $existingCredits): array
    {
        global $gDb;

        $types = array();
        foreach ((new RequirementTypeService($gDb))->findTypes() as $type) {
            if ((bool) $type['rqt_active'] || array_key_exists((int) $type['rqt_id'], $existingCredits)) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(): void
    {
        global $gDb, $gL10n, $gCurrentUser;

        $this->setHtmlID('adm_requirements_activities');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'));

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_activity_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_ACTIVITY'))),
            $this->pageUrl('activities.php', array('mode' => 'edit')),
            'bi-plus-circle-fill'
        );
        if ($this->isAdmin()) {
            $this->addPageFunctionsMenuItem(
                'menu_item_requirements_providers',
                $gL10n->get('PLG_REQUIREMENTS_PROVIDERS'),
                $this->pageUrl('providers.php'),
                'bi-shop'
            );
            $this->addPageFunctionsMenuItem(
                'menu_item_requirements_rules',
                $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPES'),
                $this->pageUrl('rules.php'),
                'bi-list-check'
            );
        }

        // Fulfilment overview widget (current user, current + N previous years)
        $previousYears = (int) (RequirementsPlugin::setting(RequirementsPlugin::SETTING_OVERVIEW_PREVIOUS_YEARS) ?? 4);
        $overview = (new FulfilmentService($gDb))->coverageHistoryForUser(
            (int) $gCurrentUser->getValue('usr_id'),
            $previousYears
        );
        $this->assignSmartyVariable('overview', $overview);

        // Activity table
        $isAdmin = $this->isAdmin();
        $headers = array($gL10n->get('SYS_TITLE'));
        if ($isAdmin) {
            $headers[] = $gL10n->get('SYS_MEMBER');
        }
        $headers[] = $gL10n->get('PLG_REQUIREMENTS_BEGIN_DATE');
        $headers[] = $gL10n->get('PLG_REQUIREMENTS_PROVIDER');
        $headers[] = $gL10n->get('PLG_REQUIREMENTS_STATUS');
        $headers[] = '';

        $rows = array();
        foreach ((new ActivityService($gDb))->findActivities() as $activity) {
            $rowId = 'adm_requirements_activity_' . $activity['rqa_uuid'];
            $editUrl = $this->pageUrl('activities.php', array('mode' => 'edit', 'uuid' => $activity['rqa_uuid']));

            $entity = new Activity($gDb);
            $entity->setArray($activity);
            $editable = $entity->isEditable();

            $data = array('<a href="' . $editUrl . '">' . SecurityUtils::encodeHTML((string) $activity['rqa_title']) . '</a>');
            if ($isAdmin) {
                $data[] = SecurityUtils::encodeHTML(trim(($activity['last_name'] ?? '') . ', ' . ($activity['first_name'] ?? ''), ', '));
            }
            $data[] = (string) $activity['rqa_begin_date'];
            $data[] = SecurityUtils::encodeHTML((string) ($activity['rqa_provider_name'] ?? ''));
            $data[] = $gL10n->get(self::statusLabel((string) $activity['rqa_status']));

            $actions = array();
            $actions[] = array('url' => $editUrl, 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('SYS_EDIT'));
            if ($editable) {
                $actions[] = $this->deleteAction(
                    $rowId,
                    $this->pageUrl('activities.php', array('mode' => 'delete', 'uuid' => $activity['rqa_uuid'])),
                    $gL10n->get('SYS_WANT_DELETE_ENTRY', array($activity['rqa_title']))
                );
            }

            $rows[] = array('id' => $rowId, 'uuid' => $activity['rqa_uuid'], 'data' => $data, 'actions' => $actions);
        }

        $this->assignSmartyVariable('headers', $headers);
        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('SYS_NO_ENTRIES'));
        $this->addHtmlByTemplate('requirements.activities.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(string $activityUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $activity = new Activity($gDb);
        $existingCredits = array();
        if ($activityUUID !== '') {
            $activity->readDataByUuid($activityUUID);
            if ((int) $activity->getValue('rqa_id') === 0 || !$activity->isEditable()) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $existingCredits = (new ActivityService($gDb))->creditsForActivity((int) $activity->getValue('rqa_id'));
            $this->setHeadline($gL10n->get('SYS_EDIT_VAR', array($gL10n->get('PLG_REQUIREMENTS_ACTIVITY'))));
        } else {
            $this->setHeadline($gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_ACTIVITY'))));
        }
        $this->setHtmlID('adm_requirements_activity_edit');

        $isAdmin = $this->isAdmin();
        $form = new FormPresenter(
            'adm_requirements_activity_edit_form',
            'requirements.activity.edit.tpl',
            $this->pageUrl('activities.php', array('mode' => 'save', 'uuid' => $activityUUID)),
            $this
        );

        $form->addInput('rqa_title', $gL10n->get('SYS_TITLE'), (string) $activity->getValue('rqa_title'),
            array('maxLength' => 255, 'property' => FormPresenter::FIELD_REQUIRED));

        $providers = array('' => '');
        foreach ((new ProviderService($gDb))->findVisible() as $provider) {
            $providers[(int) $provider['rqp_id']] = $provider['rqp_name']
                . ($provider['rqp_qualified'] ? ' ★' : '');
        }
        $form->addSelectBox('rqa_provider_id', $gL10n->get('PLG_REQUIREMENTS_PROVIDER'), $providers,
            array('defaultValue' => (int) $activity->getValue('rqa_provider_id')));

        $form->addInput('rqa_begin_date', $gL10n->get('PLG_REQUIREMENTS_BEGIN_DATE'),
            (string) $activity->getValue('rqa_begin_date'),
            array('type' => 'date', 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addInput('rqa_begin_time', $gL10n->get('SYS_TIME'), (string) $activity->getValue('rqa_begin_time'),
            array('type' => 'time'));
        $form->addInput('rqa_end_date', $gL10n->get('SYS_END'), (string) $activity->getValue('rqa_end_date'),
            array('type' => 'date'));
        $form->addInput('rqa_end_time', $gL10n->get('SYS_TIME'), (string) $activity->getValue('rqa_end_time'),
            array('type' => 'time'));
        $form->addInput('rqa_completion_date', $gL10n->get('PLG_REQUIREMENTS_COMPLETION_DATE'),
            (string) $activity->getValue('rqa_completion_date'), array('type' => 'date'));
        $form->addInput('rqa_examination_date', $gL10n->get('PLG_REQUIREMENTS_EXAMINATION_DATE'),
            (string) $activity->getValue('rqa_examination_date'), array('type' => 'date'));
        $form->addInput('rqa_attendance', $gL10n->get('PLG_REQUIREMENTS_ATTENDANCE'),
            (string) $activity->getValue('rqa_attendance'), array('type' => 'number', 'minNumber' => 0, 'step' => 0.01));
        $form->addInput('rqa_url', $gL10n->get('SYS_WEBSITE'), (string) $activity->getValue('rqa_url'),
            array('maxLength' => 2000, 'type' => 'url'));
        $form->addMultilineTextInput('rqa_location', $gL10n->get('PLG_REQUIREMENTS_LOCATION'),
            (string) $activity->getValue('rqa_location'), 2);
        $form->addMultilineTextInput('rqa_description', $gL10n->get('SYS_DESCRIPTION'),
            (string) $activity->getValue('rqa_description'), 4);
        $form->addMultilineTextInput('rqa_comments', $gL10n->get('SYS_COMMENT'),
            (string) $activity->getValue('rqa_comments'), 3);

        // Credit inputs, one block per creditable requirement type.
        $creditFields = array();
        foreach ($this->creditableTypes($existingCredits) as $type) {
            $typeId = (int) $type['rqt_id'];
            $current = $existingCredits[$typeId] ?? null;
            $claimedField = 'credit_' . $typeId;
            $periodField = 'period_' . $typeId;

            $form->addInput($claimedField,
                $gL10n->get('PLG_REQUIREMENTS_CREDITS_FOR', array($type['rqt_name'])),
                (string) ($current['rac_credits_claimed'] ?? ''),
                array('type' => 'number', 'minNumber' => 0, 'step' => 0.01));
            $form->addInput($periodField,
                $gL10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD'),
                (string) ($current['rac_period_reference'] ?? ''),
                array('type' => 'date', 'helpTextId' => 'PLG_REQUIREMENTS_CREDITED_PERIOD_HELP'));

            $fieldNames = array($claimedField, $periodField);
            if ($isAdmin) {
                $acceptedField = 'accepted_' . $typeId;
                $form->addInput($acceptedField,
                    $gL10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED'),
                    $current === null || $current['rac_credits_accepted'] === null
                        ? '' : (string) $current['rac_credits_accepted'],
                    array('type' => 'number', 'minNumber' => 0, 'step' => 0.01,
                        'helpTextId' => 'PLG_REQUIREMENTS_CREDITS_ACCEPTED_HELP'));
                $fieldNames[] = $acceptedField;
            }
            $creditFields[] = array('name' => SecurityUtils::encodeHTML((string) $type['rqt_name']), 'fields' => $fieldNames);
        }

        if ($isAdmin) {
            $form->addSelectBox('rqa_status', $gL10n->get('PLG_REQUIREMENTS_STATUS'), array(
                'draft' => $gL10n->get('PLG_REQUIREMENTS_STATUS_DRAFT'),
                'submitted' => $gL10n->get('PLG_REQUIREMENTS_STATUS_SUBMITTED'),
                'withdrawn' => $gL10n->get('PLG_REQUIREMENTS_STATUS_WITHDRAWN')
            ), array(
                'defaultValue' => (string) $activity->getValue('rqa_status') ?: 'submitted',
                'showContextDependentFirstEntry' => false
            ));
            $form->addSelectBox('rqa_review_status', $gL10n->get('PLG_REQUIREMENTS_REVIEW_STATUS'), array(
                '' => '',
                'open' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_OPEN'),
                'accepted' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_ACCEPTED'),
                'partially_accepted' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_PARTIALLY_ACCEPTED'),
                'not_accepted' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_NOT_ACCEPTED'),
                'clarification' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_CLARIFICATION')
            ), array('defaultValue' => (string) $activity->getValue('rqa_review_status')));
            $form->addMultilineTextInput('rqa_review_comments', $gL10n->get('PLG_REQUIREMENTS_REVIEW_COMMENTS'),
                (string) $activity->getValue('rqa_review_comments'), 3);
        }

        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('creditFields', $creditFields);
        $this->assignSmartyVariable('showReview', $isAdmin);
        $this->assignSmartyVariable('userCreatedName', $activity->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $activity->getValue('rqa_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $activity->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $activity->getValue('rqa_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'PLG_REQUIREMENTS_STATUS_DRAFT',
            'withdrawn' => 'PLG_REQUIREMENTS_STATUS_WITHDRAWN',
            default => 'PLG_REQUIREMENTS_STATUS_SUBMITTED'
        };
    }
}
