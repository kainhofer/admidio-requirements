<?php
use Admidio\Infrastructure\Cli\CliTaskRegistry;
use AdmidioPlugin\Requirements\Cli\RequirementsCli;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ImportService;

if (PHP_SAPI !== 'cli') {
    exit('This page may not be called directly!');
}

$arg = static function (string $name, string $description, bool $required = true): array {
    return array('name' => $name, 'description' => $description, 'required' => $required, 'multiple' => false);
};
$opt = static function (
    string $name,
    string $description,
    string $value = 'VALUE',
    bool $required = false,
    bool $multiple = false,
    bool $flag = false,
    array $values = array()
): array {
    $definition = array(
        'name' => $name,
        'description' => $description,
        'required' => $required,
        'multiple' => $multiple,
        'flag' => $flag
    );
    if (!$flag) {
        $definition['value'] = $value;
    }
    if ($values !== array()) {
        $definition['values'] = $values;
    }
    return $definition;
};

$format = $opt('format', 'Output format.', 'FORMAT', false, false, false,
    array('table', 'record', 'json', 'json-api', 'csv', 'md', 'dokuwiki'));

CliTaskRegistry::register(
    'requirements:provider-list',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'providerList'),
    'List providers visible to the acting user.',
    'requirements:provider-list [--query=TEXT] [--format=FORMAT]',
    array(),
    array($opt('query', 'Free-text filter on name and short name.', 'TEXT'), $format),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE
);

$providerOptions = array(
    $opt('name', 'Provider name.', 'NAME'),
    $opt('short-name', 'Provider short name.', 'NAME'),
    $opt('address', 'Postal address.', 'TEXT'),
    $opt('url', 'Provider URL.', 'URL'),
    $opt('description', 'Description.', 'TEXT'),
    $opt('public', 'Whether the provider is public.', 'BOOL', false, false, false, array('true', 'false', '1', '0')),
    $opt('qualified', 'Whether the provider is qualified.', 'BOOL', false, false, false, array('true', 'false', '1', '0')),
    $format
);

CliTaskRegistry::register(
    'requirements:provider-add',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'providerAdd'),
    'Create a provider.',
    'requirements:provider-add NAME [options] [--dry-run]',
    array($arg('name', 'Provider name.')),
    $providerOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);
CliTaskRegistry::register(
    'requirements:provider-update',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'providerUpdate'),
    'Update a provider.',
    'requirements:provider-update PROVIDER [options] [--dry-run]',
    array($arg('provider', 'Provider UUID or id.')),
    $providerOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:provider-delete',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'providerDelete'),
    'Delete a provider. Activities keep the stored provider name.',
    'requirements:provider-delete PROVIDER --yes [--dry-run]',
    array($arg('provider', 'Provider UUID or id.')),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:requirement-list',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'requirementList'),
    'List configured requirement types.',
    'requirements:requirement-list [--format=FORMAT]',
    array(),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE
);

$requirementOptions = array(
    $opt('name', 'Requirement name.', 'NAME'),
    $opt('short-name', 'Requirement short name.', 'NAME'),
    $opt('description', 'Description.', 'TEXT'),
    $opt('period-unit', 'Calendar period unit.', 'UNIT', false, false, false, array('year', 'quarter', 'month', 'week')),
    $opt('period-count', 'Number of period units.', 'N'),
    $opt('period-anchor', 'Period anchor.', 'ANCHOR', false, false, false, array('calendar', 'last_fulfilment')),
    $opt('multiple-roles', 'How simultaneous role obligations combine.', 'MODE', false, false, false,
        array('maximum', 'minimum', 'sum')),
    $opt('active', 'Whether the requirement is active.', 'BOOL', false, false, false, array('true', 'false', '1', '0')),
    $opt('sequence', 'Display sequence.', 'N'),
    $format
);
CliTaskRegistry::register(
    'requirements:requirement-add',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'requirementAdd'),
    'Create a requirement type.',
    'requirements:requirement-add NAME --period-unit=UNIT [options] [--dry-run]',
    array($arg('name', 'Requirement name.')),
    $requirementOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);
CliTaskRegistry::register(
    'requirements:requirement-update',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'requirementUpdate'),
    'Correct or update the currently stored requirement definition.',
    'requirements:requirement-update REQUIREMENT [options] [--dry-run]',
    array($arg('requirement', 'Requirement UUID, id or short name.')),
    $requirementOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:requirement-delete',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'requirementDelete'),
    'Delete a requirement type. Its role rules go with it; existing credits block the deletion.',
    'requirements:requirement-delete REQUIREMENT --yes [--dry-run]',
    array($arg('requirement', 'Requirement UUID, id or short name.')),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:rule-list',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'ruleList'),
    'List role rules with their required credits and validity.',
    'requirements:rule-list [--requirement=REQUIREMENT] [--format=FORMAT]',
    array(),
    array($opt('requirement', 'Restrict to one requirement type.', 'REQUIREMENT'), $format),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE
);

