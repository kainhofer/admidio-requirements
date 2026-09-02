<?php
/**
 ***********************************************************************************************
 * Requirements plugin - activity list (with fulfilment overview) and activity edit.
 *
 * Parameters:
 * mode : list (default) | edit | save | delete
 * uuid : UUID of the activity for edit / save / delete
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Presenter\ActivityPresenter;
use AdmidioPlugin\Requirements\Service\ActivityService;
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
            $page = new ActivityPresenter();
            $page->createList();
            $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), 'bi-clipboard-check');
            $page->show();
            break;

        case 'edit':
            $page = new ActivityPresenter($getUuid);
            $page->createEditForm($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'save':
            $activityService = new ActivityService($gDb);

            $activity = new Activity($gDb);
            if ($getUuid !== '') {
                $activity->readDataByUuid($getUuid);
            }

            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);

            $values = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'rqa_')) {
                    $values[$key] = $value;
                }
            }

            $activityService->save($activity, $values);

            // Persist the per-requirement credit contributions.
            $typeService = new RequirementTypeService($gDb);
            foreach ($typeService->findTypes() as $typeRow) {
                $typeId = (int) $typeRow['rqt_id'];
                if (!array_key_exists('credit_' . $typeId, $formValues)) {
                    continue;
                }

                $claimedRaw = trim((string) $formValues['credit_' . $typeId]);
                $acceptedRaw = array_key_exists('accepted_' . $typeId, $formValues)
                    ? trim((string) $formValues['accepted_' . $typeId]) : '';
                $period = trim((string) ($formValues['period_' . $typeId] ?? ''));

                $type = new RequirementType($gDb);
                $type->readDataById($typeId);

                $activityService->syncCredit(
                    $activity,
                    $type,
                    $period === '' ? null : $period,
                    $claimedRaw === '' ? null : (float) $claimedRaw,
                    $acceptedRaw === '' ? null : (float) $acceptedRaw
                );
            }

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $activity = new Activity($gDb);
            $activity->readDataByUuid($getUuid);
            (new ActivityService($gDb))->delete($activity);
            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save', 'delete')));
}
