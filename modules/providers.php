<?php
/**
 ***********************************************************************************************
 * Requirements plugin - provider list and edit.
 *
 * Parameters:
 * mode : list (default) | edit | save | delete
 * uuid : UUID of the provider for edit / save / delete
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Presenter\ProviderPresenter;
use AdmidioPlugin\Requirements\Service\ProviderService;

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
            $page = new ProviderPresenter();
            $page->createList();
            $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), 'bi-shop');
            $page->show();
            break;

        case 'edit':
            $page = new ProviderPresenter($getUuid);
            $page->createEditForm($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'save':
            $provider = new Provider($gDb);
            if ($getUuid !== '') {
                $provider->readDataByUuid($getUuid);
            }

            $form = $gCurrentSession->getFormObject($_POST['adm_csrf_token']);
            $formValues = $form->validate($_POST);

            $values = array();
            foreach (array('rqp_name', 'rqp_short_name', 'rqp_url', 'rqp_address', 'rqp_description') as $field) {
                if (array_key_exists($field, $formValues)) {
                    $values[$field] = $formValues[$field];
                }
            }
            if (array_key_exists('rqp_public', $formValues)) {
                $values['rqp_public'] = (bool) $formValues['rqp_public'];
            }
            if (array_key_exists('rqp_qualified', $formValues)) {
                $values['rqp_qualified'] = (bool) $formValues['rqp_qualified'];
            }

            (new ProviderService($gDb))->save($provider, $values);

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $provider = new Provider($gDb);
            $provider->readDataByUuid($getUuid);
            (new ProviderService($gDb))->delete($provider);
            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save', 'delete')));
}
