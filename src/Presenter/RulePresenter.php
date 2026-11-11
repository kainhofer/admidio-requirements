<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

/**
 * The requirements page: the organization's requirements and the member's own, on one page.
 *
 * Administrators define the organization's requirements and their role rules. A member sees those
 * that apply to them through their roles, read-only. Everybody manages their own: requirements of
 * their own - an employer's training obligation, the hours a licence asks for - and their own
 * targets for requirements of the organization that apply to them through their work rather than a
 * role. Other members never see either, administrators only as the credits on the member's
 * activities.
 */
class RulePresenter extends RequirementsPresenter
{
    /**
     * @throws Exception
     */
    private function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(string $query = ''): void
    {
        global $gDb, $gL10n, $gCurrentUser, $gCurrentSession, $gSettingsManager;

        $isAdmin = $this->isAdmin();
        $userId = (int) $gCurrentUser->getValue('usr_id');
        $service = new RequirementTypeService($gDb);

        $this->setHtmlID('adm_requirements_rules');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_NAME'));

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_own_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENT'))),
            $this->pageUrl('rules.php', array('mode' => 'own-edit')),
            'bi-person-plus'
        );
        $targetable = array_filter(
            $service->findTypes(),
            static fn(array $type): bool => (bool) $type['rqt_active']
        );
        if ($targetable !== array()) {
            $this->addPageFunctionsMenuItem(
                'menu_item_requirements_target_add',
                $gL10n->get('PLG_REQUIREMENTS_OWN_TARGET_ADD'),
                $this->pageUrl('rules.php', array('mode' => 'target-edit')),
                'bi-bullseye'
            );
        }
        if ($isAdmin) {
            $this->addPageFunctionsMenuItem(
                'menu_item_requirements_type_add',
                $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'))),
                $this->pageUrl('rules.php', array('mode' => 'type-edit')),
                'bi-plus-circle-fill'
            );
        }
        if ($isAdmin) {
            $this->addHistoryMenuItem(
                'rules',
                array('req_requirement_types', 'req_requirement_role_rules')
            );
            $this->addSettingsMenuItem();
        }

        $this->assignSmartyVariable('searchUrl', $this->plugin->getUrl('rules.php'));
        $this->assignSmartyVariable('query', $query);
        $this->assignSmartyVariable('searchPlaceholder', $gL10n->get('PLG_REQUIREMENTS_SEARCH_REQUIREMENTS'));

        // The organization's requirements: all of them for an administrator, and for a member those
        // their current roles carry, with the rules of those roles only.
        $rulesByType = array();
        foreach ($isAdmin ? $service->findRules() : $service->findRulesForMember($userId) as $rule) {
            $rulesByType[(int) $rule['rrr_requirement_type_id']][] = $rule;
        }

