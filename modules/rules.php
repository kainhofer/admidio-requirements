<?php
/**
 ***********************************************************************************************
 * Requirements plugin - the requirements page: the organization's requirements and role rules,
 * which administrators edit and members see as far as they apply to them, and every member's own
 * requirements and own targets.
 *
 * Parameters:
 * mode : list (default)
 *        | type-edit | type-save | type-delete | rule-edit | rule-save | rule-inline | rule-delete (administrators)
 *        | own-edit | own-save | own-delete         a requirement of the member's own
 *        | target-edit | target-save | target-delete  the member's own target for one of the organization's
 * uuid : UUID of the requirement type, the role rule or the own target
 * type : UUID of the requirement type a new rule belongs to
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Presenter\RulePresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array(
            'list', 'type-edit', 'type-save', 'type-delete', 'rule-edit', 'rule-save', 'rule-inline', 'rule-delete',
            'own-edit', 'own-save', 'own-delete', 'target-edit', 'target-save', 'target-delete'
        )
    ));
    // The services refuse a write of the administration to anybody else as well; this keeps the
    // forms of the administration from even being shown.
    if (str_starts_with($getMode, 'type-') || str_starts_with($getMode, 'rule-')) {
        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');
    $getType = admFuncVariableIsValid($_GET, 'type', 'uuid');
    $getQuery = admFuncVariableIsValid($_GET, 'query', 'string');

    $service = new RequirementTypeService($gDb);

    switch ($getMode) {
        case 'own-edit':
            $page = new RulePresenter($getUuid);
            $page->createOwnForm($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'own-save':
            $type = RulePresenter::readOwnType($getUuid);
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);
            $values = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'rqt_') || str_starts_with($key, 'rrr_')) {
                    $values[$key] = $value;
                }
            }
            $values['rqt_active'] = (bool) ($formValues['rqt_active'] ?? false);
            $service->saveOwnType($type, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'own-delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $service->deleteOwnType(RulePresenter::readOwnType($getUuid));
            echo json_encode(array('status' => 'success'));
            break;

        case 'target-edit':
            $page = new RulePresenter($getUuid);
            $page->createTargetForm($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'target-save':
            $rule = RulePresenter::readOwnTarget($getUuid);
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);
            $type = new RequirementType($gDb);
            if ($rule->isNewRecord()) {
                $type->readDataByUuid((string) ($formValues['rqt_uuid'] ?? ''));
            } else {
                $type->readDataById((int) $rule->getValue('rrr_requirement_type_id'));
            }
            $values = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'rrr_')) {
                    $values[$key] = $value;
                }
            }
            $service->saveOwnTarget($rule, $type, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'target-delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $service->deleteOwnTarget(RulePresenter::readOwnTarget($getUuid));
            echo json_encode(array('status' => 'success'));
            break;

        case 'list':
            $page = new RulePresenter();
            $page->createList($getQuery);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline(), 'bi-list-check');
            $page->show();
            break;

        case 'type-edit':
            $page = new RulePresenter($getUuid);
            $page->createTypeForm($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'rule-edit':
            $page = new RulePresenter($getUuid);
            $page->createRuleForm($getUuid, $getType);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'type-save':
            $type = new RequirementType($gDb);
            if ($getUuid !== '') {
                // See index.php: a stale UUID must not turn an edit into a create.
                $type->readDataByUuid($getUuid);
                if ((int) $type->getValue('rqt_id') === 0) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
            }
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);
            $values = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'rqt_')) {
                    $values[$key] = $value;
                }
            }
            if (array_key_exists('rqt_active', $formValues)) {
                $values['rqt_active'] = (bool) $formValues['rqt_active'];
            }
            // The form names the parent by UUID. A type that has parts has no such field, and
            // leaves the parent as it is.
            if (array_key_exists('rqt_parent_uuid', $formValues)) {
                $parent = new RequirementType($gDb);
                if ((string) $formValues['rqt_parent_uuid'] !== '') {
                    $parent->readDataByUuid((string) $formValues['rqt_parent_uuid']);
                }
                $values['rqt_parent_id'] = (int) $parent->getValue('rqt_id');
                if ((string) $formValues['rqt_parent_uuid'] !== '' && $values['rqt_parent_id'] === 0) {
                    throw new Exception('PLG_REQUIREMENTS_PARENT_INVALID');
                }
            }
            $service->saveType($type, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'rule-save':
            $type = new RequirementType($gDb);
            $type->readDataByUuid($getType);

            $rule = new RequirementRoleRule($gDb);
            if ($getUuid !== '') {
                // See index.php: a stale UUID must not turn an edit into a create.
                $rule->readDataByUuid($getUuid);
                if ((int) $rule->getValue('rrr_id') === 0) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $type->readDataById((int) $rule->getValue('rrr_requirement_type_id'));
            }
            if ((int) $type->getValue('rqt_id') === 0) {
                throw new Exception('SYS_NO_RIGHTS');
            }

            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);
            $values = array(
                'rrr_role_id' => (int) ($formValues['rrr_role_id'] ?? 0),
                'rrr_required_credits' => (float) ($formValues['rrr_required_credits'] ?? 0),
                'rrr_partial_period_mode' => (string) ($formValues['rrr_partial_period_mode'] ?? 'pro_rata'),
                'rrr_proration_basis' => (string) ($formValues['rrr_proration_basis'] ?? 'month'),
                'rrr_membership_at_period_start' => (bool) ($formValues['rrr_membership_at_period_start'] ?? false),
                'rrr_valid_from' => $formValues['rrr_valid_from'] ?? null,
                'rrr_valid_until' => $formValues['rrr_valid_until'] ?? null
            );
            $service->saveRule($rule, $type, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'rule-inline':
            // One field of a rule changed in the list: the required credits or one end of the
            // validity. The rest of the rule stays as it is, and the service validates as for the form.
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $field = admFuncVariableIsValid($_POST, 'field', 'string', array(
                'requireValue' => true,
                'validValues' => array('rrr_required_credits', 'rrr_valid_from', 'rrr_valid_until')
            ));
            $value = trim((string) ($_POST['value'] ?? ''));

            $rule = new RequirementRoleRule($gDb);
            $rule->readDataByUuid($getUuid);
            if ((int) $rule->getValue('rrr_id') === 0) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            $type = new RequirementType($gDb);
            $type->readDataById((int) $rule->getValue('rrr_requirement_type_id'));

            $service->saveRule($rule, $type, array($field => $field === 'rrr_required_credits' ? (float) $value : $value));
            // What the cell shows from now on, formatted as the page formats it.
            $display = $field === 'rrr_required_credits'
                ? rtrim(rtrim(number_format((float) $rule->getValue($field, 'database'), 2, '.', ''), '0'), '.')
                : RulePresenter::formatRuleDate((string) $rule->getValue($field, 'database'));
            echo json_encode(array('status' => 'success', 'display' => $display));
            break;

        case 'type-delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $type = new RequirementType($gDb);
            $type->readDataByUuid($getUuid);
            $service->deleteType($type);
            echo json_encode(array('status' => 'success'));
            break;

        case 'rule-delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $rule = new RequirementRoleRule($gDb);
            $rule->readDataByUuid($getUuid);
            $service->deleteRule($rule);
            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, str_contains($getMode ?? '', 'save') || str_contains($getMode ?? '', 'delete'));
}
