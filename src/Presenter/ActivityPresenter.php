<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ProviderService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\RequirementsPlugin;

/**
 * Activity list (with the fulfilment overview widget on top) and activity edit form.
 */
class ActivityPresenter extends RequirementsPresenter
{
    /**
     * Requirement types that should have a credit input on the edit form: every active type plus
     * every type this activity already carries a credit for.
     *
     * @return array<int,array<string,mixed>>
     */
    private function creditableTypes(array $existingCredits): array
    {
        global $gDb;

        $types = array();
        foreach ((new RequirementTypeService($gDb))->findTypes() as $type) {
            if ((bool) $type['rqt_active'] || array_key_exists((int) $type['rqt_id'], $existingCredits)) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(
        string $query = '',
        string $requirementUuid = '',
        string $period = '',
        string $userUuid = ''
    ): void {
        global $gDb, $gL10n, $gCurrentUser, $gProfileFields;

        $this->setHtmlID('adm_requirements_activities');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'));

        $userId = (int) $gCurrentUser->getValue('usr_id');
        $isAdmin = $this->isAdmin();
        $fulfilment = new FulfilmentService($gDb);
        $applicableIds = $fulfilment->applicableRequirementTypeIds($userId);
        $allTypes = (new RequirementTypeService($gDb))->findTypes();

        // Requirement-type filter — default to the first applicable type, then any type.
        $selectedType = null;
        foreach ($allTypes as $t) {
            if ($requirementUuid !== '' && $t['rqt_uuid'] === $requirementUuid) {
                $selectedType = $t;
                break;
            }
        }
        if ($selectedType === null) {
            foreach ($allTypes as $t) {
                if (in_array((int) $t['rqt_id'], $applicableIds, true)) {
                    $selectedType = $t;
                    break;
                }
            }
            $selectedType ??= $allTypes[0] ?? null;
        }
        $selectedTypeId = $selectedType !== null ? (int) $selectedType['rqt_id'] : null;
        $selectedKind = 'date';
        if ($selectedType !== null) {
            $typeEntity = new RequirementType($gDb);
            $typeEntity->setArray($selectedType);
            $selectedKind = FulfilmentService::periodControlKind($typeEntity);
        }

        $typeOptions = array();
        foreach ($allTypes as $t) {
            $typeOptions[] = array(
                'uuid' => $t['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $t['rqt_name']),
                'selected' => $selectedType !== null && $t['rqt_uuid'] === $selectedType['rqt_uuid']
            );
        }
        $this->assignSmartyVariable('searchUrl', $this->plugin->getUrl('index.php'));
        $this->assignSmartyVariable('query', $query);
        $this->assignSmartyVariable('typeOptions', $typeOptions);
        $this->assignSmartyVariable('searchPlaceholder', $gL10n->get('PLG_REQUIREMENTS_SEARCH_ACTIVITIES'));

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_activity_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_ACTIVITY'))),
            $this->pageUrl('index.php', array('mode' => 'edit')),
            'bi-plus-circle-fill'
        );

        // A member may import their own activities; the import assigns them server-side (§12).
        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_import',
            $gL10n->get('PLG_REQUIREMENTS_IMPORT'),
            $this->pageUrl('import.php'),
            'bi-upload'
        );
        if ($isAdmin) {
            $this->addPageFunctionsMenuItem('menu_item_requirements_review',
                $gL10n->get('PLG_REQUIREMENTS_REVIEW'), $this->pageUrl('review.php'), 'bi-clipboard-data');
            $this->addPageFunctionsMenuItem('menu_item_requirements_providers',
                $gL10n->get('PLG_REQUIREMENTS_PROVIDERS'), $this->pageUrl('providers.php'), 'bi-shop');
            $this->addPageFunctionsMenuItem('menu_item_requirements_rules',
                $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPES'), $this->pageUrl('rules.php'), 'bi-list-check');
            $this->addHistoryMenuItem('activities', array('req_activities', 'req_activity_credits'));
        }

        // Administrators arrive here from a row of the review roster, which asks for the activities
        // of one member. A normal member never gets past the record check in findActivities(), so
        // the parameter is only resolved for an administrator in the first place.
        $filterUserId = null;
        $filterUserName = '';
        if ($userUuid !== '' && $isAdmin) {
            $filterUser = new User($gDb, $gProfileFields);
            $filterUser->readDataByUuid($userUuid);
            $candidateId = (int) $filterUser->getValue('usr_id');
            // Not isMemberOfOrganization() on its own: the review links to members who have left the
            // role, and they still have the activities it sent us here to look at. isKnownMember()
            // is that population, and it keeps a name from another organization off the page.
            if ($candidateId > 0 && (new ActivityService($gDb))->isKnownMember($candidateId)) {
                $filterUserId = $candidateId;
                $filterUserName = $filterUser->readableName();
            }
        }
        $this->assignSmartyVariable('userUuid', $filterUserId === null ? '' : $userUuid);
        $this->assignSmartyVariable('userLabel', SecurityUtils::encodeHTML($filterUserName));

        // Fulfilment overview bars — running + N previous periods, applicable requirements only.
        $previousPeriods = (int) (RequirementsPlugin::setting(RequirementsPlugin::SETTING_OVERVIEW_PREVIOUS_YEARS) ?? 4);
        $overview = $fulfilment->coverageHistoryForUser($userId, $previousPeriods);
        // Each bar links to the activities that make it up, which is the answer to "where does this
        // number come from" - the question a bar always raises and never answers on its own.
        foreach ($overview as $i => $requirement) {
            $uuid = '';
            foreach ($allTypes as $t) {
                if ((int) $t['rqt_id'] === (int) $requirement['typeId']) {
                    $uuid = (string) $t['rqt_uuid'];
                    break;
                }
            }
            foreach ($requirement['periods'] as $j => $periodRow) {
                $overview[$i]['periods'][$j]['url'] = $uuid === '' || $periodRow['periodReference'] === null
                    ? ''
                    : $this->pageUrl('index.php', array(
                        'mode' => 'list',
                        'requirement' => $uuid,
                        'period' => $periodRow['periodReference']
                    ));
            }
        }
        $this->assignSmartyVariable('overview', $overview);
        // Say so rather than silently hiding the card when nothing applies to this member.
        $this->assignSmartyVariable(
            'overviewHint',
            $overview === array() && $allTypes !== array() ? $gL10n->get('PLG_REQUIREMENTS_OVERVIEW_NONE') : ''
        );

        // Table: title | credited period + credits for the selected requirement | provider
        $headers = array(
            $gL10n->get('SYS_TITLE'),
            $gL10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD'),
            $gL10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED')
        );
        if ($isAdmin) {
            $headers[] = $gL10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED');
        }
        $headers[] = $gL10n->get('PLG_REQUIREMENTS_PROVIDER');
        $headers[] = '';

        // A period narrows the list to the activities that made up one bar of the overview. It only
        // means anything together with a requirement, and it is normalised so that a link built from
        // any day of the period finds the same contributions.
        $periodFilter = null;
        $periodLabel = '';
        if ($period !== '' && $selectedType !== null) {
            $typeEntity = new RequirementType($gDb);
            $typeEntity->setArray($selectedType);
            try {
                $periodFilter = FulfilmentService::normalizePeriodReference($typeEntity, $period);
                $parts = FulfilmentService::periodControlValue($selectedKind, $periodFilter);
                $periodLabel = $parts['quarter'] !== ''
                    ? $parts['value'] . ' Q' . $parts['quarter']
                    : $parts['value'];
            } catch (Exception) {
                $periodFilter = null;
            }
        }
        $this->assignSmartyVariable('periodLabel', $periodLabel);
        // One way out of both narrowings: a member filter and a period filter are two halves of the
        // same "I followed a link to get here", and clearing one and not the other only puzzles.
        $this->assignSmartyVariable('clearFilterUrl', $this->pageUrl('index.php', array(
            'mode' => 'list',
            'query' => $query,
            'requirement' => $requirementUuid
        )));

        $rows = array();
        foreach ((new ActivityService($gDb))->findActivities($filterUserId, $query, $selectedTypeId, $periodFilter) as $activity) {
            $rowId = 'adm_requirements_activity_' . $activity['rqa_uuid'];
            $editUrl = $this->pageUrl('index.php', array('mode' => 'edit', 'uuid' => $activity['rqa_uuid']));

            $entity = new Activity($gDb);
            $entity->setArray($activity);
            $editable = $entity->isEditable();

            $periodText = '';
            if ($activity['rac_period_reference'] !== null) {
                $parts = FulfilmentService::periodControlValue($selectedKind, (string) $activity['rac_period_reference']);
                $periodText = $parts['quarter'] !== '' ? $parts['value'] . ' Q' . $parts['quarter'] : $parts['value'];
            }
            $provider = (string) ($activity['rqa_provider_short_name'] ?? '');
            if ($provider === '') {
                $provider = (string) ($activity['rqa_provider_name'] ?? '');
            }

            $data = array(
                '<a href="' . $editUrl . '">' . SecurityUtils::encodeHTML((string) $activity['rqa_title']) . '</a>',
                SecurityUtils::encodeHTML($periodText),
                self::formatCredits($activity['rac_credits_claimed'])
            );
            if ($isAdmin) {
                $data[] = self::formatCredits($activity['rac_credits_accepted']);
            }
            $data[] = SecurityUtils::encodeHTML($provider);

            $actions = array();
            $actions[] = array('url' => $editUrl, 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('SYS_EDIT'));
            // A draft only exists under the until_submitted policy, where submitting is what hands
            // the activity over for review - and the only way out of draft for its owner.
            if ($editable && (string) $activity['rqa_status'] === 'draft') {
                $actions[] = $this->confirmedPostAction(
                    $rowId,
                    $this->pageUrl('index.php', array('mode' => 'submit', 'uuid' => $activity['rqa_uuid'])),
                    $gL10n->get('PLG_REQUIREMENTS_SUBMIT_CONFIRM', array($activity['rqa_title'])),
                    'bi bi-send',
                    $gL10n->get('PLG_REQUIREMENTS_SUBMIT'),
                    'admReqReloadPage'
                );
            }
            if ($editable) {
                $actions[] = $this->deleteAction(
                    $rowId,
                    $this->pageUrl('index.php', array('mode' => 'delete', 'uuid' => $activity['rqa_uuid'])),
                    $gL10n->get('SYS_WANT_DELETE_ENTRY', array($activity['rqa_title']))
                );
            }

            $rows[] = array(
                'id' => $rowId,
                'uuid' => $activity['rqa_uuid'],
                'data' => $data,
                'actions' => $actions,
                'statusIcon' => $this->statusIcon($activity)
            );
        }

        // Submitting changes a row rather than removing it, so the list is redrawn instead of
        // letting callUrlHideElement() fade the row out.
        $this->addJavascript('function admReqReloadPage() { window.location.reload(); }');

        $this->assignSmartyVariable('headers', $headers);
        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('SYS_NO_ENTRIES'));
        $this->addHtmlByTemplate('requirements.activities.list.tpl');
    }

    /**
     * The activity status as a coloured dot for the actions column: draft is amber, submitted is
     * green and withdrawn is red. A review verdict, where one exists, is added to the tooltip.
     */
    private function statusIcon(array $activity): string
    {
        global $gL10n;

        [$icon, $colour, $textKey] = match ((string) ($activity['rqa_status'] ?? '')) {
            'draft' => array('bi-pencil-fill', 'text-warning', 'PLG_REQUIREMENTS_STATUS_DRAFT'),
            'withdrawn' => array('bi-x-circle-fill', 'text-danger', 'PLG_REQUIREMENTS_STATUS_WITHDRAWN'),
            default => array('bi-check-circle-fill', 'text-success', 'PLG_REQUIREMENTS_STATUS_SUBMITTED')
        };

        $tooltip = $gL10n->get($textKey);
        $reviewKey = match ((string) ($activity['rqa_review_status'] ?? '')) {
            'open' => 'PLG_REQUIREMENTS_REVIEW_OPEN',
            'accepted' => 'PLG_REQUIREMENTS_REVIEW_ACCEPTED',
            'partially_accepted' => 'PLG_REQUIREMENTS_REVIEW_PARTIALLY_ACCEPTED',
            'not_accepted' => 'PLG_REQUIREMENTS_REVIEW_NOT_ACCEPTED',
            'clarification' => 'PLG_REQUIREMENTS_REVIEW_CLARIFICATION',
            default => ''
        };
        if ($reviewKey !== '') {
            $tooltip .= ' – ' . $gL10n->get($reviewKey);
        }

        return '<i class="bi ' . $icon . ' ' . $colour . '" data-bs-toggle="tooltip" title="'
            . SecurityUtils::encodeHTML($tooltip) . '"></i> ';
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(string $activityUUID): void
    {
        global $gDb, $gL10n, $gCurrentSession, $gCurrentUser;

        $activity = new Activity($gDb);
        $existingCredits = array();
        // Seeing an activity and being allowed to change it are two questions. Under the
        // until_submitted policy a member's own submitted activity is visible but not editable, and
        // refusing the page outright would leave the list linking somewhere they cannot go - so the
        // page opens read-only instead.
        $readOnly = false;
        if ($activityUUID !== '') {
            $activity->readDataByUuid($activityUUID);
            if ((int) $activity->getValue('rqa_id') === 0 || !$activity->isVisible()) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $readOnly = !$activity->isEditable();
            $existingCredits = (new ActivityService($gDb))->creditsForActivity((int) $activity->getValue('rqa_id'));
            $this->setHeadline($readOnly
                ? $gL10n->get('PLG_REQUIREMENTS_ACTIVITY_VIEW')
                : $gL10n->get('SYS_EDIT_VAR', array($gL10n->get('PLG_REQUIREMENTS_ACTIVITY'))));
        } else {
            $this->setHeadline($gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_ACTIVITY'))));
        }
        $this->setHtmlID('adm_requirements_activity_edit');

        if (!$readOnly) {
            $returnUrl = $this->pageUrl('index.php', array('mode' => 'edit', 'uuid' => $activityUUID));
            $this->addPageFunctionsMenuItem(
                'menu_item_requirements_provider_add',
                $gL10n->get('PLG_REQUIREMENTS_PROVIDER_CREATE'),
                $this->pageUrl('providers.php', array('mode' => 'edit', 'return' => $returnUrl)),
                'bi-plus-circle-fill'
            );
        }

        $isAdmin = $this->isAdmin();
        // A read-only field still submits its value, which is what keeps the form payload
        // complete; a select cannot be made read-only in a browser, so it is disabled instead.
        $textProperty = $readOnly ? FormPresenter::FIELD_READONLY : FormPresenter::FIELD_DEFAULT;
        $requiredProperty = $readOnly ? FormPresenter::FIELD_READONLY : FormPresenter::FIELD_REQUIRED;
        $choiceProperty = $readOnly ? FormPresenter::FIELD_DISABLED : FormPresenter::FIELD_DEFAULT;
        $filesEnabled = ActivityFileService::isEnabled();
        $form = new FormPresenter(
            'adm_requirements_activity_edit_form',
            'requirements.activity.edit.tpl',
            $this->pageUrl('index.php', array('mode' => 'save', 'uuid' => $activityUUID)),
            $this,
            // Without this the form is urlencoded and $_FILES stays empty, so the upload field
            // would render and silently do nothing.
            array('enableFileUpload' => $filesEnabled && !$readOnly)
        );

        $form->addInput('rqa_title', $gL10n->get('SYS_TITLE'), (string) $activity->getValue('rqa_title'),
            array('maxLength' => 255, 'property' => $requiredProperty));

        $providers = array('' => '');
        foreach ((new ProviderService($gDb))->findVisible() as $provider) {
            $providers[(int) $provider['rqp_id']] = $provider['rqp_name']
                . ($provider['rqp_qualified'] ? ' ★' : '');
        }
        $providers['__new__'] = '＋ ' . $gL10n->get('PLG_REQUIREMENTS_PROVIDER_CREATE_NEW');
        $form->addSelectBox('rqa_provider_id', $gL10n->get('PLG_REQUIREMENTS_PROVIDER'), $providers,
            array('defaultValue' => (int) $activity->getValue('rqa_provider_id'),
                'showContextDependentFirstEntry' => false, 'property' => $choiceProperty,
                'helpTextId' => 'PLG_REQUIREMENTS_PROVIDER_CREATE_NEW_HELP'));

        $beginTime = (string) $activity->getValue('rqa_begin_time', 'database');
        $endTime = (string) $activity->getValue('rqa_end_time', 'database');
        $form->addCheckbox('rqa_full_day', $gL10n->get('PLG_REQUIREMENTS_FULL_DAY'),
            $beginTime === '' && $endTime === '',
            array('helpTextId' => 'PLG_REQUIREMENTS_FULL_DAY_DESC', 'property' => $choiceProperty));
        $form->addInput('rqa_begin', $gL10n->get('SYS_START'),
            self::dateTimeValue((string) $activity->getValue('rqa_begin_date', 'database'), $beginTime),
            array('type' => 'datetime', 'property' => $requiredProperty));
        $form->addInput('rqa_end', $gL10n->get('SYS_END'),
            self::dateTimeValue((string) $activity->getValue('rqa_end_date', 'database'), $endTime),
            array('type' => 'datetime', 'property' => $textProperty));

        // Toggle the two time inputs with the full-day checkbox; keep begin <= end.
        $this->addJavascript('
            function admReqToggleFullDay() {
                var t = $("#rqa_begin_time, #rqa_end_time");
                if ($("#rqa_full_day").is(":checked")) {
                    t.val("00:00").closest("div").hide();
                } else {
                    t.closest("div").show();
                }
            }
            admReqToggleFullDay();
            $("#rqa_full_day").on("change", admReqToggleFullDay);
            $("#rqa_begin").on("change", function () {
                if ($("#rqa_end").val() === "" || $("#rqa_end").val() < $("#rqa_begin").val()) {
                    $("#rqa_end").val($("#rqa_begin").val());
                }
            });
        ', true);
        $form->addInput('rqa_attendance', $gL10n->get('PLG_REQUIREMENTS_ATTENDANCE'),
            (string) $activity->getValue('rqa_attendance'),
            array('type' => 'number', 'minNumber' => 0, 'step' => 0.01, 'property' => $textProperty));
        $form->addInput('rqa_certificate_date', $gL10n->get('PLG_REQUIREMENTS_CERTIFICATE_DATE'),
            (string) $activity->getValue('rqa_certificate_date', 'database'),
            array('type' => 'date', 'property' => $textProperty,
                'helpTextId' => 'PLG_REQUIREMENTS_CERTIFICATE_DATE_HELP'));
        $form->addInput('rqa_url', $gL10n->get('SYS_WEBSITE'), (string) $activity->getValue('rqa_url'),
            array('maxLength' => 2000, 'type' => 'url', 'property' => $textProperty));
        $form->addMultilineTextInput('rqa_location', $gL10n->get('PLG_REQUIREMENTS_LOCATION'),
            (string) $activity->getValue('rqa_location'), 2, array('property' => $textProperty));
        $form->addMultilineTextInput('rqa_description', $gL10n->get('SYS_DESCRIPTION'),
            (string) $activity->getValue('rqa_description'), 4, array('property' => $textProperty));
        $form->addMultilineTextInput('rqa_comments', $gL10n->get('SYS_COMMENT'),
            (string) $activity->getValue('rqa_comments'), 3, array('property' => $textProperty));

        // Credit inputs, one compact row per creditable requirement type. Rows for requirements that
        // currently apply to the member (or that already carry a credit) are shown; the rest are
        // hidden and offered through the "add another requirement" selector. Every field stays a
        // registered form element so validate() accepts the payload; the template does the layout.
        $targetUserId = $activityUUID !== ''
            ? (int) $activity->getValue('rqa_user_id')
            : (int) $gCurrentUser->getValue('usr_id');
        $applicable = array_flip((new FulfilmentService($gDb))->applicableRequirementTypeIds($targetUserId));

        $defaultPeriodDate = (string) $activity->getValue('rqa_begin_date', 'database');
        $maxYear = (string) ((int) date('Y') + 1);

        $creditFields = array();
        $optionalCreditTypes = array();
        foreach ($this->creditableTypes($existingCredits) as $type) {
            $typeId = (int) $type['rqt_id'];
            $current = $existingCredits[$typeId] ?? null;
            $shown = $current !== null || isset($applicable[$typeId]);

            $typeEntity = new RequirementType($gDb);
            $typeEntity->setArray($type);
            $kind = FulfilmentService::periodControlKind($typeEntity);

            $claimedField = 'credit_' . $typeId;
            $periodField = 'period_' . $typeId;
            $quarterField = 'periodq_' . $typeId;
            $acceptedField = 'accepted_' . $typeId;

            $form->addInput($claimedField, $gL10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED'),
                (string) ($current['rac_credits_claimed'] ?? ''),
                array('type' => 'number', 'minNumber' => 0, 'step' => 0.01));
            // Registered so validate() accepts the payload; rendered by the template as a native
            // month/week picker, a year field or a date field depending on $kind.
            $form->addInput($periodField, $gL10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD'),
                (string) ($current['rac_period_reference'] ?? ''));
            if ($kind === 'quarter') {
                $form->addSelectBox($quarterField, $gL10n->get('PLG_REQUIREMENTS_PERIOD_QUARTER'),
                    array('1' => 'Q1', '2' => 'Q2', '3' => 'Q3', '4' => 'Q4'),
                    array('showContextDependentFirstEntry' => false));
            }
            if ($isAdmin) {
                $form->addInput($acceptedField, $gL10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED'),
                    $current === null || $current['rac_credits_accepted'] === null
                        ? '' : (string) $current['rac_credits_accepted'],
                    array('type' => 'number', 'minNumber' => 0, 'step' => 0.01));
            }

            $ref = (string) ($current['rac_period_reference'] ?? $defaultPeriodDate);
            $parts = FulfilmentService::periodControlValue($kind, $ref);

            $creditFields[] = array(
                'typeId' => $typeId,
                'name' => SecurityUtils::encodeHTML((string) $type['rqt_name']),
                'shown' => $shown,
                'kind' => $kind,
                'maxYear' => $maxYear,
                'periodId' => $periodField,
                'quarterId' => $quarterField,
                'periodValue' => SecurityUtils::encodeHTML($parts['value']),
                'quarterValue' => $parts['quarter'],
                'claimedId' => $claimedField,
                'claimedValue' => SecurityUtils::encodeHTML((string) ($current['rac_credits_claimed'] ?? '')),
                'acceptedId' => $isAdmin ? $acceptedField : '',
                'acceptedValue' => SecurityUtils::encodeHTML(
                    $current === null || $current['rac_credits_accepted'] === null
                        ? '' : (string) $current['rac_credits_accepted']
                )
            );
            if (!$shown) {
                $optionalCreditTypes[$typeId] = SecurityUtils::encodeHTML((string) $type['rqt_name']);
            }
        }
        $this->assignSmartyVariable('optionalCreditTypes', $optionalCreditTypes);

        // Prefill an empty credited period from the begin date, and reveal a hidden requirement row
        // when it is picked from the selector.
        $this->addJavascript('
            function admReqIsoWeek(d) {
                var t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
                t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7));
                var y = t.getUTCFullYear();
                var w = Math.ceil((((t - Date.UTC(y, 0, 1)) / 86400000) + 1) / 7);
                return y + "-W" + ("0" + w).slice(-2);
            }
            $("#rqa_begin").on("change.reqcredit", function () {
                var v = $(this).val();
                if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { return; }
                var jd = new Date(v + "T00:00:00");
                $(".adm-req-period[data-kind]").each(function () {
                    if ($(this).val() !== "") { return; }
                    var k = $(this).attr("data-kind");
                    if (k === "month") { $(this).val(v.substring(0, 7)); }
                    else if (k === "week") { $(this).val(admReqIsoWeek(jd)); }
                    else if (k === "year") { $(this).val(v.substring(0, 4)); }
                    else if (k === "quarter") {
                        $(this).val(v.substring(0, 4));
                        $(this).closest(".input-group").find(".adm-req-period-quarter")
                            .val(String(Math.floor(jd.getMonth() / 3) + 1));
                    }
                    else { $(this).val(v); }
                });
            });
            $("#adm_req_add_credit").on("change", function () {
                var id = $(this).val();
                if (id === "") { return; }
                $("#adm_req_credit_" + id).prop("hidden", false);
                $(this).find("option[value=\'" + id + "\']").remove();
                $(this).val("");
                if ($(this).find("option").length <= 1) { $("#adm_req_add_credit_wrap").hide(); }
            });
        ', true);

        if ($isAdmin) {
            $form->addSelectBox('rqa_status', $gL10n->get('PLG_REQUIREMENTS_STATUS'), array(
                'draft' => $gL10n->get('PLG_REQUIREMENTS_STATUS_DRAFT'),
                'submitted' => $gL10n->get('PLG_REQUIREMENTS_STATUS_SUBMITTED'),
                'withdrawn' => $gL10n->get('PLG_REQUIREMENTS_STATUS_WITHDRAWN')
            ), array(
                'defaultValue' => (string) $activity->getValue('rqa_status') ?: 'submitted',
                'showContextDependentFirstEntry' => false,
                'property' => $choiceProperty
            ));
            $form->addSelectBox('rqa_review_status', $gL10n->get('PLG_REQUIREMENTS_REVIEW_STATUS'), array(
                '' => '',
                'open' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_OPEN'),
                'accepted' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_ACCEPTED'),
                'partially_accepted' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_PARTIALLY_ACCEPTED'),
                'not_accepted' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_NOT_ACCEPTED'),
                'clarification' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_CLARIFICATION')
            ), array(
                'defaultValue' => (string) $activity->getValue('rqa_review_status'),
                'property' => $choiceProperty
            ));
            $form->addMultilineTextInput('rqa_review_comments', $gL10n->get('PLG_REQUIREMENTS_REVIEW_COMMENTS'),
                (string) $activity->getValue('rqa_review_comments'), 3, array('property' => $textProperty));
        }

        if (!$readOnly) {
            $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));
        }

        $this->assignCertificates($form, $activity, $filesEnabled, $readOnly);

        $this->assignSmartyVariable('readOnly', $readOnly);
        $this->assignSmartyVariable('creditFields', $creditFields);
        $this->assignSmartyVariable('showReview', $isAdmin);
        $this->assignSmartyVariable('userCreatedName', $activity->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $activity->getValue('rqa_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $activity->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $activity->getValue('rqa_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * Combine a stored date and time into the "Y-m-d H:i" value a datetime form field expects.
     */
    /**
     * The "certificates" card: what is already attached, and the field that attaches more.
     *
     * A new activity has no id yet, so it has no files to list - but its upload field is still
     * offered, because `index.php?mode=save` creates the activity first and attaches afterwards,
     * inside the same request. Asking the member to save and come back would be a worse answer to
     * a form they have already filled in.
     *
     * @throws Exception
     */
    private function assignCertificates(
        FormPresenter $form,
        Activity $activity,
        bool $filesEnabled,
        bool $readOnly
    ): void {
        global $gDb, $gL10n;

        $this->assignSmartyVariable('showFiles', $filesEnabled);
        $this->assignSmartyVariable('files', array());
        $this->assignSmartyVariable('filesUploadable', false);
        if (!$filesEnabled) {
            return;
        }

        $activityId = (int) $activity->getValue('rqa_id');
        $files = array();
        if ($activityId > 0) {
            foreach ((new ActivityFileService($gDb))->listForActivity($activityId) as $file) {
                $rowId = 'adm_requirements_file_' . $file['raf_uuid'];
                $files[] = array(
                    'id' => $rowId,
                    'name' => SecurityUtils::encodeHTML((string) $file['raf_name']),
                    'size' => FileSystemUtils::getHumanReadableBytes((int) $file['raf_size']),
                    'url' => $this->pageUrl('file.php', array('uuid' => $file['raf_uuid'])),
                    'deleteAction' => $readOnly ? null : $this->deleteAction(
                        $rowId,
                        $this->pageUrl('index.php', array(
                            'mode' => 'file-delete',
                            'uuid' => (string) $activity->getValue('rqa_uuid'),
                            'file' => $file['raf_uuid']
                        )),
                        $gL10n->get('SYS_WANT_DELETE_ENTRY', array($file['raf_name']))
                    )
                );
            }
        }

        if (!$readOnly) {
            // The types this organization allows, not every type the plugin could store, so the
            // file picker offers what the upload would actually accept.
            $allowed = ActivityFileService::allowedMimeTypes();
            $form->addFileUpload('adm_requirements_files', $gL10n->get('PLG_REQUIREMENTS_FILES_ADD'), array(
                'enableMultiUploads' => true,
                'multiUploadLabel' => $gL10n->get('PLG_REQUIREMENTS_FILES_ADD_ANOTHER'),
                'allowedMimeTypes' => array_keys($allowed)
            ));
            $form->addDescription('adm_requirements_files_help', $gL10n->get(
                'PLG_REQUIREMENTS_FILES_ADD_HELP',
                array(
                    implode(', ', $allowed),
                    (int) RequirementsPlugin::setting(ActivityFileService::SETTING_MAX_SIZE),
                    (int) RequirementsPlugin::setting(ActivityFileService::SETTING_MAX_COUNT)
                )
            ));
        }

        $this->assignSmartyVariable('files', $files);
        $this->assignSmartyVariable('filesUploadable', !$readOnly);
        $this->assignSmartyVariable('filesNone', $gL10n->get('PLG_REQUIREMENTS_FILES_NONE'));
    }

    private static function dateTimeValue(string $date, string $time): string
    {
        if ($date === '') {
            return '';
        }
        return $date . ' ' . ($time === '' ? '00:00' : substr($time, 0, 5));
    }
}
