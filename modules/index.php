<?php
/**
 ***********************************************************************************************
 * Requirements plugin - main page: the fulfilment overview and the activity list, plus the
 * activity edit form.
 *
 * Parameters:
 * mode        : list (default) | edit | save | delete
 * uuid        : UUID of the activity for edit / save / delete
 * query       : free-text filter for the activity list
 * requirement : UUID of the requirement type whose credits the list shows
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Presenter\ActivityPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'edit', 'save', 'delete')
    ));
    // No defaultValue here: admFuncVariableIsValid() only returns early for a missing parameter
    // when none is set, otherwise it would validate the empty string against the datatype.
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');
    $getQuery = admFuncVariableIsValid($_GET, 'query', 'string');
    $getRequirement = admFuncVariableIsValid($_GET, 'requirement', 'uuid');

    switch ($getMode) {
        case 'list':
            $page = new ActivityPresenter();
            $page->createList($getQuery, $getRequirement);
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

            $skip = array('rqa_full_day', 'rqa_begin', 'rqa_end');
            $values = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'rqa_') && !in_array($key, $skip, true)) {
                    $values[$key] = $value;
                }
            }

            // Split the two datetime fields back into their date and time columns.
            $fullDay = !empty($formValues['rqa_full_day']);
            $values['rqa_begin_date'] = $formValues['rqa_begin'] ?? '';
            $values['rqa_end_date'] = ($formValues['rqa_end'] ?? '') !== '' ? $formValues['rqa_end'] : null;
            $values['rqa_begin_time'] = ($fullDay || trim((string) ($values['rqa_begin_time'] ?? '')) === '')
                ? null : $values['rqa_begin_time'];
            $values['rqa_end_time'] = ($fullDay || $values['rqa_end_date'] === null
                || trim((string) ($values['rqa_end_time'] ?? '')) === '')
                ? null : $values['rqa_end_time'];

            // "Create new provider" drop-down entry: save without a provider, then hand off to the
            // provider form and return to this activity afterwards.
            $createProvider = ($formValues['rqa_provider_id'] ?? '') === '__new__';
            if ($createProvider) {
                $values['rqa_provider_id'] = null;
            }

            $activityService->save($activity, $values);

            // Persist the per-requirement credit contributions.
            $typeService = new RequirementTypeService($gDb);
            foreach ($typeService->findTypes() as $typeRow) {
                $typeId = (int) $typeRow['rqt_id'];
                if (!array_key_exists('credit_' . $typeId, $formValues)) {
                    continue;
                }

                $claimed = trim((string) $formValues['credit_' . $typeId]) === ''
                    ? null : (float) $formValues['credit_' . $typeId];
                $accepted = array_key_exists('accepted_' . $typeId, $formValues)
                    && trim((string) $formValues['accepted_' . $typeId]) !== ''
                    ? (float) $formValues['accepted_' . $typeId] : null;

                $type = new RequirementType($gDb);
                $type->readDataById($typeId);

                // Only resolve the period control (year / YYYY-MM / YYYY-Www / year+quarter / date)
                // when a value is actually being written; an untouched row must not fail validation.
                $periodDate = null;
                $period = trim((string) ($formValues['period_' . $typeId] ?? ''));
                if ($period !== '' && ($claimed !== null || $accepted !== null)) {
                    $periodDate = FulfilmentService::periodInputToDate(
                        $type,
                        $period,
                        trim((string) ($formValues['periodq_' . $typeId] ?? ''))
                    );
                }

                $activityService->syncCredit($activity, $type, $periodDate, $claimed, $accepted);
            }

            if ($createProvider) {
                $plugin = PluginRegistry::requireEnabled(RequirementsPlugin::ID);
                $activityEditUrl = SecurityUtils::encodeUrl(
                    $plugin->getUrl('index.php'),
                    array('mode' => 'edit', 'uuid' => $activity->getValue('rqa_uuid'))
                );
                echo json_encode(array('status' => 'success', 'url' => SecurityUtils::encodeUrl(
                    $plugin->getUrl('providers.php'),
                    array('mode' => 'edit', 'return' => $activityEditUrl)
                )));
                break;
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
