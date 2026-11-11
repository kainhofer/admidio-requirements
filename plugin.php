<?php
/**
 ***********************************************************************************************
 * Requirements - plugin entry file.
 *
 * Runtime registration only. Domain work is performed by pages, services or CLI callbacks after
 * normal bootstrap and authorization.
 *
 * @copyright Reinhold Kainhofer
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

/*
 * How the change history names and formats the columns of the requirements tables. A column that is
 * missing here is shown by its raw database name, so every column that is logged has an entry - the
 * ones that are not meant to appear (the organization, the ids that say which requirement or which
 * role a record belongs to) are left out by the entities' getIgnoredLogColumns() instead.
 *
 * The REQUIREMENTS_* types are the plugin's own and formatted by the callbacks further down.
 */
$requirementsList = static function (array $entries): array {
    return array_map(static fn(string $key): array => array('text' => $key), $entries);
};
ChangelogService::registerCallback('getFieldTranslations', '', array(
    // Providers
    'rqp_name' => 'SYS_NAME',
    'rqp_short_name' => 'PLG_REQUIREMENTS_PROVIDER_SHORT_NAME',
    'rqp_address' => 'SYS_ADDRESS',
    'rqp_url' => array('name' => 'SYS_WEBSITE', 'type' => 'URL'),
    'rqp_description' => 'SYS_DESCRIPTION',
    'rqp_qualified' => array('name' => 'PLG_REQUIREMENTS_PROVIDER_QUALIFIED', 'type' => 'BOOL'),
    'rqp_public' => array('name' => 'PLG_REQUIREMENTS_PROVIDER_PUBLIC', 'type' => 'BOOL'),
    // Activities
    'rqa_title' => 'SYS_TITLE',
    'rqa_description' => 'SYS_DESCRIPTION',
    'rqa_url' => array('name' => 'SYS_WEBSITE', 'type' => 'URL'),
    'rqa_location' => 'PLG_REQUIREMENTS_LOCATION',
    'rqa_provider_id' => array('name' => 'PLG_REQUIREMENTS_PROVIDER', 'type' => 'REQUIREMENTS_PROVIDER'),
    'rqa_begin_date' => array('name' => 'PLG_REQUIREMENTS_BEGIN_DATE', 'type' => 'DATE'),
    'rqa_begin_time' => 'PLG_REQUIREMENTS_BEGIN_TIME',
    'rqa_end_date' => array('name' => 'PLG_REQUIREMENTS_END_DATE', 'type' => 'DATE'),
    'rqa_end_time' => 'PLG_REQUIREMENTS_END_TIME',
    'rqa_certificate_date' => array('name' => 'PLG_REQUIREMENTS_CERTIFICATE_DATE', 'type' => 'DATE'),
    'rqa_attendance' => array('name' => 'PLG_REQUIREMENTS_ATTENDANCE', 'type' => 'REQUIREMENTS_CREDITS'),
    'rqa_comments' => 'SYS_COMMENT',
    'rqa_status' => array('name' => 'PLG_REQUIREMENTS_STATUS', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'draft' => 'PLG_REQUIREMENTS_STATUS_DRAFT',
        'submitted' => 'PLG_REQUIREMENTS_STATUS_SUBMITTED',
        'withdrawn' => 'PLG_REQUIREMENTS_STATUS_WITHDRAWN'
    ))),
    'rqa_review_status' => array('name' => 'PLG_REQUIREMENTS_REVIEW_STATUS', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'open' => 'PLG_REQUIREMENTS_REVIEW_OPEN',
        'accepted' => 'PLG_REQUIREMENTS_REVIEW_ACCEPTED',
        'partially_accepted' => 'PLG_REQUIREMENTS_REVIEW_PARTIALLY_ACCEPTED',
        'not_accepted' => 'PLG_REQUIREMENTS_REVIEW_NOT_ACCEPTED',
        'clarification' => 'PLG_REQUIREMENTS_REVIEW_CLARIFICATION'
    ))),
    'rqa_review_comments' => 'PLG_REQUIREMENTS_REVIEW_COMMENTS',
    // Requirement types
    'rqt_name' => 'SYS_NAME',
    'rqt_short_name' => 'PLG_REQUIREMENTS_SHORT_NAME',
    'rqt_description' => 'SYS_DESCRIPTION',
    'rqt_parent_id' => array('name' => 'PLG_REQUIREMENTS_PARENT_REQUIREMENT', 'type' => 'REQUIREMENTS_TYPE'),
    'rqt_period_unit' => array('name' => 'PLG_REQUIREMENTS_PERIOD_UNIT', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'year' => 'PLG_REQUIREMENTS_PERIOD_YEAR',
        'quarter' => 'PLG_REQUIREMENTS_PERIOD_QUARTER',
        'month' => 'PLG_REQUIREMENTS_PERIOD_MONTH',
        'week' => 'PLG_REQUIREMENTS_PERIOD_WEEK'
    ))),
    'rqt_period_count' => 'PLG_REQUIREMENTS_PERIOD_COUNT',
    'rqt_period_anchor' => array('name' => 'PLG_REQUIREMENTS_PERIOD_ANCHOR', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'calendar' => 'PLG_REQUIREMENTS_ANCHOR_CALENDAR',
        'last_fulfilment' => 'PLG_REQUIREMENTS_ANCHOR_LAST_FULFILMENT'
    ))),
    'rqt_multiple_roles_mode' => array('name' => 'PLG_REQUIREMENTS_MULTIPLE_ROLES_MODE', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'maximum' => 'PLG_REQUIREMENTS_MULTIPLE_ROLES_MAXIMUM',
        'minimum' => 'PLG_REQUIREMENTS_MULTIPLE_ROLES_MINIMUM',
        'sum' => 'PLG_REQUIREMENTS_MULTIPLE_ROLES_SUM'
    ))),
    'rqt_active' => array('name' => 'PLG_REQUIREMENTS_ACTIVE', 'type' => 'BOOL'),
    'rqt_sequence' => 'SYS_ORDER',
    // Role rules
    'rrr_required_credits' => array('name' => 'PLG_REQUIREMENTS_REQUIRED_CREDITS', 'type' => 'REQUIREMENTS_CREDITS'),
    'rrr_partial_period_mode' => array('name' => 'PLG_REQUIREMENTS_PARTIAL_PERIOD_MODE', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'full' => 'PLG_REQUIREMENTS_PARTIAL_FULL',
        'pro_rata' => 'PLG_REQUIREMENTS_PARTIAL_PRO_RATA'
    ))),
    'rrr_proration_basis' => array('name' => 'PLG_REQUIREMENTS_PRORATION_BASIS', 'type' => 'CUSTOM_LIST', 'entries' => $requirementsList(array(
        'month' => 'PLG_REQUIREMENTS_PRORATION_MONTH',
        'day' => 'PLG_REQUIREMENTS_PRORATION_DAY',
        'week' => 'PLG_REQUIREMENTS_PRORATION_WEEK'
    ))),
    'rrr_membership_at_period_start' => array('name' => 'PLG_REQUIREMENTS_MEMBERSHIP_AT_PERIOD_START', 'type' => 'BOOL'),
    'rrr_valid_from' => array('name' => 'PLG_REQUIREMENTS_VALID_FROM', 'type' => 'DATE'),
    'rrr_valid_until' => array('name' => 'PLG_REQUIREMENTS_VALID_UNTIL', 'type' => 'DATE'),
    // Credits
    'rac_period_reference' => array('name' => 'PLG_REQUIREMENTS_CREDITED_PERIOD', 'type' => 'DATE'),
    'rac_credits_claimed' => array('name' => 'PLG_REQUIREMENTS_CREDITS_CLAIMED', 'type' => 'REQUIREMENTS_CREDITS'),
    'rac_credits_accepted' => array('name' => 'PLG_REQUIREMENTS_CREDITS_ACCEPTED', 'type' => 'REQUIREMENTS_CREDITS'),
    // Certificates
    'raf_name' => 'SYS_NAME',
    'raf_mime_type' => 'SYS_FILE_TYPE',
    'raf_size' => array('name' => 'SYS_SIZE', 'type' => 'REQUIREMENTS_BYTES')
));

