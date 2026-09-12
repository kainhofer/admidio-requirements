<?php
/**
 ***********************************************************************************************
 * Requirements - plugin entry file.
 *
 * Runtime registration only. Domain work is performed by pages, services or CLI callbacks after
 * normal bootstrap and authorization.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Changelog\Service\ChangelogService;
use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit('This page may not be called directly!');
}

ChangelogService::registerCallback('getTableLabelArray', '', array(
    'req_providers' => 'PLG_REQUIREMENTS_PROVIDERS',
    'req_activities' => 'PLG_REQUIREMENTS_ACTIVITIES',
    'req_requirement_types' => 'PLG_REQUIREMENTS_REQUIREMENT_TYPES',
    'req_requirement_role_rules' => 'PLG_REQUIREMENTS_ROLE_RULES',
    'req_activity_credits' => 'PLG_REQUIREMENTS_ACTIVITY_CREDITS',
    'req_activity_files' => 'PLG_REQUIREMENTS_FILES'
));

ChangelogService::registerCallback('getAreaArray', '', array(
    'requirements' => array(
        'label' => 'PLG_REQUIREMENTS_NAME',
        'section' => 'content_modules',
        'enabledBy' => array(RequirementsPlugin::SETTING_ACCESS),
        'tables' => array(
            'req_providers',
            'req_activities',
            'req_requirement_types',
            'req_requirement_role_rules',
            'req_activity_credits',
            'req_activity_files'
        )
    )
));

ChangelogService::registerCallback('getFieldTranslations', '', array(
    'rqp_short_name' => 'PLG_REQUIREMENTS_PROVIDER_SHORT_NAME',
    'rqp_qualified' => 'PLG_REQUIREMENTS_PROVIDER_QUALIFIED',
    'rqp_public' => 'PLG_REQUIREMENTS_PROVIDER_PUBLIC',
    'rqa_begin_date' => 'PLG_REQUIREMENTS_BEGIN_DATE',
    'rqa_certificate_date' => 'PLG_REQUIREMENTS_CERTIFICATE_DATE',
    'rac_period_reference' => 'PLG_REQUIREMENTS_CREDITED_PERIOD',
    'rac_credits_claimed' => 'PLG_REQUIREMENTS_CREDITS_CLAIMED',
    'rac_credits_accepted' => 'PLG_REQUIREMENTS_CREDITS_ACCEPTED'
));

// The changelog hands these callbacks nothing but the table name and reads the record by id or UUID
// afterwards, so the organization check cannot live here. It lives in the entities themselves, which
// scope every read to the current organization - see Entity\OrganizationScope.
ChangelogService::registerCallback(
    'getObjectForTable',
    'req_providers',
    static function (): Provider {
        global $gDb;
        return new Provider($gDb);
    }
);
ChangelogService::registerCallback(
    'getObjectForTable',
    'req_activities',
    static function (): Activity {
        global $gDb;
        return new Activity($gDb);
    }
);
ChangelogService::registerCallback(
    'getObjectForTable',
    'req_activity_files',
    static function (): ActivityFile {
        global $gDb;
        return new ActivityFile($gDb);
    }
);
ChangelogService::registerCallback(
    'getObjectForTable',
    'req_requirement_types',
    static function (): RequirementType {
        global $gDb;
        return new RequirementType($gDb);
    }
);
ChangelogService::registerCallback(
    'getObjectForTable',
    'req_requirement_role_rules',
    static function (): RequirementRoleRule {
        global $gDb;
        return new RequirementRoleRule($gDb);
    }
);
ChangelogService::registerCallback(
    'getObjectForTable',
    'req_activity_credits',
    static function (): ActivityCredit {
        global $gDb;
        return new ActivityCredit($gDb);
    }
);

/*
 * Where a changelog row points. Without this the record name in the change history is plain text,
 * so an administrator reading "who changed what" has no way back to the thing that was changed.
 *
 * A rule and a credit have no page of their own - they are edited inside their requirement type and
 * their activity - so they link to the page that owns them. The rule needs its requirement type
 * looked up for that, which is why it is the one callback that reads.
 */
ChangelogService::registerCallback(
    'createLink',
    'req_providers',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        return $plugin === null || $uuid === '' ? '' : SecurityUtils::encodeUrl(
            $plugin->getUrl('providers.php'),
            array('mode' => 'edit', 'uuid' => $uuid)
        );
    }
);
ChangelogService::registerCallback(
    'createLink',
    'req_activities',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        return $plugin === null || $uuid === '' ? '' : SecurityUtils::encodeUrl(
            $plugin->getUrl('index.php'),
            array('mode' => 'edit', 'uuid' => $uuid)
        );
    }
);
ChangelogService::registerCallback(
    'createLink',
    'req_requirement_types',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        return $plugin === null || $uuid === '' ? '' : SecurityUtils::encodeUrl(
            $plugin->getUrl('rules.php'),
            array('mode' => 'type-edit', 'uuid' => $uuid)
        );
    }
);
ChangelogService::registerCallback(
    'createLink',
    'req_requirement_role_rules',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        return $plugin === null || $uuid === '' ? '' : SecurityUtils::encodeUrl(
            $plugin->getUrl('rules.php'),
            array('mode' => 'rule-edit', 'uuid' => $uuid)
        );
    }
);
ChangelogService::registerCallback(
    'createLink',
    'req_activity_credits',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        global $gDb;

        // A credit is edited on its activity, so the link needs the activity the credit belongs to.
        $credit = new ActivityCredit($gDb, (int) $id);
        if ((int) $credit->getValue('rac_id') === 0) {
            return '';
        }
        $activity = new Activity($gDb, (int) $credit->getValue('rac_activity_id'));
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        if ($plugin === null || (int) $activity->getValue('rqa_id') === 0) {
            return '';
        }

        return SecurityUtils::encodeUrl(
            $plugin->getUrl('index.php'),
            array('mode' => 'edit', 'uuid' => $activity->getValue('rqa_uuid'))
        );
    }
);

ChangelogService::registerCallback(
    'createLink',
    'req_activity_files',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        global $gDb;

        // A certificate is managed on its activity, so the link needs the activity it belongs to.
        $file = new ActivityFile($gDb, (int) $id);
        if ((int) $file->getValue('raf_id') === 0) {
            return '';
        }
        $activity = new Activity($gDb, (int) $file->getValue('raf_activity_id'));
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        if ($plugin === null || (int) $activity->getValue('rqa_id') === 0) {
            return '';
        }

        return SecurityUtils::encodeUrl(
            $plugin->getUrl('index.php'),
            array('mode' => 'edit', 'uuid' => $activity->getValue('rqa_uuid'))
        );
    }
);

// The requirements right, not the generic organization-administrator flag: a site that delegates
// requirements administration through the component right would otherwise be denied its own change
// history, while an administrator who was never given that right would be granted it.
//
// Component::isAdministrable() always answers for the current user, so the callback only speaks for
// the user it is asked about when that is the same person. Core already grants an organization
// administrator every table before this callback is reached, so answering "no" here never takes
// access away - it only adds it for the holder of the requirements right.
ChangelogService::registerCallback('getPermittedTables', '', static function ($user): array {
    global $gCurrentUser;

    if ((int) $user->getValue('usr_id') !== (int) $gCurrentUser->getValue('usr_id')
        || !Component::isAdministrable(RequirementsPlugin::ID)) {
        return array();
    }

    return array(
        'req_providers',
        'req_activities',
        'req_requirement_types',
        'req_requirement_role_rules',
        'req_activity_credits',
        'req_activity_files'
    );
});
