<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityExportService;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ProviderService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Activity list (with the fulfilment overview widget on top) and activity edit form.
 */
class ActivityPresenter extends RequirementsPresenter
{
    /**
     * Give every bar of the fulfilment overview the link to the activities that make it up, which is
     * the answer to "where does this number come from" - the question a bar always raises and never
     * answers on its own. Shared by the activity list and the widget of the overview page.
     *
     * @param array<int,array<string,mixed>> $overview as FulfilmentService::coverageHistoryForUser() returns it
     * @param array<int,array<string,mixed>> $types the requirement types the member sees
     * @return array<int,array<string,mixed>>
     */
    public static function linkOverview(array $overview, array $types, Plugin $plugin): array
    {
        $uuids = array_column($types, 'rqt_uuid', 'rqt_id');
        foreach ($overview as $i => $requirement) {
            $uuid = (string) ($uuids[$requirement['typeId']] ?? '');
            foreach ($requirement['periods'] as $j => $periodRow) {
                $overview[$i]['periods'][$j]['url'] = $uuid === '' || $periodRow['periodReference'] === null
                    ? ''
                    : SecurityUtils::encodeUrl($plugin->getUrl('index.php'), array_filter(array(
                        'mode' => 'list',
                        'requirement' => $uuid,
                        'period' => $periodRow['periodReference'],
                        'period_to' => $periodRow['periodTo'] ?? ''
                    ), static fn($value): bool => $value !== ''));
            }
        }
        return $overview;
    }

    private static function currentUserId(): int
    {
        return (int) $GLOBALS['gCurrentUser']->getValue('usr_id');
    }