        $builtTypes = array();
        foreach ($service->findTypes($query) as $type) {
            $typeId = (int) $type['rqt_id'];
            if (!$isAdmin && !isset($rulesByType[$typeId])) {
                continue;
            }
            $typeRules = array();
            foreach ($rulesByType[$typeId] ?? array() as $rule) {
                $ruleRowId = 'adm_requirements_rule_' . $rule['rrr_uuid'];
                $validity = ($rule['rrr_valid_from'] ?: '…') . ' – ' . ($rule['rrr_valid_until'] ?: '…');
                // The partial-period handling, the pro-rata basis and the membership condition are
                // details of a rule: one line of text instead of a column each.
                $details = array();
                if ($rule['rrr_partial_period_mode'] === 'pro_rata') {
                    $basisKey = match ((string) $rule['rrr_proration_basis']) {
                        'day' => 'PLG_REQUIREMENTS_PRORATION_DAY',
                        'week' => 'PLG_REQUIREMENTS_PRORATION_WEEK',
                        'month' => 'PLG_REQUIREMENTS_PRORATION_MONTH',
                        default => ''
                    };
                    $details[] = $gL10n->get('PLG_REQUIREMENTS_PARTIAL_PRO_RATA')
                        . ($basisKey === '' ? '' : ' (' . $gL10n->get($basisKey) . ')');
                } else {
                    $details[] = $gL10n->get('PLG_REQUIREMENTS_PARTIAL_FULL');
                }
                if ((bool) $rule['rrr_membership_at_period_start']) {
                    $details[] = $gL10n->get('PLG_REQUIREMENTS_MEMBERSHIP_AT_PERIOD_START');
                }
                $typeRules[] = array(
                    'roleName' => SecurityUtils::encodeHTML((string) $rule['role_name']),
                    'credits' => (float) $rule['rrr_required_credits'],
                    'details' => SecurityUtils::encodeHTML(implode('; ', $details)),
                    'validity' => $validity,
                    'validFrom' => (string) $rule['rrr_valid_from'],
                    'validUntil' => (string) $rule['rrr_valid_until'],
                    'inlineUrl' => $this->pageUrl('rules.php', array('mode' => 'rule-inline', 'uuid' => $rule['rrr_uuid'])),
                    'editUrl' => $this->pageUrl('rules.php', array('mode' => 'rule-edit', 'uuid' => $rule['rrr_uuid'])),
                    'rowId' => $ruleRowId,
                    'deleteAction' => $this->deleteAction(
                        $ruleRowId,
                        $this->pageUrl('rules.php', array('mode' => 'rule-delete', 'uuid' => $rule['rrr_uuid'])),
                        $gL10n->get('SYS_WANT_DELETE_ENTRY', array($rule['role_name'] . ' / ' . $type['rqt_name']))
                    )
                );
            }

            $builtTypes[(int) $type['rqt_id']] = array(
                'parentId' => (int) $type['rqt_parent_id'],
                'parts' => array(),
                'uuid' => $type['rqt_uuid'],
                'rowId' => 'adm_requirements_type_' . $type['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $type['rqt_name']),
                'shortName' => SecurityUtils::encodeHTML((string) $type['rqt_short_name']),
                'description' => SecurityUtils::encodeHTML((string) $type['rqt_description']),
                'period' => self::periodDescription($type, $gL10n),
                'multipleRoles' => $type['rqt_multiple_roles_mode'],
                'active' => (bool) $type['rqt_active'],
                'editUrl' => $this->pageUrl('rules.php', array('mode' => 'type-edit', 'uuid' => $type['rqt_uuid'])),
                'addRuleUrl' => $this->pageUrl('rules.php', array('mode' => 'rule-edit', 'type' => $type['rqt_uuid'])),
                'deleteUrl' => $this->pageUrl('rules.php', array('mode' => 'type-delete', 'uuid' => $type['rqt_uuid'])),
                'deleteMessage' => $gL10n->get('SYS_WANT_DELETE_ENTRY', array($type['rqt_name'])),
                'rules' => $typeRules
            );
        }

        // A sub-requirement is shown inside the card of its parent, and on its own where a search
        // finds only the part.
        foreach ($builtTypes as $built) {
            if ($built['parentId'] > 0 && isset($builtTypes[$built['parentId']])) {
                $builtTypes[$built['parentId']]['parts'][] = $built;
            }
        }
        $types = array_values(array_filter(
            $builtTypes,
            static fn(array $built): bool => $built['parentId'] === 0 || !isset($builtTypes[$built['parentId']])
        ));

        // The member's own: requirements of their own, then their targets for the organization's.
        $format = $gSettingsManager->getString('system_date');
        $date = static fn(?string $value): string => $value === null || $value === ''
            ? '…' : \DateTimeImmutable::createFromFormat('!Y-m-d', $value)->format($format);
        $matches = static fn(array $type): bool => $query === ''
            || mb_stripos((string) $type['rqt_name'], $query) !== false
            || mb_stripos((string) $type['rqt_short_name'], $query) !== false;

        $own = array();
        foreach (array(
            'own' => $service->findOwnTypes($userId),
            'target' => $service->findOwnTargets($userId)
        ) as $kind => $rows) {
            foreach (array_filter($rows, $matches) as $row) {
                $uuid = $kind === 'own' ? $row['rqt_uuid'] : $row['rrr_uuid'];
                $own[] = array(
                    'rowId' => 'adm_requirements_' . $kind . '_' . $uuid,
                    'target' => $kind === 'target',
                    'name' => SecurityUtils::encodeHTML((string) $row['rqt_name']),
                    'shortName' => SecurityUtils::encodeHTML((string) $row['rqt_short_name']),
                    'description' => SecurityUtils::encodeHTML((string) $row['rqt_description']),
                    'active' => (bool) $row['rqt_active'],
                    'period' => self::periodDescription($row, $gL10n),
                    'credits' => self::formatCredits($row['rrr_required_credits']),
                    'validity' => $date($row['rrr_valid_from']) . ' – ' . $date($row['rrr_valid_until']),
                    'editUrl' => $this->pageUrl('rules.php', array('mode' => $kind . '-edit', 'uuid' => $uuid)),
                    'deleteUrl' => $this->pageUrl('rules.php', array('mode' => $kind . '-delete', 'uuid' => $uuid)),
                    'deleteMessage' => $gL10n->get('SYS_WANT_DELETE_ENTRY', array($row['rqt_name']))
                );
            }
        }

        $this->assignSmartyVariable('isAdmin', $isAdmin);
        $this->assignSmartyVariable('types', $types);
        $this->assignSmartyVariable('ownRequirements', $own);
        $this->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
        $this->assignSmartyVariable('messageNoData', $gL10n->get('SYS_NO_ENTRIES'));
        $this->addHtmlByTemplate('requirements.rules.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createTypeForm(string $typeUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $this->requireAdmin();

        $type = new RequirementType($gDb);
        if ($typeUUID !== '') {
            $type->readDataByUuid($typeUUID);
            if ((int) $type->getValue('rqt_id') === 0 || RequirementTypeService::ownerOf($type) !== null) {
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
        // A requirement can be made part of another one ("thereof in person"). Only a requirement
        // of the organization without a parent of its own qualifies, and not one that has parts.
        $parentOptions = array();
        $hasParts = false;
        foreach ((new RequirementTypeService($gDb))->findTypes() as $candidate) {
            if ((int) $candidate['rqt_parent_id'] === (int) $type->getValue('rqt_id') && !$type->isNewRecord()) {
                $hasParts = true;
            }
            if ((int) $candidate['rqt_id'] !== (int) $type->getValue('rqt_id')
                && (int) $candidate['rqt_parent_id'] === 0 && (int) $candidate['rqt_usr_id'] === 0) {
                $parentOptions[$candidate['rqt_uuid']] = $candidate['rqt_name'];
            }
        }
        if (!$hasParts) {
            $parentUuid = '';
            foreach ((new RequirementTypeService($gDb))->findTypes() as $candidate) {
                if ((int) $candidate['rqt_id'] === (int) $type->getValue('rqt_parent_id')) {
                    $parentUuid = $candidate['rqt_uuid'];
                }
            }
            $form->addSelectBox('rqt_parent_uuid', $gL10n->get('PLG_REQUIREMENTS_PARENT_REQUIREMENT'), $parentOptions, array(
                'defaultValue' => $parentUuid,
                'showContextDependentFirstEntry' => true,
                'helpTextId' => 'PLG_REQUIREMENTS_PARENT_REQUIREMENT_HELP'
            ));
        }
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

        $this->requireAdmin();

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
        // A member's own requirement and a member's own target are theirs, not the administration's.
        if ((int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId
            || RequirementTypeService::ownerOf($type) !== null
            || (int) $rule->getValue('rrr_usr_id') > 0) {
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

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createOwnForm(string $typeUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession, $gCurrentUser;

        $type = self::readOwnType($typeUUID);
        $rule = (new RequirementTypeService($gDb))->ownRule($type, (int) $gCurrentUser->getValue('usr_id'));

        $this->setHtmlID('adm_requirements_own_edit');
        $this->setHeadline($gL10n->get(
            $type->isNewRecord() ? 'SYS_CREATE_VAR' : 'SYS_EDIT_VAR',
            array($gL10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENT'))
        ));

        $form = new FormPresenter(
            'adm_requirements_own_edit_form',
            'requirements.own.edit.tpl',
            $this->pageUrl('rules.php', array('mode' => 'own-save', 'uuid' => $typeUUID)),
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

    /**
     * The form for the member's own target for a requirement of the organization. A new target
     * chooses the requirement; an existing one keeps it.
     *
     * @throws Exception|\Smarty\Exception
     */
    public function createTargetForm(string $ruleUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $rule = self::readOwnTarget($ruleUUID);

        $this->setHtmlID('adm_requirements_target_edit');
        $this->setHeadline($gL10n->get(
            $rule->isNewRecord() ? 'SYS_CREATE_VAR' : 'SYS_EDIT_VAR',
            array($gL10n->get('PLG_REQUIREMENTS_OWN_TARGET'))
        ));

        $form = new FormPresenter(
            'adm_requirements_target_edit_form',
            'requirements.target.edit.tpl',
            $this->pageUrl('rules.php', array('mode' => 'target-save', 'uuid' => $ruleUUID)),
            $this
        );
        if ($rule->isNewRecord()) {
            $options = array();
            foreach ((new RequirementTypeService($gDb))->findTypes() as $type) {
                if ((bool) $type['rqt_active']) {
                    $options[$type['rqt_uuid']] = (string) $type['rqt_name'];
                }
            }
            $form->addSelectBox('rqt_uuid', $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'), $options, array(
                'property' => FormPresenter::FIELD_REQUIRED,
                'helpTextId' => 'PLG_REQUIREMENTS_OWN_TARGET_HELP'
            ));
        } else {
            $type = new RequirementType($gDb, (int) $rule->getValue('rrr_requirement_type_id'));
            $form->addCustomContent('rqt_uuid', $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'),
                SecurityUtils::encodeHTML((string) $type->getValue('rqt_name')));
        }
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

        $this->assignSmartyVariable('userCreatedName', $rule->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $rule->getValue('rrr_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $rule->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $rule->getValue('rrr_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * One of the current member's own targets, or a new one for an empty UUID. Anything else - an
     * unknown UUID, a role rule, somebody else's target - is refused.
     *
     * @throws Exception
     */
    public static function readOwnTarget(string $ruleUUID): RequirementRoleRule
    {
        global $gDb, $gCurrentUser;

        $rule = new RequirementRoleRule($gDb);
        if ($ruleUUID === '') {
            return $rule;
        }

        $rule->readDataByUuid($ruleUUID);
        $type = new RequirementType($gDb, (int) $rule->getValue('rrr_requirement_type_id'));
        if ((int) $rule->getValue('rrr_id') === 0
            || (int) $rule->getValue('rrr_usr_id') !== (int) $gCurrentUser->getValue('usr_id')
            || RequirementTypeService::ownerOf($type) !== null) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        return $rule;
    }
}