/*
 * The value types of those columns. A value reaches these callbacks already encoded for HTML, and what
 * they return is HTML as well.
 */
// A requirement or a provider named by its id is shown by its name and linked to its page; one that
// does not exist any more (or never belonged to this organization) is left as the plain id.
ChangelogService::registerCallback('formatValue', 'REQUIREMENTS_TYPE', static function ($value): string {
    global $gDb;

    $type = new RequirementType($gDb, (int) $value);
    return (int) $type->getValue('rqt_id') === 0 ? (string) $value : ChangelogService::createLink(
        $type->readableName(),
        'req_requirement_types',
        (int) $type->getValue('rqt_id'),
        (string) $type->getValue('rqt_uuid')
    );
});
ChangelogService::registerCallback('formatValue', 'REQUIREMENTS_PROVIDER', static function ($value): string {
    global $gDb;

    $provider = new Provider($gDb, (int) $value);
    return (int) $provider->getValue('rqp_id') === 0 ? (string) $value : ChangelogService::createLink(
        $provider->readableName(),
        'req_providers',
        (int) $provider->getValue('rqp_id'),
        (string) $provider->getValue('rqp_uuid')
    );
});
// "6.00" and "6" are the same number, and the history should not make the reader wonder: no trailing
// zeros, at most two decimals.
ChangelogService::registerCallback('formatValue', 'REQUIREMENTS_CREDITS', static function ($value): string {
    return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : (string) $value;
});
ChangelogService::registerCallback('formatValue', 'REQUIREMENTS_BYTES', static function ($value): string {
    return is_numeric($value) ? \Admidio\Infrastructure\Utils\FileSystemUtils::getHumanReadableBytes((int) $value) : (string) $value;
});

// The related object of an entry: the member of an activity, the requirement a credit counts towards,
// the role a rule applies to, and the activity a certificate belongs to.
foreach (array(
    'req_activities' => 'users',
    'req_activity_credits' => 'req_requirement_types',
    'req_requirement_role_rules' => 'roles',
    'req_activity_files' => 'req_activities'
) as $requirementsTable => $requirementsRelated) {
    ChangelogService::registerCallback(
        'getRelatedTable',
        $requirementsTable,
        static fn(): string => $requirementsRelated
    );
}

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
 * A credit and a certificate have no page of their own - they are edited inside their activity - so
 * they link to the page that owns them.
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
// A credit and a certificate are edited on their activity, and their entries carry the activity's UUID
// as their own (see ActivityCredit::adjustLogEntry() and ActivityFile::adjustLogEntry()), so the link
// needs no lookup - and works for a credit or certificate that has been deleted since.
foreach (array('req_activity_credits', 'req_activity_files') as $requirementsTable) {
    ChangelogService::registerCallback(
        'createLink',
        $requirementsTable,
        static function (string $text, string $module, int|string $id, string $uuid = ''): string {
            $plugin = PluginRegistry::get(RequirementsPlugin::ID);
            return $plugin === null || $uuid === '' ? '' : SecurityUtils::encodeUrl(
                $plugin->getUrl('index.php'),
                array('mode' => 'edit', 'uuid' => $uuid)
            );
        }
    );
}

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

// The fulfilment overview of the activity list, on the overview page as well.
RequirementsPlugin::registerWidget();
