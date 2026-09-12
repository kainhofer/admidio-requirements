<?php
/**
 ***********************************************************************************************
 * Requirements plugin - main page: the fulfilment overview and the activity list, plus the
 * activity edit form.
 *
 * Parameters:
 * mode        : list (default) | edit | save | submit | delete
 * uuid        : UUID of the activity for edit / save / delete
 * query       : free-text filter for the activity list
 * requirement : UUID of the requirement type whose credits the list shows
 * period      : credited period the list is narrowed to, for one bar of the overview
 * user        : UUID of the member the list is narrowed to; administrators only
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\Presenter\ActivityPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'edit', 'save', 'submit', 'delete', 'file-delete')
    ));
    // No defaultValue here: admFuncVariableIsValid() only returns early for a missing parameter
    // when none is set, otherwise it would validate the empty string against the datatype.
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');
    $getQuery = admFuncVariableIsValid($_GET, 'query', 'string');
    $getRequirement = admFuncVariableIsValid($_GET, 'requirement', 'uuid');
    $getPeriod = admFuncVariableIsValid($_GET, 'period', 'date');
    $getUser = admFuncVariableIsValid($_GET, 'user', 'uuid');
    $getFile = admFuncVariableIsValid($_GET, 'file', 'uuid');

    switch ($getMode) {
        case 'list':
            $page = new ActivityPresenter();
            $page->createList($getQuery, $getRequirement, $getPeriod, $getUser);
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
                // Without this an unknown or stale UUID would leave a new record, and the save would
                // quietly create one instead of saying that the activity is gone.
                $activity->readDataByUuid($getUuid);
                if ((int) $activity->getValue('rqa_id') === 0) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
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

            // Collect the per-requirement credit rows the form submitted. The service writes them
            // together with the activity in one transaction.
            $creditInput = array();
            foreach ((new RequirementTypeService($gDb))->findTypes() as $typeRow) {
                $typeId = (int) $typeRow['rqt_id'];
                if (!array_key_exists('credit_' . $typeId, $formValues)) {
                    continue;
                }

                $creditInput[$typeId] = array(
                    'period' => trim((string) ($formValues['period_' . $typeId] ?? '')),
                    'quarter' => trim((string) ($formValues['periodq_' . $typeId] ?? '')),
                    'claimed' => trim((string) $formValues['credit_' . $typeId]) === ''
                        ? null : (float) $formValues['credit_' . $typeId],
                    'accepted' => array_key_exists('accepted_' . $typeId, $formValues)
                        && trim((string) $formValues['accepted_' . $typeId]) !== ''
                        ? (float) $formValues['accepted_' . $typeId] : null
                );
            }

            $activityService->saveWithCredits($activity, $values, $creditInput);

            // The certificates are attached only now, because a new activity has no id until the
            // save above gave it one. A rejected file does not undo the activity - it is already
            // committed - so the refusal says so rather than pretending nothing happened.
            $fileError = '';
            if (ActivityFileService::isEnabled() && isset($_FILES['adm_requirements_files'])) {
                $fileService = new ActivityFileService($gDb);
                foreach (ActivityFileService::uploadsFrom($_FILES['adm_requirements_files']) as $upload) {
                    try {
                        $fileService->add($activity, $upload);
                    } catch (Throwable $fileException) {
                        $fileError = $fileException->getMessage();
                        break;
                    }
                }
            }
            if ($fileError !== '') {
                throw new Exception('PLG_REQUIREMENTS_FILES_SAVED_BUT_REJECTED', array($fileError));
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

        case 'submit':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $activity = new Activity($gDb);
            $activity->readDataByUuid($getUuid);
            (new ActivityService($gDb))->submit($activity);
            echo json_encode(array('status' => 'success'));
            break;

        case 'delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $activity = new Activity($gDb);
            $activity->readDataByUuid($getUuid);
            (new ActivityService($gDb))->delete($activity);
            echo json_encode(array('status' => 'success'));
            break;

        case 'file-delete':
            SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);

            $file = new ActivityFile($gDb);
            $file->readDataByUuid($getFile);
            // OrganizationScope answers a file of another organization the way it answers an
            // unknown one, and the service asks the owning activity whether this user may edit it.
            if ((int) $file->getValue('raf_id') === 0) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            (new ActivityFileService($gDb))->delete($file);
            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save', 'submit', 'delete', 'file-delete')));
}
