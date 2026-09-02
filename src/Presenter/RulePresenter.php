<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

/**
 * Requirement type and role rule administration of the requirements plugin. Administrators only.
 */
class RulePresenter extends RequirementsPresenter
{
    /**
     * @throws Exception
     */
    public function __construct(string $objectUUID = '')
    {
        parent::__construct($objectUUID);

        if (!$this->isAdmin()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(string $query = ''): void
    {
        global $gDb, $gL10n;

        $this->setHtmlID('adm_requirements_rules');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPES'));

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_type_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'))),
            $this->pageUrl('rules.php', array('mode' => 'type-edit')),
            'bi-plus-circle-fill'
        );

        $this->assignSmartyVariable('searchUrl', $this->plugin->getUrl('rules.php'));
        $this->assignSmartyVariable('query', $query);
        $this->assignSmartyVariable('searchPlaceholder', $gL10n->get('PLG_REQUIREMENTS_SEARCH_REQUIREMENTS'));

        $service = new RequirementTypeService($gDb);
        $rulesByType = array();
        foreach ($service->findRules() as $rule) {
            $rulesByType[(int) $rule['rrr_requirement_type_id']][] = $rule;
        }

        $types = array();
        foreach ($service->findTypes($query) as $type) {
            $typeId = (int) $type['rqt_id'];
            $typeRules = array();
            foreach ($rulesByType[$typeId] ?? array() as $rule) {
                $ruleRowId = 'adm_requirements_rule_' . $rule['rrr_uuid'];
                $validity = ($rule['rrr_valid_from'] ?: '…') . ' – ' . ($rule['rrr_valid_until'] ?: '…');
                $typeRules[] = array(
                    'roleName' => SecurityUtils::encodeHTML((string) $rule['role_name']),
                    'credits' => (float) $rule['rrr_required_credits'],
                    'mode' => $rule['rrr_partial_period_mode'],
                    'basis' => $rule['rrr_proration_basis'],
                    'periodStart' => (bool) $rule['rrr_membership_at_period_start'],
                    'validity' => $validity,
                    'editUrl' => $this->pageUrl('rules.php', array('mode' => 'rule-edit', 'uuid' => $rule['rrr_uuid'])),
                    'rowId' => $ruleRowId,
                    'deleteAction' => $this->deleteAction(
                        $ruleRowId,
                        $this->pageUrl('rules.php', array('mode' => 'rule-delete', 'uuid' => $rule['rrr_uuid'])),
                        $gL10n->get('SYS_WANT_DELETE_ENTRY', array($rule['role_name'] . ' / ' . $type['rqt_name']))
                    )
                );
            }

            $types[] = array(
                'uuid' => $type['rqt_uuid'],
                'rowId' => 'adm_requirements_type_' . $type['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $type['rqt_name']),
                'shortName' => SecurityUtils::encodeHTML((string) $type['rqt_short_name']),
                'period' => self::periodDescription($type, $gL10n),
                'multipleRoles' => $type['rqt_multiple_roles_mode'],
                'active' => (bool) $type['rqt_active'],
                'editUrl' => $this->pageUrl('rules.php', array('mode' => 'type-edit', 'uuid' => $type['rqt_uuid'])),
                'addRuleUrl' => $this->pageUrl('rules.php', array('mode' => 'rule-edit', 'type' => $type['rqt_uuid'])),
                'deleteUrl' => $this->pageUrl('rules.php', array('mode' => 'type-delete', 'uuid' => $type['rqt_uuid'])),
                'deleteMessage' => $gL10n->get('SYS_WANT_DELETE_ENTRY', array($type['rqt_name'])),
                'csrfToken' => $GLOBALS['gCurrentSession']->getCsrfToken(),
                'rules' => $typeRules
            );
        }

        $this->assignSmartyVariable('types', $types);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('SYS_NO_ENTRIES'));
        $this->addHtmlByTemplate('requirements.rules.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createTypeForm(string $typeUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $type = new RequirementType($gDb);
        if ($typeUUID !== '') {
            $type->readDataByUuid($typeUUID);
            if ((int) $type->getValue('rqt_id') === 0) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $this->setHeadline($gL10n->get('SYS_EDIT_VAR', array($gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'))));
        } else {
            $this->setHeadline($gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'))));
        }
        $this->setHtmlID('adm_requirements_type_edit');

        $form = new FormPresenter(
            'adm_requirements_type_edit_form',
            'requirements.requirement-type.edit.tpl',
            $this->pageUrl('rules.php', array('mode' => 'type-save', 'uuid' => $typeUUID)),
            $this
        );
        $form->addInput('rqt_name', $gL10n->get('SYS_NAME'), (string) $type->getValue('rqt_name'),
            array('maxLength' => 255, 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addInput('rqt_short_name', $gL10n->get('PLG_REQUIREMENTS_SHORT_NAME'), (string) $type->getValue('rqt_short_name'),
            array('maxLength' => 255, 'helpTextId' => 'PLG_REQUIREMENTS_REQUIREMENT_SHORT_NAME_HELP'));
        $form->addMultilineTextInput('rqt_description', $gL10n->get('SYS_DESCRIPTION'),
            (string) $type->getValue('rqt_description'), 3);
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
        $form->addSelectBox('rqt_multiple_roles_mode', $gL10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_MODE'), array(
            'maximum' => $gL10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_MAXIMUM'),
            'minimum' => $gL10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_MINIMUM'),
            'sum' => $gL10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_SUM')
        ), array(
            'defaultValue' => (string) $type->getValue('rqt_multiple_roles_mode') ?: 'maximum',
            'showContextDependentFirstEntry' => false,
            'helpTextId' => 'PLG_REQUIREMENTS_MULTIPLE_ROLES_MODE_HELP'
        ));
        $form->addInput('rqt_sequence', $gL10n->get('SYS_ORDER'), (string) ($type->getValue('rqt_sequence') ?: 0),
            array('type' => 'number', 'minNumber' => 0, 'step' => 1));
        $form->addCheckbox('rqt_active', $gL10n->get('PLG_REQUIREMENTS_ACTIVE'),
            $type->isNewRecord() ? true : (bool) $type->getValue('rqt_active'),
            array('helpTextId' => 'PLG_REQUIREMENTS_REQUIREMENT_ACTIVE_HELP'));
        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('userCreatedName', $type->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $type->getValue('rqt_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $type->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $type->getValue('rqt_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createRuleForm(string $ruleUUID, string $typeUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession, $gCurrentOrgId;

        $rule = new RequirementRoleRule($gDb);
        $type = new RequirementType($gDb);

        if ($ruleUUID !== '') {
            $rule->readDataByUuid($ruleUUID);
            if ((int) $rule->getValue('rrr_id') === 0) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $type->readDataById((int) $rule->getValue('rrr_requirement_type_id'));
            $this->setHeadline($gL10n->get('SYS_EDIT_VAR', array($gL10n->get('PLG_REQUIREMENTS_ROLE_RULE'))));
        } else {
            $type->readDataByUuid($typeUUID);
            if ((int) $type->getValue('rqt_id') === 0) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $this->setHeadline($gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_ROLE_RULE'))));
        }
        if ((int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        $this->setHtmlID('adm_requirements_rule_edit');

        $form = new FormPresenter(
            'adm_requirements_rule_edit_form',
            'requirements.rule.edit.tpl',
            $this->pageUrl('rules.php', array('mode' => 'rule-save', 'uuid' => $ruleUUID, 'type' => $type->getValue('rqt_uuid'))),
            $this
        );
        $form->addCustomContent('rrr_requirement', $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'),
            SecurityUtils::encodeHTML((string) $type->getValue('rqt_name')));
        $form->addSelectBoxFromSql(
            'rrr_role_id',
            $gL10n->get('SYS_ROLE'),
            $gDb,
            array(
                'query' => 'SELECT rol_id, rol_name
                              FROM ' . TBL_ROLES . '
                        INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
                             WHERE cat_org_id = ? AND rol_valid = true
                          ORDER BY rol_name',
                'params' => array((int) $gCurrentOrgId)
            ),
            array('property' => FormPresenter::FIELD_REQUIRED, 'defaultValue' => (int) $rule->getValue('rrr_role_id'))
        );
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
        $form->addSelectBox('rrr_proration_basis', $gL10n->get('PLG_REQUIREMENTS_PRORATION_BASIS'), array(
            'month' => $gL10n->get('PLG_REQUIREMENTS_PRORATION_MONTH'),
            'day' => $gL10n->get('PLG_REQUIREMENTS_PRORATION_DAY'),
            'week' => $gL10n->get('PLG_REQUIREMENTS_PRORATION_WEEK')
        ), array(
            'defaultValue' => (string) $rule->getValue('rrr_proration_basis')
                ?: (string) \AdmidioPlugin\Requirements\RequirementsPlugin::setting(
                    \AdmidioPlugin\Requirements\RequirementsPlugin::SETTING_PRORATION_BASIS_DEFAULT
                ) ?: 'month',
            'showContextDependentFirstEntry' => false
        ));
        $form->addCheckbox('rrr_membership_at_period_start', $gL10n->get('PLG_REQUIREMENTS_MEMBERSHIP_AT_PERIOD_START'),
            (bool) $rule->getValue('rrr_membership_at_period_start'),
            array('helpTextId' => 'PLG_REQUIREMENTS_MEMBERSHIP_AT_PERIOD_START_HELP'));
        $form->addInput('rrr_valid_from', $gL10n->get('PLG_REQUIREMENTS_VALID_FROM'),
            (string) $rule->getValue('rrr_valid_from'), array('type' => 'date'));
        $form->addInput('rrr_valid_until', $gL10n->get('PLG_REQUIREMENTS_VALID_UNTIL'),
            (string) $rule->getValue('rrr_valid_until'), array('type' => 'date'));
        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('userCreatedName', $rule->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $rule->getValue('rrr_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $rule->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $rule->getValue('rrr_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    private static function periodUnitLabel(string $unit): string
    {
        return match ($unit) {
            'quarter' => 'PLG_REQUIREMENTS_PERIOD_QUARTER',
            'month' => 'PLG_REQUIREMENTS_PERIOD_MONTH',
            'week' => 'PLG_REQUIREMENTS_PERIOD_WEEK',
            default => 'PLG_REQUIREMENTS_PERIOD_YEAR'
        };
    }

    /**
     * Human-readable description of a requirement type's period, e.g. "Calendar year" or
     * "Every 2 years after the last fulfilment".
     *
     * @param array<string,mixed> $type
     */
    private static function periodDescription(array $type, Language $gL10n): string
    {
        $unit = (string) $type['rqt_period_unit'];
        $count = max(1, (int) $type['rqt_period_count']);

        if ((string) $type['rqt_period_anchor'] === 'calendar') {
            return $gL10n->get(match ($unit) {
                'quarter' => 'PLG_REQUIREMENTS_PERIOD_CALENDAR_QUARTER',
                'month' => 'PLG_REQUIREMENTS_PERIOD_CALENDAR_MONTH',
                'week' => 'PLG_REQUIREMENTS_PERIOD_CALENDAR_WEEK',
                default => 'PLG_REQUIREMENTS_PERIOD_CALENDAR_YEAR'
            });
        }

        return $gL10n->get('PLG_REQUIREMENTS_PERIOD_RECURRING', array(
            $count . ' ' . $gL10n->get(self::periodUnitLabel($unit))
        ));
    }
}