$ruleOptions = array(
    $opt('role', 'Role id.', 'ID'),
    $opt('credits', 'Required credits.', 'N'),
    $opt('partial-mode', 'Partial-period handling.', 'MODE', false, false, false, array('full', 'pro_rata')),
    $opt('member-at-period-start', 'Require membership on the first day of the requirement period.', 'BOOL',
        false, false, false, array('true', 'false', '1', '0')),
    $opt('proration-basis', 'Proration granularity.', 'BASIS', false, false, false, array('month', 'day', 'week')),
    $opt('valid-from', 'First date on which this historical rule applies.', 'YYYY-MM-DD'),
    $opt('valid-until', 'Last date on which this historical rule applies.', 'YYYY-MM-DD'),
    $format
);
CliTaskRegistry::register(
    'requirements:rule-add',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'ruleAdd'),
    'Add a validity-dated role rule.',
    'requirements:rule-add REQUIREMENT ROLE_ID CREDITS [options] [--dry-run]',
    array(
        $arg('requirement', 'Requirement UUID, id or short name.'),
        $arg('role', 'Role id.'),
        $arg('credits', 'Required credits.')
    ),
    $ruleOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);
CliTaskRegistry::register(
    'requirements:rule-update',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'ruleUpdate'),
    'Correct a stored historical role rule.',
    'requirements:rule-update RULE [options] [--dry-run]',
    array($arg('rule', 'Rule UUID or id.')),
    $ruleOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:rule-delete',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'ruleDelete'),
    'Delete a role rule.',
    'requirements:rule-delete RULE --yes [--dry-run]',
    array($arg('rule', 'Rule UUID or id.')),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:activity-list',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'activityList'),
    'List activities. A normal member sees only their own.',
    'requirements:activity-list [--user=USER] [--query=TEXT] [--requirement=REQUIREMENT] [--format=FORMAT]',
    array(),
    array(
        $opt('user', 'Restrict to one member. Non-administrators may only select themselves.', 'USER'),
        $opt('query', 'Free-text filter on title and provider name.', 'TEXT'),
        $opt('requirement', 'Show the credited period and credits for this requirement.', 'REQUIREMENT'),
        $format
    ),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE
);

$activityOptions = array(
    $opt('title', 'Activity title.', 'TEXT'),
    $opt('user', 'Target user UUID, id or login; add only.', 'USER'),
    $opt('provider', 'Provider id.', 'ID'),
    $opt('description', 'Description.', 'TEXT'),
    $opt('url', 'Activity URL.', 'URL'),
    $opt('location', 'Location.', 'TEXT'),
    $opt('begin', 'Begin date.', 'YYYY-MM-DD'),
    $opt('begin-time', 'Begin time.', 'HH:MM'),
    $opt('end', 'End date.', 'YYYY-MM-DD'),
    $opt('end-time', 'End time.', 'HH:MM'),
    $opt('certificate', 'Certificate date (e.g. the exam date).', 'YYYY-MM-DD'),
    $opt('attendance', 'Attendance hours.', 'N'),
    $opt('comments', 'Member comments.', 'TEXT'),
    $opt('status', 'Activity status.', 'STATUS', false, false, false, array('draft', 'submitted', 'withdrawn')),
    $opt('review-status', 'Review status.', 'STATUS', false, false, false,
        array('open', 'accepted', 'partially_accepted', 'not_accepted', 'clarification')),
    $opt('review-comments', 'Review comments.', 'TEXT'),
    $format
);
CliTaskRegistry::register(
    'requirements:activity-add',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'activityAdd'),
    'Create an activity for the acting user or another member.',
    'requirements:activity-add TITLE --begin=YYYY-MM-DD [--user=USER] [options] [--dry-run]',
    array($arg('title', 'Activity title.')),
    $activityOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);
CliTaskRegistry::register(
    'requirements:activity-update',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'activityUpdate'),
    'Update an activity.',
    'requirements:activity-update ACTIVITY [options] [--dry-run]',
    array($arg('activity', 'Activity UUID or id.')),
    $activityOptions,
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);
CliTaskRegistry::register(
    'requirements:activity-submit',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'activitySubmit'),
    'Hand a draft activity over for review.',
    'requirements:activity-submit ACTIVITY [--dry-run]',
    array($arg('activity', 'Activity UUID or id.')),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE,
    true
);
CliTaskRegistry::register(
    'requirements:activity-delete',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'activityDelete'),
    'Delete an activity and its credit rows.',
    'requirements:activity-delete ACTIVITY --yes [--dry-run]',
    array($arg('activity', 'Activity UUID or id.')),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);
