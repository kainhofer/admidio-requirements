<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Service\ImportService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

/**
 * The import wizard of concept §12: choose a file, say which column is what, look at what would
 * happen, and only then confirm.
 *
 * The four pages are four presenter methods over one service. Nothing here parses a cell or decides
 * what a duplicate is - the wizard's whole job is to carry the operator's answers from one step to
 * the next and to render what `ImportService::analyse()` reported. The preview page in particular
 * renders a result; it does not produce one, which is what keeps "the preview writes nothing" a
 * property of the service rather than a promise of the page.
 *
 * State between the steps lives in the session, as the core contact import wizard does it: the
 * parsed rows are too large for a form field, and re-reading the upload on every step would parse
 * the file three times and require the operator to re-upload it after every correction.
 */
class ImportPresenter extends RequirementsPresenter
{
    /** Where the wizard keeps the parsed file and the answers given so far. */
    public const SESSION_KEY = 'requirements_import';

    /** How many preview rows are rendered before the table is cut short. */
    private const PREVIEW_LIMIT = 200;

    /**
     * Step 1: the file, its format and how duplicates are to be treated.
     *
     * @throws Exception|\Smarty\Exception
     */
    public function createFileForm(): void
    {
        global $gL10n, $gCurrentSession;

        $this->setHtmlID('adm_requirements_import');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_IMPORT'));
        $this->addBackToActivities();

        $form = new FormPresenter(
            'adm_requirements_import_file_form',
            'requirements.import.file.tpl',
            $this->pageUrl('import.php', array('mode' => 'read')),
            $this,
            array('enableFileUpload' => true)
        );

        $formats = array(
            'AUTO' => $gL10n->get('SYS_AUTO_DETECT'),
            'XLSX' => $gL10n->get('SYS_EXCEL_2007_365'),
            'XLS' => $gL10n->get('SYS_EXCEL_97_2003'),
            'ODS' => $gL10n->get('SYS_ODF_SPREADSHEET'),
            'CSV' => $gL10n->get('SYS_COMMA_SEPARATED_FILE')
        );
        $form->addSelectBox('format', $gL10n->get('SYS_FORMAT'), $formats, array(
            'showContextDependentFirstEntry' => false,
            'defaultValue' => 'AUTO',
            'property' => FormPresenter::FIELD_REQUIRED
        ));
        $form->addFileUpload('userfile', $gL10n->get('SYS_CHOOSE_FILE'), array(
            'property' => FormPresenter::FIELD_REQUIRED,
            'allowedMimeTypes' => array(
                'text/comma-separated-values',
                'text/csv',
                'text/plain',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/vnd.oasis.opendocument.spreadsheet'
            )
        ));
        $form->addInput('import_sheet', $gL10n->get('SYS_WORKSHEET_NAMEINDEX'), '', array(
            'helpTextId' => 'PLG_REQUIREMENTS_IMPORT_WORKSHEET_HELP'
        ));
        $form->addCheckbox('first_row_header', $gL10n->get('PLG_REQUIREMENTS_IMPORT_FIRST_ROW_HEADER'), true, array(
            'helpTextId' => 'PLG_REQUIREMENTS_IMPORT_FIRST_ROW_HEADER_HELP'
        ));

        $encodings = array('' => $gL10n->get('SYS_DEFAULT_ENCODING_UTF8'));
        foreach (ImportService::INPUT_ENCODINGS as $encoding) {
            $encodings[$encoding] = $encoding === 'GUESS' ? $gL10n->get('SYS_ENCODING_GUESS') : $encoding;
        }
        $form->addSelectBox('import_coding', $gL10n->get('SYS_CODING'), $encodings, array(
            'showContextDependentFirstEntry' => false
        ));
        $form->addSelectBox(
            'import_separator',
            $gL10n->get('SYS_SEPARATOR_FOR_CSV_FILE'),
            array_combine(
                array_keys(ImportService::CSV_DELIMITERS),
                array_keys(ImportService::CSV_DELIMITERS)
            ),
            array('showContextDependentFirstEntry' => true)
        );

        // REQ-DEC-008 has no default answer, so neither does the control: the operator picks one
        // before the wizard will move on.
        $form->addSelectBox('duplicates', $gL10n->get('PLG_REQUIREMENTS_IMPORT_DUPLICATES'), array(
            'skip' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_DUPLICATES_SKIP'),
            'create' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_DUPLICATES_CREATE')
        ), array(
            'property' => FormPresenter::FIELD_REQUIRED,
            'helpTextId' => 'PLG_REQUIREMENTS_IMPORT_DUPLICATES_HELP'
        ));

        $form->addSubmitButton('adm_button_next', $gL10n->get('SYS_NEXT'), array('icon' => 'bi-arrow-right'));

        $this->assignSmartyVariable('introduction', $gL10n->get('PLG_REQUIREMENTS_IMPORT_DESC'));
        $this->assignSmartyVariable('columnHelp', $this->columnHelp());
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * Step 2: which column is which field.
     *
     * Every field the file already named by its header comes pre-selected, so the common case is a
     * page the operator only has to confirm. The credit and period columns are offered per
     * requirement type rather than as free text: a short name mistyped here would be found only by
     * the preview, and only by somebody who noticed the missing credits.
     *
     * @throws Exception|\Smarty\Exception
     */
    public function createMappingForm(): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $state = self::state();
        $this->setHtmlID('adm_requirements_import_mapping');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_IMPORT'));
        $this->addBackToActivities();

        $service = new ImportService($gDb);
        $suggested = array();
        try {
            $suggested = $service->resolveMapping(
                $state['rows'],
                array(),
                $state['firstRowHeader'],
                $this->isAdmin()
            );
        } catch (\Throwable) {
            // An incomplete file is not an error yet - the operator is here to complete it.
        }

        $columns = array('' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_COLUMN_UNUSED'));
        foreach ($this->columnLabels($state) as $index => $label) {
            $columns[(string) $index] = $label;
        }

        $form = new FormPresenter(
            'adm_requirements_import_mapping_form',
            'requirements.import.mapping.tpl',
            $this->pageUrl('import.php', array('mode' => 'map')),
            $this
        );

        $groups = array();
        if ($this->isAdmin()) {
            $groups[] = array(
                'label' => $gL10n->get('SYS_MEMBER'),
                'help' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_MEMBER_DESC'),
                'fields' => $this->addFields($form, $columns, $suggested, array(
                    'user_login' => $gL10n->get('SYS_USERNAME'),
                    'user_uuid' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_USER_UUID'),
                    'first_name' => $gL10n->get('SYS_FIRSTNAME'),
                    'last_name' => $gL10n->get('SYS_LASTNAME')
                ))
            );
        }

        $activityFields = array(
            'title' => $gL10n->get('SYS_TITLE'),
            'begin_date' => $gL10n->get('PLG_REQUIREMENTS_BEGIN_DATE'),
            'begin_time' => $gL10n->get('SYS_START'),
            'end_date' => $gL10n->get('SYS_END'),
            'end_time' => $gL10n->get('SYS_END'),
            'certificate_date' => $gL10n->get('PLG_REQUIREMENTS_CERTIFICATE_DATE'),
            'provider' => $gL10n->get('PLG_REQUIREMENTS_PROVIDER'),
            'location' => $gL10n->get('PLG_REQUIREMENTS_LOCATION'),
            'attendance' => $gL10n->get('PLG_REQUIREMENTS_ATTENDANCE'),
            'description' => $gL10n->get('SYS_DESCRIPTION'),
            'url' => $gL10n->get('SYS_WEBSITE'),
            'comments' => $gL10n->get('SYS_COMMENT'),
            'source_key' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_SOURCE_KEY'),
            'duplicate' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_DUPLICATE_COLUMN')
        );
        if ($this->isAdmin()) {
            $activityFields['status'] = $gL10n->get('PLG_REQUIREMENTS_STATUS');
        }
        $groups[] = array(
            'label' => $gL10n->get('PLG_REQUIREMENTS_ACTIVITY'),
            'help' => '',
            'fields' => $this->addFields($form, $columns, $suggested, $activityFields)
        );

        $creditFields = array();
        foreach ((new RequirementTypeService($gDb))->findTypes() as $type) {
            if (!(bool) $type['rqt_active']) {
                continue;
            }
            $reference = strtolower(trim((string) $type['rqt_short_name']));
            if ($reference === '') {
                $reference = (string) (int) $type['rqt_id'];
            }
            $creditFields['credit_' . $reference] =
                $gL10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED') . ' - ' . $type['rqt_name'];
            $creditFields['period_' . $reference] =
                $gL10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD') . ' - ' . $type['rqt_name'];
        }
        if ($creditFields !== array()) {
            $groups[] = array(
                'label' => $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPES'),
                'help' => $gL10n->get('PLG_REQUIREMENTS_IMPORT_CREDITS_DESC'),
                'fields' => $this->addFields($form, $columns, $suggested, $creditFields)
            );
        }

        $form->addSubmitButton('adm_button_next', $gL10n->get('SYS_NEXT'), array('icon' => 'bi-arrow-right'));

        $this->assignSmartyVariable('groups', $groups);
        $this->assignSmartyVariable('sampleHeaders', array_values($this->columnLabels($state)));
        $this->assignSmartyVariable('sampleRows', $this->sampleRows($state));
        $this->assignSmartyVariable('sampleLabel', $gL10n->get('PLG_REQUIREMENTS_IMPORT_SAMPLE'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * Step 3: what an import would do, and the button that does it.
     *
     * @param array{summary:array<string,int>,rows:array<int,array<string,mixed>>} $result
     * @throws Exception|\Smarty\Exception
     */
    public function createPreview(array $result): void
    {
        global $gL10n, $gCurrentSession;

        $this->setHtmlID('adm_requirements_import_preview');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_IMPORT'));
        $this->addBackToActivities();

        $form = new FormPresenter(
            'adm_requirements_import_preview_form',
            'requirements.import.preview.tpl',
            $this->pageUrl('import.php', array('mode' => 'import')),
            $this
        );
        if ($result['summary']['create'] > 0) {
            $form->addSubmitButton(
                'adm_button_import',
                $gL10n->get('PLG_REQUIREMENTS_IMPORT_CONFIRM', array($result['summary']['create'])),
                array('icon' => 'bi-upload')
            );
        }

        $this->assignResult($result, false);
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * Step 4: what the import actually did.
     *
     * @param array{summary:array<string,int>,rows:array<int,array<string,mixed>>} $result
     * @throws Exception|\Smarty\Exception
     */
    public function createResult(array $result): void
    {
        global $gL10n;

        $this->setHtmlID('adm_requirements_import_result');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_IMPORT'));
        $this->addBackToActivities();

        $this->assignResult($result, true);
        $this->addHtmlByTemplate('requirements.import.result.tpl');
    }

    /**
     * The rows of a preview or a result, in the shape the two templates read.
     *
     * @param array{summary:array<string,int>,rows:array<int,array<string,mixed>>} $result
     * @throws Exception|\Smarty\Exception
     */
    private function assignResult(array $result, bool $written): void
    {
        global $gL10n;

        $shown = self::rowsWorthShowing($result['rows'], self::PREVIEW_LIMIT);

        $rows = array();
        foreach ($shown as $row) {
            $credits = array();
            foreach ($row['credits'] as $credit) {
                $credits[] = SecurityUtils::encodeHTML(
                    $credit['requirement'] . ': ' . self::formatCredits($credit['claimed'])
                    . ' (' . $credit['period'] . ')'
                );
            }

            // Admidio's Smarty does not escape by default, and every value here came out of an
            // uploaded file - so it is encoded on the way in, as the other plugin pages do it.
            $rows[] = array(
                'row' => (int) $row['row'],
                'status' => (string) $row['status'],
                'statusText' => $gL10n->get(self::statusLabelKey((string) $row['status'])),
                'member' => SecurityUtils::encodeHTML((string) $row['member']),
                'title' => SecurityUtils::encodeHTML((string) $row['title']),
                'begin' => SecurityUtils::encodeHTML((string) $row['begin']),
                'credits' => $credits,
                'messages' => array_map(
                    static fn(string $message): string => SecurityUtils::encodeHTML($message),
                    $row['messages']
                ),
                'url' => isset($row['uuid'])
                    ? $this->pageUrl('index.php', array('mode' => 'edit', 'uuid' => $row['uuid']))
                    : ''
            );
        }

        $this->assignSmartyVariable('written', $written);
        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('truncated', count($shown) < count($result['rows'])
            ? $gL10n->get('PLG_REQUIREMENTS_IMPORT_TRUNCATED', array(count($shown), count($result['rows'])))
            : '');
        $this->assignSmartyVariable('summary', $gL10n->get(
            $written ? 'PLG_REQUIREMENTS_IMPORT_SUMMARY_DONE' : 'PLG_REQUIREMENTS_IMPORT_SUMMARY',
            array(
                $result['summary']['rows'],
                $result['summary']['create'],
                $result['summary']['skip'],
                $result['summary']['error']
            )
        ));
        $this->assignSmartyVariable('hasErrors', $result['summary']['error'] > 0);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('SYS_NO_ENTRIES'));
        $this->assignSmartyVariable('activitiesUrl', $this->pageUrl('index.php'));
        $this->assignSmartyVariable('restartUrl', $this->pageUrl('import.php'));
    }

    /**
     * Register one select box per field and return what the template needs to lay them out.
     *
     * @param array<string,string> $columns
     * @param array<string,int> $suggested
     * @param array<string,string> $fields
     * @return array<int,array{id:string,label:string}>
     * @throws Exception
     */
    private function addFields(
        FormPresenter $form,
        array $columns,
        array $suggested,
        array $fields
    ): array {
        $entries = array();
        foreach ($fields as $field => $label) {
            $form->addSelectBox('map_' . $field, $label, $columns, array(
                'showContextDependentFirstEntry' => false,
                'defaultValue' => isset($suggested[$field]) ? (string) $suggested[$field] : ''
            ));
            $entries[] = array('id' => 'map_' . $field, 'label' => $label);
        }

        return $entries;
    }

    /**
     * The columns as the operator sees them: the header when there is one, otherwise the position.
     *
     * @param array<string,mixed> $state
     * @return array<int,string>
     * @throws Exception
     */
    private function columnLabels(array $state): array
    {
        global $gL10n;

        $labels = array();
        $headers = ImportService::headers($state['rows']);
        $count = max(array_map('count', $state['rows'])) ?: count($headers);

        for ($index = 0; $index < $count; ++$index) {
            $header = $state['firstRowHeader'] ? ($headers[$index] ?? '') : '';
            $labels[$index] = $header === ''
                ? $gL10n->get('PLG_REQUIREMENTS_IMPORT_COLUMN_NUMBER', array($index + 1))
                : SecurityUtils::encodeHTML($header);
        }

        return $labels;
    }

    /**
     * The first few data rows, so the operator can see what they are mapping.
     *
     * @param array<string,mixed> $state
     * @return array<int,array<int,string>>
     */
    private function sampleRows(array $state): array
    {
        $rows = array();
        foreach (array_slice($state['rows'], $state['firstRowHeader'] ? 1 : 0, 3) as $row) {
            $cells = array();
            foreach ($row as $value) {
                $cells[] = SecurityUtils::encodeHTML(mb_substr(trim((string) $value), 0, 60));
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * The header names a file may carry to be mapped without any clicking, as one readable line.
     *
     * A member sees only the names that would do anything for them: the member and status columns
     * are passed over for anybody who may not assign them, and listing them here would be an
     * invitation to add a column that is then silently ignored.
     *
     * @throws Exception
     */
    private function columnHelp(): string
    {
        $names = array();
        if ($this->isAdmin()) {
            $names = array('user_login', 'user_uuid', 'first_name', 'last_name');
        }
        foreach (ImportService::ACTIVITY_FIELDS as $field) {
            if ($this->isAdmin() || !ImportService::isRestrictedField($field)) {
                $names[] = $field;
            }
        }

        return implode(', ', array_merge($names, array('credit_<SHORTNAME>', 'period_<SHORTNAME>')));
    }

    /**
     * @throws Exception
     */
    private function addBackToActivities(): void
    {
        global $gL10n;

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_activities',
            $gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'),
            $this->pageUrl('index.php'),
            'bi-clipboard-check'
        );
    }

    /**
     * Which rows the table renders when there are more of them than it will show.
     *
     * Cutting the list at the first N rows is the wrong cut: the rows that need somebody to act are
     * the ones that failed or were skipped, and in a file of a thousand rows those are exactly the
     * ones a cut at the top loses. Errors come first, then skips, then the rows that simply worked
     * - each group in file order, so a row is still found by its number.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function rowsWorthShowing(array $rows, int $limit): array
    {
        if (count($rows) <= $limit) {
            return $rows;
        }

        $byStatus = array('error' => array(), 'skip' => array(), 'other' => array());
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $byStatus[isset($byStatus[$status]) ? $status : 'other'][] = $row;
        }

        return array_slice(array_merge($byStatus['error'], $byStatus['skip'], $byStatus['other']), 0, $limit);
    }

    /**
     * The translation key a row status reads as, for the preview and the result table alike.
     */
    public static function statusLabelKey(string $status): string
    {
        return match ($status) {
            'create' => 'PLG_REQUIREMENTS_IMPORT_STATUS_CREATE',
            'imported' => 'PLG_REQUIREMENTS_IMPORT_STATUS_IMPORTED',
            'skip' => 'PLG_REQUIREMENTS_IMPORT_STATUS_SKIPPED',
            default => 'PLG_REQUIREMENTS_IMPORT_STATUS_ERROR'
        };
    }

    /**
     * The wizard state, or a refusal if the operator arrived at a later step directly.
     *
     * @return array{rows:array<int,array<int,mixed>>,firstRowHeader:bool,duplicates:string}
     * @throws Exception
     */
    public static function state(): array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state) || !isset($state['rows']) || $state['rows'] === array()) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        return $state;
    }
}
