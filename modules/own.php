<?php
/**
 ***********************************************************************************************
 * Requirements plugin - the requirements a member tracks for themselves.
 *
 * Every member manages their own here; nobody else sees them.
 *
 * Parameters:
 * mode : list (default) | edit | save | delete
 * uuid : UUID of the member's own requirement
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Presenter\OwnRequirementPresenter;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'edit', 'save', 'delete')
    ));
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');

    switch ($getMode) {
        case 'list':
            $page = new OwnRequirementPresenter();
            $page->createList();
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'edit':
            $page = new OwnRequirementPresenter($getUuid);
            $page->createEditForm($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'save':
            $type = OwnRequirementPresenter::readOwnType($getUuid);
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);
            $values = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'rqt_') || str_starts_with($key, 'rrr_')) {
                    $values[$key] = $value;
                }
            }
            $values['rqt_active'] = (bool) ($formValues['rqt_active'] ?? false);
            (new RequirementTypeService($gDb))->saveOwnType($type, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            (new RequirementTypeService($gDb))->deleteOwnType(OwnRequirementPresenter::readOwnType($getUuid));
            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save', 'delete'), true));
}
