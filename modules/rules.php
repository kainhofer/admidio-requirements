<?php
/**
 ***********************************************************************************************
 * Requirements plugin - requirement type and role rule administration (administrators only).
 *
 * Parameters:
 * mode : list (default) | type-edit | type-save | type-delete | rule-edit | rule-save | rule-delete
 * uuid : UUID of the requirement type or role rule
 * type : UUID of the requirement type a new rule belongs to
 *
 * @copyright The Admidio Team
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

    if (!Component::isAdministrable(RequirementsPlugin::ID)) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'type-edit', 'type-save', 'type-delete', 'rule-edit', 'rule-save', 'rule-delete')
    ));
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');
    $getType = admFuncVariableIsValid($_GET, 'type', 'uuid');
    $getQuery = admFuncVariableIsValid($_GET, 'query', 'string');

    $service = new RequirementTypeService($gDb);

    switch ($getMode) {
        case 'list':
            $page = new RulePresenter();
            $page->createList($getQuery);
            $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), 'bi-list-check');
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
                $type->readDataByUuid($getUuid);
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
            $service->saveType($type, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'rule-save':
            $type = new RequirementType($gDb);
            $type->readDataByUuid($getType);

            $rule = new RequirementRoleRule($gDb);
            if ($getUuid !== '') {
                $rule->readDataByUuid($getUuid);
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