CliTaskRegistry::register(
    'requirements:credit-set',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'creditSet'),
    'Create or update one credited-period contribution for an activity.',
    'requirements:credit-set ACTIVITY REQUIREMENT PERIOD CLAIMED [--accepted=N] [--dry-run]',
    array(
        $arg('activity', 'Activity UUID or id.'),
        $arg('requirement', 'Requirement UUID, id or short name.'),
        $arg('period', 'Credited period or fulfilment date.'),
        $arg('claimed', 'Claimed credits.')
    ),
    array($opt('accepted', 'Accepted credits; zero explicitly rejects the claim.', 'N'), $format),
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:credit-delete',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'creditDelete'),
    'Remove one credited-period contribution from an activity.',
    'requirements:credit-delete ACTIVITY REQUIREMENT PERIOD --yes [--dry-run]',
    array(
        $arg('activity', 'Activity UUID or id.'),
        $arg('requirement', 'Requirement UUID, id or short name.'),
        $arg('period', 'Credited period or fulfilment date.')
    ),
    array($format),
    array(),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'requirements:report',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'report'),
    'Report requirement coverage for a period, optionally including contributing activities.',
    'requirements:report [--user=USER | --requirement=REQUIREMENT [--status=STATUS]] '
        . '[--period=YYYY-MM-DD] [--details] [--format=FORMAT]',
    array(),
    array(
        $opt('user', 'Target user UUID, id or login. Non-administrators may only select themselves.', 'USER'),
        $opt('requirement', 'Report one requirement across every member it concerns, instead of one '
            . 'member across their requirements. Administrators only.', 'REQUIREMENT'),
        $opt('status', 'With --requirement, keep only members with this coverage status.', 'STATUS',
            false, false, false, array('missing', 'fulfilled', 'overdue', 'never-fulfilled',
                'no-credits-required', 'additional-credits', 'none')),
        $opt('period', 'Date identifying the requested requirement period.', 'YYYY-MM-DD'),
        $opt('details', 'Include contributing activity rows.', '', false, false, true),
        $format
    ),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE
);

/*
 * Import. `--commit` is what separates the preview from the write, rather than the `--dry-run` the
 * mutating commands use: a preview here never writes at all, so there is no transaction to roll
 * back, and a command whose default is to write nothing should not have to be told so.
 */
CliTaskRegistry::register(
    'requirements:import',
    RequirementsPlugin::ID,
    array(RequirementsCli::class, 'import'),
    'Import activities and their credits from a CSV or spreadsheet file. Reports what would happen '
        . 'unless --commit is given.',
    'requirements:import FILE --duplicates=POLICY [--map=FIELD=COLUMN]... [--user=USER] [--commit]',
    array($arg('file', 'Path to the CSV, XLSX, XLS or ODS file.')),
    array(
        $opt('duplicates', 'What to do with a row that repeats an existing activity. Required.',
            'POLICY', true, false, false, ImportService::DUPLICATE_POLICIES),
        $opt('map', 'Assign one field: FIELD=COLUMN, where COLUMN is a header title or a '
            . 'zero-based index. Repeatable; headers that spell a field name map themselves.',
            'FIELD=COLUMN', false, true),
        $opt('user', 'Assign every row to this member, instead of reading the member from the file. '
            . 'Administrators only.', 'USER'),
        $opt('no-header', 'The first row is data, not column titles.', '', false, false, true),
        $opt('commit', 'Actually write the rows. Without it nothing is written.', '', false, false, true),
        $opt('format-in', 'Input format.', 'FORMAT', false, false, false, ImportService::inputFormats()),
        $opt('worksheet', 'Worksheet name or zero-based index.', 'SHEET'),
        $opt('encoding', 'CSV input encoding.', 'ENCODING', false, false, false,
            ImportService::INPUT_ENCODINGS),
        $opt('delimiter', 'CSV field delimiter.', 'CHAR', false, false, false,
            array_keys(ImportService::CSV_DELIMITERS)),
        $opt('enclosure', 'CSV field enclosure.', 'CHAR', false, false, false, ImportService::CSV_ENCLOSURES),
        $format
    ),
    array(),
    CliTaskRegistry::ACCESS_VISIBLE
);
