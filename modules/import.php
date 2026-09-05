<?php
/**
 ***********************************************************************************************
 * Requirements plugin - import wizard: a CSV or spreadsheet of activities and their credits.
 *
 * Parameters:
 * mode : file (default) | read | mapping | map | preview | import | result
 *
 * The four steps of concept §12 alternate between a page and the handler that leaves it. A form in
 * Admidio submits by ajax and expects a URL back, so `read`, `map` and `import` do the work and
 * answer with the address of the next page, and the pages themselves are plain GET requests -
 * which is also what keeps a reload from uploading, or importing, anything a second time.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\PhpIniUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Presenter\ImportPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ImportService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'file',
        'validValues' => array('file', 'read', 'mapping', 'map', 'preview', 'import', 'result')
    ));

    /**
     * The address of one wizard step.
     */
    $stepUrl = static function (string $mode): string {
        return SecurityUtils::encodeUrl(
            PluginRegistry::requireEnabled(RequirementsPlugin::ID)->getUrl('import.php'),
            array('mode' => $mode)
        );
    };

    switch ($getMode) {
        case 'file':
            if (!PhpIniUtils::isFileUploadEnabled()) {
                throw new Exception('SYS_SERVER_NO_UPLOAD');
            }

            // A new run starts with nothing left of the previous one, so a file that was abandoned
            // halfway cannot be imported later by somebody who reaches a later step by its URL.
            unset($_SESSION[ImportPresenter::SESSION_KEY]);

            $page = new ImportPresenter();
            $page->createFileForm();
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline(), 'bi-upload');
            $page->show();
            break;

        case 'read':
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);

            $upload = $_FILES['userfile']['tmp_name'][0] ?? '';
            if (($_FILES['userfile']['error'][0] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE) {
                throw new Exception('SYS_FILE_TO_LARGE_SERVER', array(ini_get('upload_max_filesize')));
            }
            if ($upload === '') {
                throw new Exception('SYS_FIELD_EMPTY', array('SYS_FILE'));
            }
            if (!is_uploaded_file($upload)) {
                throw new Exception('SYS_FILE_NOT_EXIST');
            }

            $duplicates = (string) ($formValues['duplicates'] ?? '');
            if (!in_array($duplicates, ImportService::DUPLICATE_POLICIES, true)) {
                // REQ-DEC-008: the wizard does not move on without an answer to this one.
                throw new Exception('SYS_FIELD_EMPTY', array('PLG_REQUIREMENTS_IMPORT_DUPLICATES'));
            }

            $_SESSION[ImportPresenter::SESSION_KEY] = array(
                'rows' => (new ImportService($gDb))->readFile(
                    $upload,
                    (string) ($formValues['format'] ?? 'AUTO'),
                    (string) ($formValues['import_coding'] ?? ''),
                    (string) ($formValues['import_separator'] ?? ''),
                    'AUTO',
                    (string) ($formValues['import_sheet'] ?? '')
                ),
                'firstRowHeader' => !empty($formValues['first_row_header']),
                'duplicates' => $duplicates,
                'assignments' => array()
            );

            echo json_encode(array('status' => 'success', 'url' => $stepUrl('mapping')));
            break;

        case 'mapping':
            ImportPresenter::state();

            $page = new ImportPresenter();
            $page->createMappingForm();
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'map':
            ImportPresenter::state();
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);

            // Only the map_* fields the mapping form registered survive validate(), so what is kept
            // here is exactly the set of columns the page offered.
            $assignments = array();
            foreach ($formValues as $key => $value) {
                if (str_starts_with($key, 'map_') && trim((string) $value) !== '') {
                    $assignments[substr($key, strlen('map_'))] = trim((string) $value);
                }
            }
            $_SESSION[ImportPresenter::SESSION_KEY]['assignments'] = $assignments;

            // The mapping is resolved here rather than on the preview page, so a mapping the service
            // refuses is reported on the form the operator is still looking at.
            (new ImportService($gDb))->resolveMapping(
                $_SESSION[ImportPresenter::SESSION_KEY]['rows'],
                $assignments,
                $_SESSION[ImportPresenter::SESSION_KEY]['firstRowHeader'],
                Component::isAdministrable(RequirementsPlugin::ID)
            );

            echo json_encode(array('status' => 'success', 'url' => $stepUrl('preview')));
            break;

        case 'preview':
            $state = ImportPresenter::state();
            $service = new ImportService($gDb);

            $page = new ImportPresenter();
            $page->createPreview($service->analyse(
                $state['rows'],
                $service->resolveMapping(
                    $state['rows'],
                    $state['assignments'],
                    $state['firstRowHeader'],
                    Component::isAdministrable(RequirementsPlugin::ID)
                ),
                array('firstRowHeader' => $state['firstRowHeader'], 'duplicates' => $state['duplicates'])
            ));
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'import':
            $state = ImportPresenter::state();
            $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);

            $service = new ImportService($gDb);
            $result = $service->import(
                $state['rows'],
                $service->resolveMapping(
                    $state['rows'],
                    $state['assignments'],
                    $state['firstRowHeader'],
                    Component::isAdministrable(RequirementsPlugin::ID)
                ),
                array('firstRowHeader' => $state['firstRowHeader'], 'duplicates' => $state['duplicates'])
            );

            // The file is gone from the session before the result page is offered: leaving it there
            // would let a reload of that page import the same file a second time.
            unset($_SESSION[ImportPresenter::SESSION_KEY]);
            $_SESSION[ImportPresenter::SESSION_KEY . '_result'] = $result;

            echo json_encode(array('status' => 'success', 'url' => $stepUrl('result')));
            break;

        case 'result':
            $result = $_SESSION[ImportPresenter::SESSION_KEY . '_result'] ?? null;
            if (!is_array($result)) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            unset($_SESSION[ImportPresenter::SESSION_KEY . '_result']);

            $page = new ImportPresenter();
            $page->createResult($result);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('read', 'map', 'import'), true));
}
