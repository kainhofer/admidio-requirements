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
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
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
    'req_activity_credits' => 'PLG_REQUIREMENTS_ACTIVITY_CREDITS'
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
            'req_activity_credits'
        )
    )
));

ChangelogService::registerCallback('getFieldTranslations', '', array(
    'rqp_short_name' => 'PLG_REQUIREMENTS_PROVIDER_SHORT_NAME',
    'rqp_qualified' => 'PLG_REQUIREMENTS_PROVIDER_QUALIFIED',
    'rqp_public' => 'PLG_REQUIREMENTS_PROVIDER_PUBLIC',
    'rqa_begin_date' => 'PLG_REQUIREMENTS_BEGIN_DATE',
    'rqa_completion_date' => 'PLG_REQUIREMENTS_COMPLETION_DATE',
    'rqa_examination_date' => 'PLG_REQUIREMENTS_EXAMINATION_DATE',
    'rac_period_reference' => 'PLG_REQUIREMENTS_CREDITED_PERIOD',
    'rac_credits_claimed' => 'PLG_REQUIREMENTS_CREDITS_CLAIMED',
    'rac_credits_accepted' => 'PLG_REQUIREMENTS_CREDITS_ACCEPTED'
));

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

ChangelogService::registerCallback('getPermittedTables', '', static function ($user): array {
    if (!$user->isAdministrator()) {
        return array();
    }

    return array(
        'req_providers',
        'req_activities',
        'req_requirement_types',
        'req_requirement_role_rules',
        'req_activity_credits'
    );
});
