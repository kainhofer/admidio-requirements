<?php
/**
 ***********************************************************************************************
 * Requirements plugin - download one certificate file of an activity.
 *
 * Parameters:
 * uuid : UUID of the file
 *
 * This is the only way an evidence file can be read. The files live under adm_my_files/, which a
 * correct installation keeps out of the document root, so there is no URL that reaches one without
 * passing through here - and here the owning activity decides, exactly as it does for every other
 * part of the record.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityFileService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    if (!RequirementsPlugin::isUsableByCurrentUser()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid', array('requireValue' => true));

    $file = new ActivityFile($gDb);
    $file->readDataByUuid($getUuid);
    // OrganizationScope answers a file of another organization the way it answers an unknown one,
    // so a UUID from elsewhere cannot be told apart from one that never existed.
    if ((int) $file->getValue('raf_id') === 0) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    // streamDownload() asks Activity::isVisible() itself and sends the file; it does not return.
    (new ActivityFileService($gDb))->streamDownload($file);
} catch (Throwable $e) {
    handleException($e);
}
