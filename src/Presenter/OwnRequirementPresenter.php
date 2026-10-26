<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

/**
 * The requirements a member tracks for themselves - an employer's training obligation, the hours a
 * licence asks for - next to the ones the organization sets. Every member manages their own here.
 * Other members never see them, and administrators only as the credits on the member's activities.
 */
class OwnRequirementPresenter extends RequirementsPresenter
{
    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(): void
    {
        global $gDb, $gL10n, $gCurrentUser, $gCurrentSession, $gSettingsManager;

        $this->setHtmlID('adm_requirements_own');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENTS'));

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_own_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENT'))),
            $this->pageUrl('own.php', array('mode' => 'edit')),
            'bi-plus-circle-fill'
        );

        $format = $gSettingsManager->getString('system_date');
        $date = static fn(?string $value): string => $value === null || $value === ''
            ? '…' : \DateTimeImmutable::createFromFormat('!Y-m-d', $value)->format($format);

        $types = array();
        foreach ((new RequirementTypeService($gDb))->findOwnTypes((int) $gCurrentUser->getValue('usr_id')) as $type) {
            $types[] = array(
                'rowId' => 'adm_requirements_own_' . $type['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $type['rqt_name']),
                'shortName' => SecurityUtils::encodeHTML((string) $type['rqt_short_name']),
                'description' => SecurityUtils::encodeHTML((string) $type['rqt_description']),
                'active' => (bool) $type['rqt_active'],
                'period' => self::periodDescription($type, $gL10n),
                'credits' => self::formatCredits($type['rrr_required_credits']),
                'validity' => $date($type['rrr_valid_from']) . ' – ' . $date($type['rrr_valid_until']),
                'editUrl' => $this->pageUrl('own.php', array('mode' => 'edit', 'uuid' => $type['rqt_uuid'])),
                'deleteUrl' => $this->pageUrl('own.php', array('mode' => 'delete', 'uuid' => $type['rqt_uuid'])),
                'deleteMessage' => $gL10n->get('SYS_WANT_DELETE_ENTRY', array($type['rqt_name']))
            );
        }

        $this->assignSmartyVariable('types', $types);
        $this->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
        $this->addHtmlByTemplate('requirements.own.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(string $typeUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $type = self::readOwnType($typeUUID);
        $rule = (new RequirementTypeService($gDb))->ownRule($type);

        $this->setHtmlID('adm_requirements_own_edit');
        $this->setHeadline($gL10n->get(
            $type->isNewRecord() ? 'SYS_CREATE_VAR' : 'SYS_EDIT_VAR',
            array($gL10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENT'))
        ));

        $form = new FormPresenter(
            'adm_requirements_own_edit_form',
            'requirements.own.edit.tpl',
            $this->pageUrl('own.php', array('mode' => 'save', 'uuid' => $typeUUID)),
            $this
        );
        $form->addInput('rqt_name', $gL10n->get('SYS_NAME'), (string) $type->getValue('rqt_name'),
            array('maxLength' => 255, 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addInput('rqt_short_name', $gL10n->get('PLG_REQUIREMENTS_SHORT_NAME'), (string) $type->getValue('rqt_short_name'),
            array('maxLength' => 255, 'helpTextId' => 'PLG_REQUIREMENTS_REQUIREMENT_SHORT_NAME_HELP'));
        $form->addMultilineTextInput('rqt_description', $gL10n->get('SYS_DESCRIPTION'),
            (string) $type->getValue('rqt_description'), 3);
        $form->addCheckbox('rqt_active', $gL10n->get('PLG_REQUIREMENTS_ACTIVE'),
            $type->isNewRecord() ? true : (bool) $type->getValue('rqt_active'),
            array('helpTextId' => 'PLG_REQUIREMENTS_REQUIREMENT_ACTIVE_HELP'));

        $form->addSelectBox('rqt_period_unit', $gL10n->get('PLG_REQUIREMENTS_PERIOD_UNIT'), array(
            'year' => $gL10n->get('PLG_REQUIREMENTS_PERIOD_YEAR'),
            'quarter' => $gL10n->get('PLG_REQUIREMENTS_PERIOD_QUARTER'),
            'month' => $gL10n->get('PLG_REQUIREMENTS_PERIOD_MONTH'),
            'week' => $gL10n->get('PLG_REQUIREMENTS_PERIOD_WEEK')
        ), array(
            'property' => FormPresenter::FIELD_REQUIRED,
            'defaultValue' => (string) $type->getValue('rqt_period_unit') ?: 'year',
            'showContextDependentFirstEntry' => false
        ));
        $form->addInput('rqt_period_count', $gL10n->get('PLG_REQUIREMENTS_PERIOD_COUNT'),
            (string) ($type->getValue('rqt_period_count') ?: 1),
            array('type' => 'number', 'minNumber' => 1, 'maxNumber' => 10, 'step' => 1,
                'helpTextId' => 'PLG_REQUIREMENTS_PERIOD_COUNT_HELP'));
        $form->addSelectBox('rqt_period_anchor', $gL10n->get('PLG_REQUIREMENTS_PERIOD_ANCHOR'), array(
            'calendar' => $gL10n->get('PLG_REQUIREMENTS_ANCHOR_CALENDAR'),
            'last_fulfilment' => $gL10n->get('PLG_REQUIREMENTS_ANCHOR_LAST_FULFILMENT')
        ), array(
            'defaultValue' => (string) $type->getValue('rqt_period_anchor') ?: 'calendar',
            'showContextDependentFirstEntry' => false
        ));

        $form->addInput('rrr_required_credits', $gL10n->get('PLG_REQUIREMENTS_REQUIRED_CREDITS'),
            (string) ($rule->isNewRecord() ? '' : $rule->getValue('rrr_required_credits')),
            array('type' => 'number', 'minNumber' => 0, 'step' => 0.01, 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addSelectBox('rrr_partial_period_mode', $gL10n->get('PLG_REQUIREMENTS_PARTIAL_PERIOD_MODE'), array(
            'pro_rata' => $gL10n->get('PLG_REQUIREMENTS_PARTIAL_PRO_RATA'),
            'full' => $gL10n->get('PLG_REQUIREMENTS_PARTIAL_FULL')
        ), array(
            'defaultValue' => (string) $rule->getValue('rrr_partial_period_mode') ?: 'pro_rata',
            'showContextDependentFirstEntry' => false
        ));
        $form->addInput('rrr_valid_from', $gL10n->get('PLG_REQUIREMENTS_OWN_SINCE'),
            (string) ($rule->getValue('rrr_valid_from') ?: DATE_NOW),
            array('type' => 'date', 'property' => FormPresenter::FIELD_REQUIRED,
                'helpTextId' => 'PLG_REQUIREMENTS_OWN_SINCE_HELP'));
        $form->addInput('rrr_valid_until', $gL10n->get('PLG_REQUIREMENTS_VALID_UNTIL'),
            (string) $rule->getValue('rrr_valid_until'), array('type' => 'date'));
        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('userCreatedName', $type->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $type->getValue('rqt_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $type->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $type->getValue('rqt_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * One of the current member's own requirements, or a new one for an empty UUID. Anything else -
     * an unknown UUID, the organization's requirement, somebody else's - is refused.
     *
     * @throws Exception
     */
    public static function readOwnType(string $typeUUID): RequirementType
    {
        global $gDb, $gCurrentUser;

        $type = new RequirementType($gDb);
        if ($typeUUID === '') {
            return $type;
        }

        $type->readDataByUuid($typeUUID);
        if ((int) $type->getValue('rqt_id') === 0
            || RequirementTypeService::ownerOf($type) !== (int) $gCurrentUser->getValue('usr_id')) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        return $type;
    }
}