    /**
     * Requirement types that should have a credit input on the edit form: every active type plus
     * every type this activity already carries a credit for. The member's own requirements are
     * those of the member the activity belongs to, and somebody else editing it - an administrator -
     * sees only those of them the activity already carries a credit for.
     *
     * @return array<int,array<string,mixed>>
     */
    private function creditableTypes(array $existingCredits, int $ownerId): array
    {
        global $gDb;

        $types = array();
        foreach ((new RequirementTypeService($gDb))->findTypes('', $ownerId) as $type) {
            $credited = array_key_exists((int) $type['rqt_id'], $existingCredits);
            $foreign = (int) $type['rqt_usr_id'] > 0 && $ownerId !== self::currentUserId();
            if ($credited || ((bool) $type['rqt_active'] && !$foreign)) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * The columns of the activity table in display order, as key, label, whether a member sees it
     * before choosing their own columns, and whether it sorts. The key is the column's name for
     * DataTables and the field of a row in listData().
     *
     * @return array<int,array{key:string,label:string,visible:bool,sortable:bool}>
     */
    private function listColumns(): array
    {
        global $gDb, $gL10n;

        $columns = array(
            array('key' => 'period', 'label' => $gL10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD'), 'visible' => true),
            array('key' => 'date', 'label' => $gL10n->get('SYS_DATE'), 'visible' => true),
            array('key' => 'title', 'label' => $gL10n->get('SYS_TITLE'), 'visible' => true),
            // With a single requirement type the column would say the same thing on every row.
            array('key' => 'requirement', 'label' => $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'),
                'visible' => count((new RequirementTypeService($gDb))->findTypes('', self::currentUserId())) > 1),
            array('key' => 'credits', 'label' => $gL10n->get('PLG_REQUIREMENTS_CREDITS'), 'visible' => true),
            array('key' => 'provider', 'label' => $gL10n->get('PLG_REQUIREMENTS_PROVIDER'), 'visible' => true)
        );
        if ($this->isAdmin()) {
            $columns[] = array('key' => 'member', 'label' => $gL10n->get('SYS_MEMBER'), 'visible' => true);
        }
        $columns[] = array('key' => 'attendance', 'label' => $gL10n->get('PLG_REQUIREMENTS_ATTENDANCE'), 'visible' => false);
        $columns[] = array('key' => 'certificate', 'label' => $gL10n->get('PLG_REQUIREMENTS_CERTIFICATE_DATE'), 'visible' => false);
        if (ActivityFileService::isEnabled()) {
            $columns[] = array('key' => 'files', 'label' => $gL10n->get('PLG_REQUIREMENTS_FILES'), 'visible' => true);
        }

        foreach ($columns as $i => $column) {
            $columns[$i]['sortable'] = $column['key'] !== 'files';
        }
        return $columns;
    }

    /**
     * The narrowing a link to the list may carry, resolved: the requirement, the member and the
     * credited period. Shared by the page, which prints what it is narrowed to, and by listData(),
     * which applies it - so the two cannot disagree about an unknown UUID.
     *
     * @return array{type:?array,userId:?int,userName:string,periodRange:?array,periodLabel:string}
     * @throws Exception
     */
    private function resolveListFilter(
        string $requirementUuid,
        string $period,
        string $userUuid,
        string $periodTo = ''
    ): array {
        global $gDb, $gProfileFields, $gSettingsManager;

        $selectedType = null;
        if ($requirementUuid !== '') {
            foreach ((new RequirementTypeService($gDb))->findTypes('', self::currentUserId()) as $t) {
                if ($t['rqt_uuid'] === $requirementUuid) {
                    $selectedType = $t;
                    break;
                }
            }
        }

        // Administrators arrive here from a row of the review roster, which asks for the activities
        // of one member. A normal member never gets past the record check in findActivityRows(), so
        // the parameter is only resolved for an administrator in the first place.
        $userId = null;
        $userName = '';
        if ($userUuid !== '' && $this->isAdmin()) {
            $filterUser = new User($gDb, $gProfileFields);
            $filterUser->readDataByUuid($userUuid);
            $candidateId = (int) $filterUser->getValue('usr_id');
            // Not isMemberOfOrganization() on its own: the review links to members who have left the
            // role, and they still have the activities it sent us here to look at. isKnownMember()
            // is that population, and it keeps a name from another organization off the page.
            if ($candidateId > 0 && (new ActivityService($gDb))->isKnownMember($candidateId)) {
                $userId = $candidateId;
                $userName = $filterUser->readableName();
            }
        }

        // A period narrows the list to the activities that made up one bar of the overview. It only
        // means anything together with a requirement, and it is normalised so that a link built from
        // any day of the period finds the same contributions.
        $periodRange = null;
        $periodLabel = '';
        if ($period !== '' && $selectedType !== null) {
            $typeEntity = new RequirementType($gDb);
            $typeEntity->setArray($selectedType);
            try {
                // A recurring requirement has no calendar period: its bar is a renewal cycle, and the
                // link names the contributions of that cycle directly. An open end is the cycle under
                // way, which takes every contribution from its start on.
                if ((string) $typeEntity->getValue('rqt_period_anchor') === 'last_fulfilment') {
                    $format = $gSettingsManager->getString('system_date');
                    $from = new \DateTimeImmutable($period);
                    $periodRange = array($period, $periodTo === '' ? '9999-12-31' : $periodTo);
                    $periodLabel = $from->format($format) . ' – '
                        . ($periodTo === '' ? '' : (new \DateTimeImmutable($periodTo))->format($format));
                    return array(
                        'type' => $selectedType,
                        'userId' => $userId,
                        'userName' => $userName,
                        'periodRange' => $periodRange,
                        'periodLabel' => $periodLabel
                    );
                }
                // The link carries the unit the period opens in; the period itself may be a rolling
                // window of several units, so the filter is the whole window and the label says so.
                $opens = FulfilmentService::normalizePeriodReference($typeEntity, $period);
                [$windowStart, $windowEnd, $periodLabel] = FulfilmentService::periodFor(
                    $typeEntity,
                    new \DateTimeImmutable($opens)
                );
                $periodRange = array($windowStart->format('Y-m-d'), $windowEnd->format('Y-m-d'));
            } catch (Exception) {
                $periodRange = null;
                $periodLabel = '';
            }
        }

        return array(
            'type' => $selectedType,
            'userId' => $userId,
            'userName' => $userName,
            'periodRange' => $periodRange,
            'periodLabel' => $periodLabel
        );
    }

    /**
     * The activity page: the fulfilment overview, the filter bar and an empty table that DataTables
     * fills page by page from `index.php?mode=data`, which is listData().
     *
     * @throws Exception|\Smarty\Exception
     */
    public function createList(
        string $query = '',
        string $requirementUuid = '',
        string $period = '',
        string $userUuid = '',
        string $periodTo = '',
        int $overviewOffset = 0
    ): void {
        global $gDb, $gL10n, $gCurrentUser;

        $this->setHtmlID('adm_requirements_activities');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'));

        $userId = (int) $gCurrentUser->getValue('usr_id');
        $isAdmin = $this->isAdmin();
        $fulfilment = new FulfilmentService($gDb);
        $allTypes = (new RequirementTypeService($gDb))->findTypes('', $userId);
        $filter = $this->resolveListFilter($requirementUuid, $period, $userUuid, $periodTo);

        $typeOptions = array();
        foreach ($allTypes as $t) {
            $typeOptions[] = array(
                'uuid' => $t['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $t['rqt_name']),
                'selected' => $filter['type'] !== null && $t['rqt_uuid'] === $filter['type']['rqt_uuid']
            );
        }
        $providerOptions = array();
        foreach ((new ProviderService($gDb))->findVisible() as $provider) {
            $providerOptions[] = array(
                'uuid' => $provider['rqp_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $provider['rqp_name'])
            );
        }
        $this->assignSmartyVariable('query', $query);
        $this->assignSmartyVariable('typeOptions', $typeOptions);
        $this->assignSmartyVariable('providerOptions', $providerOptions);
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
        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_rules',
            $gL10n->get('PLG_REQUIREMENTS_NAME'),
            $this->pageUrl('rules.php'),
            'bi-list-check'
        );
        if ($isAdmin) {
            $this->addPageFunctionsMenuItem('menu_item_requirements_review',
                $gL10n->get('PLG_REQUIREMENTS_REVIEW'), $this->pageUrl('review.php'), 'bi-clipboard-data');
            $this->addPageFunctionsMenuItem('menu_item_requirements_providers',
                $gL10n->get('PLG_REQUIREMENTS_PROVIDERS'), $this->pageUrl('providers.php'), 'bi-shop');
            $this->addHistoryMenuItem('activities', array('req_activities', 'req_activity_credits'));
        }

        $effectiveUserUuid = $filter['userId'] === null ? '' : $userUuid;
        $this->assignSmartyVariable('userLabel', SecurityUtils::encodeHTML($filter['userName']));

        // Fulfilment overview bars — running + N previous periods, applicable requirements only.
        $previousPeriods = (int) (RequirementsPlugin::setting(RequirementsPlugin::SETTING_OVERVIEW_PREVIOUS_YEARS) ?? 4);
        $overviewOffset = max(0, $overviewOffset);
        $overview = $fulfilment->coverageHistoryForUser($userId, $previousPeriods, $overviewOffset);
        $hasEarlier = in_array(true, array_column($overview, 'hasEarlier'), true);
        $overview = self::linkOverview($overview, $allTypes, $this->plugin);
        $this->assignSmartyVariable('overview', $overview);
        // Paging through the overview keeps whatever narrows the list below it.
        $overviewUrl = fn(int $offset): string => $this->pageUrl('index.php', array_filter(array(
            'mode' => 'list',
            'query' => $query,
            'requirement' => $requirementUuid,
            'period' => $period,
            'period_to' => $periodTo,
            'user' => $userUuid,
            'overview' => $offset
        ), static fn($value): bool => $value !== '' && $value !== 0));
        $this->assignSmartyVariable('overviewEarlierUrl', $hasEarlier ? $overviewUrl($overviewOffset + 1) : '');
        $this->assignSmartyVariable('overviewLaterUrl', $overviewOffset > 0 ? $overviewUrl($overviewOffset - 1) : '');
        // Say so rather than silently hiding the card when nothing applies to this member.
        $this->assignSmartyVariable(
            'overviewHint',
            $overview === array() && $allTypes !== array() ? $gL10n->get('PLG_REQUIREMENTS_OVERVIEW_NONE') : ''
        );

        $this->assignSmartyVariable('periodLabel', $filter['periodRange'] === null ? '' : $filter['periodLabel']);
        // One way out of both narrowings: a member filter and a period filter are two halves of the
        // same "I followed a link to get here", and clearing one and not the other only puzzles.
        $this->assignSmartyVariable('clearFilterUrl', $this->pageUrl('index.php', array(
            'mode' => 'list',
            'query' => $query,
            'requirement' => $requirementUuid
        )));

        $columns = $this->listColumns();
        $this->assignSmartyVariable('columns', $columns);
        $this->assignSmartyVariable('filesEnabled', ActivityFileService::isEnabled());
        $this->assignSmartyVariable('groupOptions', array(
            '' => $gL10n->get('SYS_NONE'),
            'period' => $gL10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD'),
            'requirement' => $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE'),
            'provider' => $gL10n->get('PLG_REQUIREMENTS_PROVIDER'),
            'begin' => $gL10n->get('PLG_REQUIREMENTS_BEGIN_DATE'),
            'end' => $gL10n->get('PLG_REQUIREMENTS_END_DATE'),
            'certificate' => $gL10n->get('PLG_REQUIREMENTS_CERTIFICATE_DATE')
        ));
        $this->assignSmartyVariable('granularityOptions', array(
            'year' => $gL10n->get('PLG_REQUIREMENTS_PERIOD_YEAR'),
            'quarter' => $gL10n->get('PLG_REQUIREMENTS_PERIOD_QUARTER'),
            'month' => $gL10n->get('SYS_MONTH')
        ));

        $this->addJavascriptFile(ADMIDIO_URL . FOLDER_LIBS . '/datatables/datatables.js');
        $this->addCssFile(ADMIDIO_URL . FOLDER_LIBS . '/datatables/datatables.css');
        $langCode = $gL10n->getLanguageIsoCode() ?: 'en';
        $config = array(
            'dataUrl' => $this->pageUrl('index.php', array('mode' => 'data')),
            'exportUrl' => $this->pageUrl('index.php', array('mode' => 'export')),
            // Changing the requirement while a period from the overview narrows the list starts over
            // from the page: the period belongs to the requirement it was chosen for.
            'listUrl' => $this->pageUrl('index.php', array('mode' => 'list', 'user' => $effectiveUserUuid)),
            'languageUrl' => ADMIDIO_URL . FOLDER_LIBS . '/datatables/language/datatables.' . $langCode . '.json',
            'emptyTable' => $gL10n->get('SYS_NO_ENTRIES'),
            'allLabel' => $gL10n->get('SYS_ALL'),
            'user' => $effectiveUserUuid,
            'period' => $filter['periodRange'] === null ? '' : $period,
            'periodTo' => $filter['periodRange'] === null ? '' : $periodTo,
            'columns' => array_map(
                static fn(array $c): array => array('key' => $c['key'], 'sortable' => $c['sortable']),
                $columns
            ),
            'hiddenByDefault' => array_values(array_map(
                static fn(array $c): string => $c['key'],
                array_filter($columns, static fn(array $c): bool => !$c['visible'])
            ))
        );

        // Submitting or deleting an activity changes the overview as well as the row, so the page is
        // redrawn rather than letting callUrlHideElement() fade a row out.
        $this->addJavascript('function admReqReloadPage() { window.location.reload(); }');
        $this->addJavascript('
            const admReqConfig = ' . json_encode($config) . ';
            const admReqStoreKey = "admReqActivitiesList";
            let admReqState = {};
            try {
                admReqState = JSON.parse(window.localStorage.getItem(admReqStoreKey)) || {};
            } catch (e) {
                admReqState = {};
            }
            function admReqSaveState() {
                try {
                    window.localStorage.setItem(admReqStoreKey, JSON.stringify(admReqState));
                } catch (e) {
                    // Private windows refuse the storage; the choice then lasts for this page only.
                }
            }
            let admReqHidden = Array.isArray(admReqState.hidden) ? admReqState.hidden : admReqConfig.hiddenByDefault;
            // Grouped by the credited period until the viewer chooses otherwise: the period is what
            // a requirement is fulfilled in, so it is the first question the list answers.
            $("#adm_req_group").val(typeof admReqState.group === "string" ? admReqState.group : "period");
            if (typeof admReqState.granularity === "string") { $("#adm_req_granularity").val(admReqState.granularity); }
            function admReqToggleGranularity() {
                $("#adm_req_granularity_wrap").toggle(["begin", "end", "certificate"].indexOf($("#adm_req_group").val()) >= 0);
            }
            admReqToggleGranularity();
            $(".adm-req-col-toggle").each(function () {
                $(this).prop("checked", admReqHidden.indexOf($(this).data("column")) < 0);
            });
            // A column that only repeats the heading of its group is left out while the table is
            // grouped by it. A date group is a year, quarter or month, so the day stays in its column.
            const admReqGroupColumns = { period: "period", requirement: "requirement", provider: "provider" };
            function admReqShown(key) {
                return admReqHidden.indexOf(key) < 0 && admReqGroupColumns[$("#adm_req_group").val()] !== key;
            }
            // A heading row per group: its label across the columns before Credits, the total of the
            // group under Credits, and an empty cell for the rest. Without a Credits column on show
            // the total goes to the right end of the label.
            function admReqGroupHeadings(api) {
                $("#adm_req_activities_table tbody tr.admidio-group-heading").remove();
                if ($("#adm_req_group").val() === "") {
                    return;
                }
                const responsive = typeof api.columns().responsiveHidden === "function"
                    ? api.columns().responsiveHidden().toArray() : null;
                const shown = [];
                api.columns().every(function (i) {
                    if (this.visible() && (responsive === null || responsive[i])) {
                        shown.push(this.dataSrc());
                    }
                });
                const creditsAt = shown.indexOf("credits");
                // The total takes the classes of the Credits cells, and with them their alignment.
                const creditsClass = creditsAt > 0
                    ? ($(api.column("credits:name").nodes()).first().attr("class") || "") : "";
                const rows = api.rows({ page: "current" }).nodes();
                let last = null;
                api.rows({ page: "current" }).data().each(function (row, i) {
                    if (row.group === last) {
                        return;
                    }
                    last = row.group;
                    const heading = $("<tr class=\"admidio-group-heading fw-bold\"></tr>");
                    if (creditsAt > 0) {
                        heading.append($("<td></td>").attr("colspan", creditsAt).html(row.group));
                        heading.append($("<td></td>").addClass(creditsClass).html(row.groupTotal));
                        if (shown.length - creditsAt - 1 > 0) {
                            heading.append($("<td></td>").attr("colspan", shown.length - creditsAt - 1));
                        }
                    } else {
                        heading.append($("<td></td>").attr("colspan", shown.length).html(row.group
                            + (row.groupTotal === "" ? "" : "<span class=\"float-end\">" + row.groupTotal + "</span>")));
                    }
                    $(rows).eq(i).before(heading);
                });
            }
            function admReqApplyColumns() {
                admReqConfig.columns.forEach(function (c) {
                    admReqTable.column(c.key + ":name").visible(admReqShown(c.key), false);
                });
                admReqTable.columns.adjust();
                $("#adm_req_activities_table").toggleClass("adm-req-grouped", $("#adm_req_group").val() !== "");
                $(".adm-req-col-toggle").each(function () {
                    $(this).prop("disabled", admReqGroupColumns[$("#adm_req_group").val()] === $(this).data("column"));
                });
                admReqGroupHeadings(admReqTable);
            }

            const admReqTable = $("#adm_req_activities_table").DataTable({
                serverSide: true,
                processing: true,
                searching: false,
                responsive: true,
                fixedHeader: true,
                autoWidth: false,
                language: { url: admReqConfig.languageUrl, emptyTable: admReqConfig.emptyTable },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, admReqConfig.allLabel]],
                order: [[1, "desc"]],
                ajax: {
                    url: admReqConfig.dataUrl,
                    data: function (d) {
                        d.requirement = $("#adm_req_requirement").val();
                        d.provider = $("#adm_req_provider").val();
                        d.from = $("#adm_req_from").val();
                        d.to = $("#adm_req_to").val();
                        d.query = $("#adm_req_query").val();
                        d.group = $("#adm_req_group").val();
                        d.granularity = $("#adm_req_granularity").val();
                        d.user = admReqConfig.user;
                        d.period = admReqConfig.period;
                        d.period_to = admReqConfig.periodTo;
                    }
                },
                columns: admReqConfig.columns.map(function (c) {
                    return { data: c.key, name: c.key, orderable: c.sortable, visible: admReqShown(c.key) };
                }).concat([{ data: "actions", orderable: false, className: "text-end text-nowrap" }]),
                drawCallback: function () {
                    admReqGroupHeadings(this.api());
                }
            });
            // The group headings are laid out over the columns that are showing, so they are
            // rebuilt whenever that changes - by the column menu or by the responsive layout.
            admReqTable.on("responsive-resize", function () {
                admReqGroupHeadings(admReqTable);
            });
            admReqApplyColumns();
            // The rows arrive after the page has set up its tooltips.
            $("#adm_req_activities_table").on("draw.dt", function () {
                this.querySelectorAll("[data-bs-toggle=\"tooltip\"]").forEach(function (el) {
                    bootstrap.Tooltip.getOrCreateInstance(el);
                });
            });

            let admReqQueryTimer = null;
            $("#adm_req_query").on("input", function () {
                clearTimeout(admReqQueryTimer);
                admReqQueryTimer = setTimeout(function () { admReqTable.ajax.reload(); }, 300);
            });
            $("#adm_req_provider, #adm_req_from, #adm_req_to").on("change", function () {
                admReqTable.ajax.reload();
            });
            $("#adm_req_requirement").on("change", function () {
                if (admReqConfig.period !== "") {
                    window.location.href = admReqConfig.listUrl + "&requirement=" + encodeURIComponent($(this).val());
                    return;
                }
                admReqTable.ajax.reload();
            });
            $("#adm_req_group, #adm_req_granularity").on("change", function () {
                admReqState.group = $("#adm_req_group").val();
                admReqState.granularity = $("#adm_req_granularity").val();
                admReqSaveState();
                admReqToggleGranularity();
                admReqApplyColumns();
                admReqTable.ajax.reload();
            });
            $("#adm_req_view_reset").on("click", function () {
                try {
                    window.localStorage.removeItem(admReqStoreKey);
                } catch (e) {
                    // Nothing was stored, then.
                }
                window.location.reload();
            });
            $(".adm-req-col-toggle").on("change", function () {
                const key = $(this).data("column");
                admReqHidden = admReqHidden.filter(function (k) { return k !== key; });
                if (!$(this).is(":checked")) {
                    admReqHidden.push(key);
                }
                admReqState.hidden = admReqHidden;
                admReqSaveState();
                admReqApplyColumns();
            });
            $("#adm_req_filter").on("submit", function (e) { e.preventDefault(); });
            // The export takes the filters the table is showing, so the file is the list on screen.
            $(".adm-req-export").on("click", function () {
                const params = admReqTable.ajax.params();
                const keep = ["requirement", "provider", "from", "to", "query", "user", "period", "period_to"];
                const query = keep.reduce(function (p, k) {
                    p[k] = params[k];
                    return p;
                }, {});
                if ($(this).data("certificates")) {
                    query.certificates = 1;
                }
                window.location.href = admReqConfig.exportUrl + "&" + $.param(query);
            });
        ', true);

        $this->addHtmlByTemplate('requirements.activities.list.tpl');
    }

    /**
     * One page of the activity table as DataTables asks for it (server-side processing).
     *
     * @param array{requirement:string,period:string,user:string,query:string,provider:string,
     *     from:string,to:string,group:string,granularity:string} $filters the filter bar and the
     *     narrowing the page was opened with
     * @param array<int,array{0:string,1:string}> $order column key and direction, as the table asked
     * @return array<string,mixed> the DataTables response without `draw`
     * @throws Exception
     */
    public function listData(array $filters, array $order, int $start, int $length): array
    {
        global $gDb, $gL10n, $gSettingsManager;

        $filter = $this->resolveListFilter(
            $filters['requirement'],
            $filters['period'],
            $filters['user'],
            $filters['periodTo'] ?? ''
        );
        $groupBy = $filters['group'];
        $notSet = SecurityUtils::encodeHTML($gL10n->get('PLG_REQUIREMENTS_NOT_SPECIFIED'));
        $service = new ActivityService($gDb);
        $groupTotals = array();
        if ($groupBy === '') {
            $result = $service->findActivityRows($this->serviceFilter($filters, $filter), '', $order, $start, $length);
        } else {
            // A group may run on over several pages, and its heading shows the total of all of it -
            // so a grouped list reads every row it filters, totals the groups, and only then cuts
            // out the page. The groups are runs of consecutive rows, so the cut keeps them whole.
            $result = $service->findActivityRows($this->serviceFilter($filters, $filter), $groupBy, $order);
            foreach ($result['rows'] as $row) {
                if ($row['rac_id'] === null) {
                    continue;
                }
                $label = $this->groupLabel($row, $groupBy, $filters['granularity'], $filter['type'] === null, $notSet);
                $requirement = self::requirementName($row);
                $groupTotals[$label][$requirement]['claimed'] =
                    ($groupTotals[$label][$requirement]['claimed'] ?? 0.0) + (float) $row['rac_credits_claimed'];
                $groupTotals[$label][$requirement]['credited'] =
                    ($groupTotals[$label][$requirement]['credited'] ?? 0.0)
                    + (float) ($row['rac_credits_accepted'] ?? $row['rac_credits_claimed']);
            }
            $result['rows'] = array_slice($result['rows'], max(0, $start), $length > 0 ? $length : null);
        }

        $dateFormat = $gSettingsManager->getString('system_date');
        $formatDate = static fn(?string $ymd): string => $ymd === null || $ymd === ''
            ? '' : \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10))->format($dateFormat);
        $filesByActivity = ActivityFileService::isEnabled()
            ? (new ActivityFileService($gDb))->listForActivities(array_column($result['rows'], 'rqa_id'))
            : array();

        $data = array();
        foreach ($result['rows'] as $row) {
            $editUrl = $this->pageUrl('index.php', array('mode' => 'edit', 'uuid' => $row['rqa_uuid']));
            $rowId = 'adm_requirements_activity_' . $row['rqa_uuid'] . '_' . (int) $row['rac_id'];

            $entity = new Activity($gDb);
            $entity->setArray($row);
            $editable = $entity->isEditable();

            $periodText = self::periodText($row);
            $requirement = self::requirementName($row);
            $provider = self::providerName($row);

            $date = $formatDate($row['rqa_begin_date']);
            if ($row['rqa_end_date'] !== null && $row['rqa_end_date'] !== $row['rqa_begin_date']) {
                $date .= ' – ' . $formatDate($row['rqa_end_date']);
            }

            $group = '';
            $groupTotal = '';
            if ($groupBy !== '') {
                $group = $this->groupLabel($row, $groupBy, $filters['granularity'], $filter['type'] === null, $notSet);
                $groupTotal = self::groupTotal($groupTotals[$group] ?? array());
            }

            // The confirmation is shown as HTML, the text around the title is markup of its own,
            // and the title is what the member typed.
            $title = SecurityUtils::encodeHTML((string) $row['rqa_title']);
            $actions = array();
            $actions[] = array('url' => $editUrl, 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('SYS_EDIT'));
            // A draft only exists under the until_submitted policy, where submitting is what hands
            // the activity over for review - and the only way out of draft for its owner.
            if ($editable && (string) $row['rqa_status'] === 'draft') {
                $actions[] = $this->confirmedPostAction(
                    $rowId,
                    $this->pageUrl('index.php', array('mode' => 'submit', 'uuid' => $row['rqa_uuid'])),
                    $gL10n->get('PLG_REQUIREMENTS_SUBMIT_CONFIRM', array($title)),
                    'bi bi-send',
                    $gL10n->get('PLG_REQUIREMENTS_SUBMIT'),
                    'admReqReloadPage'
                );
            }
            if ($editable) {
                $actions[] = $this->confirmedPostAction(
                    $rowId,
                    $this->pageUrl('index.php', array('mode' => 'delete', 'uuid' => $row['rqa_uuid'])),
                    $gL10n->get('SYS_WANT_DELETE_ENTRY', array($title)),
                    'bi bi-trash',
                    $gL10n->get('SYS_DELETE'),
                    'admReqReloadPage'
                );
            }

            $data[] = array(
                'DT_RowId' => $rowId,
                'group' => $group,
                'groupTotal' => $groupTotal,
                'period' => SecurityUtils::encodeHTML($periodText),
                'date' => SecurityUtils::encodeHTML($date),
                'title' => '<a href="' . $editUrl . '">' . SecurityUtils::encodeHTML((string) $row['rqa_title']) . '</a>',
                'requirement' => $requirement === '' ? '' : '<span title="' . SecurityUtils::encodeHTML((string) $row['rqt_name'])
                    . '">' . SecurityUtils::encodeHTML($requirement) . '</span>',
                'credits' => $row['rac_id'] === null ? '' : self::creditsCell($row['rac_credits_claimed'], $row['rac_credits_accepted']),
                'provider' => SecurityUtils::encodeHTML($provider),
                'member' => '<a href="' . $this->pageUrl('index.php', array('mode' => 'list', 'user' => $row['user_uuid'])) . '">'
                    . SecurityUtils::encodeHTML(trim($row['last_name'] . ', ' . $row['first_name'], ', ')) . '</a>',
                'attendance' => (float) $row['rqa_attendance'] > 0 ? self::formatCredits($row['rqa_attendance']) : '',
                'certificate' => SecurityUtils::encodeHTML($formatDate($row['rqa_certificate_date'])),
                'files' => $this->filesCell($filesByActivity[(int) $row['rqa_id']] ?? array()),
                'actions' => $this->statusIcon($row) . self::actionsHtml($actions)
            );
        }

        return array(
            'recordsTotal' => $result['count'],
            'recordsFiltered' => $result['count'],
            'data' => $data
        );
    }

    /**
     * The filter bar and the narrowing the page was opened with, as findActivityRows() takes them.
     *
     * @param array<string,string> $filters
     * @param array<string,mixed> $resolved what resolveListFilter() made of the narrowing
     * @return array<string,mixed>
     * @throws Exception
     */
    private function serviceFilter(array $filters, array $resolved): array
    {
        global $gDb;

        $providerId = null;
        if ($filters['provider'] !== '') {
            foreach ((new ProviderService($gDb))->findVisible() as $provider) {
                if ($provider['rqp_uuid'] === $filters['provider']) {
                    $providerId = (int) $provider['rqp_id'];
                    break;
                }
            }
            // An unknown provider finds nothing, rather than quietly dropping the filter.
            $providerId ??= 0;
        }

        return array(
            'userId' => $resolved['userId'],
            'query' => $filters['query'],
            'requirementTypeId' => $resolved['type'] === null ? null : (int) $resolved['type']['rqt_id'],
            'periodRange' => $resolved['periodRange'],
            'providerId' => $providerId,
            'dateFrom' => $filters['from'],
            'dateTo' => $filters['to']
        );
    }

    /**
     * Send the activities the list shows for these filters as an Excel file in the importer's
     * layout, or - with $withCertificates - as a ZIP of that file and the certificates, which the
     * import wizard takes back as it is. See ActivityExportService.
     *
     * @param array<string,string> $filters as for listData()
     * @throws Exception|\PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function export(array $filters, bool $withCertificates = false): void
    {
        global $gDb;

        $resolved = $this->resolveListFilter(
            $filters['requirement'],
            $filters['period'],
            $filters['user'],
            $filters['periodTo'] ?? ''
        );
        $filter = $this->serviceFilter($filters, $resolved);
        $service = new ActivityExportService($gDb);
        $filename = 'requirements-activities-' . date('Y-m-d');

        if (!$withCertificates) {
            $spreadsheet = $service->spreadsheet($filter);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            header('Cache-Control: private, must-revalidate');
            (new Xlsx($spreadsheet))->save('php://output');
            return;
        }

        if (!ActivityFileService::isEnabled()) {
            throw new Exception('PLG_REQUIREMENTS_FILES_DISABLED');
        }
        $zipPath = tempnam(sys_get_temp_dir(), 'reqexport');
        try {
            $service->archive($filter, $zipPath);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '.zip"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: private, must-revalidate');
            readfile($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * The certificates of an activity as one paperclip per file, each a download link named by its
     * tooltip, so the column stays narrow however long the file names are.
     *
     * @param array<int,array<string,mixed>> $files
     */
    private function filesCell(array $files): string
    {
        $html = '';
        foreach ($files as $file) {
            $html .= '<a class="admidio-icon-link" href="' . $this->pageUrl('file.php', array('uuid' => $file['raf_uuid']))
                . '"><i class="bi bi-paperclip" data-bs-toggle="tooltip" title="'
                . SecurityUtils::encodeHTML((string) $file['raf_name']) . '"></i></a>';
        }
        return $html;
    }

    /**
     * Claimed and accepted credits in one cell. Where a reviewer accepted a different amount, the
     * claim is struck through and the accepted amount stands beside it; otherwise one number says
     * it all, and an unreviewed claim is simply the claim.
     */
    private static function creditsCell(mixed $claimed, mixed $accepted): string
    {
        $claimedText = self::formatCredits($claimed);
        $acceptedText = self::formatCredits($accepted);
        if ($acceptedText === '' || $acceptedText === $claimedText) {
            return $claimedText;
        }
        return '<del class="text-body-secondary">' . $claimedText . '</del> ' . $acceptedText;
    }

    /**
     * The heading of the group a row belongs to, as HTML. A period only names one together with its
     * requirement once the list shows several requirements.
     */
    private function groupLabel(array $row, string $groupBy, string $granularity, bool $allRequirements, string $notSet): string
    {
        $label = match ($groupBy) {
            'period' => self::periodText($row) === '' ? '' : self::periodText($row)
                . ($allRequirements ? ' · ' . self::requirementName($row) : ''),
            'requirement' => self::requirementName($row),
            'provider' => self::providerName($row),
            'begin' => self::dateGroupLabel($row['rqa_begin_date'], $granularity),
            'end' => self::dateGroupLabel($row['rqa_end_date'] ?? $row['rqa_begin_date'], $granularity),
            'certificate' => self::dateGroupLabel($row['rqa_certificate_date'], $granularity),
            default => ''
        };
        return $label === '' ? $notSet : SecurityUtils::encodeHTML($label);
    }

    /**
     * The credits of a whole group, shown in the Credits column of its heading and in the same form
     * as that column. A group of several requirements is totalled per requirement, because hours of
     * one requirement and points of another add up to nothing anybody could read.
     *
     * @param array<string,array{claimed:float,credited:float}> $totals by requirement
     */
    private static function groupTotal(array $totals): string
    {
        if ($totals === array()) {
            return '';
        }

        $parts = array();
        foreach ($totals as $requirement => $total) {
            $cell = self::creditsCell($total['claimed'], $total['credited']);
            $parts[] = count($totals) > 1 ? SecurityUtils::encodeHTML($requirement) . ': ' . $cell : $cell;
        }
        return implode(' &middot; ', $parts);
    }

    /**
     * The credited period of a row as its control shows it: "2026", "2026 Q2", "2026-03", ...
     */
    private static function periodText(array $row): string
    {
        if ($row['rac_period_reference'] === null) {
            return '';
        }
        $kind = FulfilmentService::periodControlKindFor((string) $row['rqt_period_anchor'], (string) $row['rqt_period_unit']);
        $parts = FulfilmentService::periodControlValue($kind, (string) $row['rac_period_reference']);
        return $parts['quarter'] !== '' ? $parts['value'] . ' Q' . $parts['quarter'] : $parts['value'];
    }

    /** The requirement of a row by its short name, or its name where it has none. */
    private static function requirementName(array $row): string
    {
        $name = (string) ($row['rqt_short_name'] ?? '');
        return $name !== '' ? $name : (string) ($row['rqt_name'] ?? '');
    }

    /** The provider of a row by its short name, or its name where it has none. */
    private static function providerName(array $row): string
    {
        $name = (string) ($row['rqa_provider_short_name'] ?? '');
        return $name !== '' ? $name : (string) ($row['rqa_provider_name'] ?? '');
    }

    /**
     * The year, quarter or month a date falls in, as the heading of a date group.
     */
    private static function dateGroupLabel(?string $ymd, string $granularity): string
    {
        if ($ymd === null || $ymd === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        return match ($granularity) {
            'quarter' => $date->format('Y') . ' Q' . (intdiv((int) $date->format('n') - 1, 3) + 1),
            'month' => $date->format('Y-m'),
            default => $date->format('Y')
        };
    }

    /**
     * The row actions as icon links, the markup sys-template-parts/list.functions.tpl gives a row
     * on a wide screen. The table rows are built here rather than by Smarty, because they arrive
     * as JSON.
     */
    private static function actionsHtml(array $actions): string
    {
        $html = '';
        foreach ($actions as $action) {
            $icon = '<i class="' . $action['icon'] . '" data-bs-toggle="tooltip" title="'
                . SecurityUtils::encodeHTML($action['tooltip']) . '"></i>';
            if (isset($action['dataHref'])) {
                $html .= '<a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"'
                    . ' data-message="' . SecurityUtils::encodeHTML($action['dataMessage']) . '"'
                    . ' data-href="' . SecurityUtils::encodeHTML($action['dataHref']) . '">' . $icon . '</a>';
            } else {
                $html .= '<a class="admidio-icon-link" href="' . $action['url'] . '">' . $icon . '</a>';
            }
        }
        return $html;
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
        foreach ($this->creditableTypes($existingCredits, $targetUserId) as $type) {
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
                'isNew' => $current === null,
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

        // The credited period follows the dates of the activity until the member sets it: a period
        // that was empty when the form opened, or that only this script has filled, moves with the
        // date last entered. A date input reports every keystroke of its year as a change - "0002",
        // "0020", "0202" - so a year before 1900 is a date still being typed, not one to follow.
        // A requirement row added from the selector starts with that period and with the attendance
        // hours as its claim, which is what most activities count with.
        $this->addJavascript('
            function admReqIsoWeek(d) {
                var t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
                t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7));
                var y = t.getUTCFullYear();
                var w = Math.ceil((((t - Date.UTC(y, 0, 1)) / 86400000) + 1) / 7);
                return y + "-W" + ("0" + w).slice(-2);
            }
            function admReqUsableDate(v) {
                return /^\d{4}-\d{2}-\d{2}$/.test(v) && parseInt(v.substring(0, 4), 10) >= 1900;
            }
            var admReqPeriodDate = "";
            function admReqActivityDate() {
                if (admReqPeriodDate !== "") { return admReqPeriodDate; }
                var v = $("#rqa_begin").val();
                return admReqUsableDate(v) ? v : "";
            }
            function admReqFillPeriod(field, v) {
                var jd = new Date(v + "T00:00:00");
                var k = field.attr("data-kind");
                if (k === "month") { field.val(v.substring(0, 7)); }
                else if (k === "week") { field.val(admReqIsoWeek(jd)); }
                else if (k === "year") { field.val(v.substring(0, 4)); }
                else if (k === "quarter") {
                    field.val(v.substring(0, 4));
                    field.closest(".input-group").find(".adm-req-period-quarter")
                        .val(String(Math.floor(jd.getMonth() / 3) + 1));
                }
                else { field.val(v); }
            }
            $(".adm-req-period[data-kind]").each(function () {
                if ($(this).val() === "" || $(this).closest("tr").is("[data-new]")) { $(this).attr("data-auto", "1"); }
            });
            $(".adm-req-period, .adm-req-period-quarter").on("input change", function () {
                $(this).closest("tr").find(".adm-req-period").removeAttr("data-auto");
            });
            $("#rqa_begin, #rqa_end, #rqa_certificate_date").on("change.reqcredit", function () {
                var v = $(this).val();
                if (!admReqUsableDate(v)) { return; }
                admReqPeriodDate = v;
                $(".adm-req-period[data-auto]").each(function () { admReqFillPeriod($(this), v); });
            });
            $("#adm_req_add_credit").on("change", function () {
                var id = $(this).val();
                if (id === "") { return; }
                var row = $("#adm_req_credit_" + id).prop("hidden", false);
                var period = row.find(".adm-req-period");
                var v = admReqActivityDate();
                if (v !== "" && (period.val() === "" || period.is("[data-auto]"))) {
                    admReqFillPeriod(period, v);
                }
                var claimed = $("#credit_" + id);
                if (claimed.val() === "" && $("#rqa_attendance").val() !== "") {
                    claimed.val($("#rqa_attendance").val());
                }
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
