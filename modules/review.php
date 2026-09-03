<?php
/**
 ***********************************************************************************************
 * Requirements plugin - administrative review: per-member coverage of one requirement in one
 * period, with the accepted credits editable in place (administrators only).
 *
 * Parameters:
 * mode        : list (default) | save
 * requirement : UUID of the requirement type under review
 * period      : raw value of the credited-period control (year, YYYY-MM, YYYY-Www or a date)
 * periodq     : quarter "1".."4", for a requirement with a quarterly period
 * status      : coverage status the roster is narrowed to
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Presenter\ReviewPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    if (!Component::isAdministrable(RequirementsPlugin::ID)) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'save')
    ));
    $getRequirement = admFuncVariableIsValid($_GET, 'requirement', 'uuid');
    // The period control submits a year, a month, an ISO week or a date depending on the
    // requirement, so it arrives as a string and the presenter resolves it once it knows which.
    $getPeriod = admFuncVariableIsValid($_GET, 'period', 'string');
    $getQuarter = admFuncVariableIsValid($_GET, 'periodq', 'string');
    $getStatus = admFuncVariableIsValid($_GET, 'status', 'string');

    switch ($getMode) {
        case 'list':
            $page = new ReviewPresenter();
            $page->createList($getRequirement, $getPeriod, $getQuarter, $getStatus);
            $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), 'bi-clipboard-data');
            $page->show();
            break;

        case 'save':
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);

            // Only the registered accepted-credit fields survive validate(), so the loop sees the
            // rows the page actually rendered. An empty field clears the acceptance, which is not
            // the same answer as an accepted zero: that one is an explicit rejection (§6.6).
            $accepted = array();
            foreach ($formValues as $key => $value) {
                if (!str_starts_with($key, 'accepted_')) {
                    continue;
                }
                $creditId = (int) substr($key, strlen('accepted_'));
                if ($creditId <= 0) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $raw = trim((string) $value);
                $accepted[$creditId] = $raw === '' ? null : (float) $raw;
            }

            (new ActivityService($gDb))->acceptCredits($accepted);

            // Back to the roster the reviewer was working through, filters and all. CURRENT_URL
            // already carries mode=save, and encodeUrl() only appends, so the page is named afresh.
            echo json_encode(array('status' => 'success', 'url' => SecurityUtils::encodeUrl(
                PluginRegistry::requireEnabled(RequirementsPlugin::ID)->getUrl('review.php'),
                array(
                    'mode' => 'list',
                    'requirement' => $getRequirement,
                    'period' => $getPeriod,
                    'periodq' => $getQuarter,
                    'status' => $getStatus
                )
            )));
            break;
    }
} catch (Throwable $e) {
    handleException($e, ($getMode ?? '') === 'save');
}
