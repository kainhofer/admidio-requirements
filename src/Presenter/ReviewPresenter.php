<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

/**
 * Administrative review (§9): per-member coverage of one requirement in one period, the claims each
 * row is made of, and the accepted credits editable in place.
 *
 * The member's own page and this one ask different questions of the same calculation. There the
 * question is "how am I doing", answered by bars across periods for one member; here it is "who owes
 * this, who has met it, and is the claim in front of me right", answered by one period across
 * members - with the contributions behind each number reachable without leaving the page, because a
 * reviewer who has to open an activity form per claim will not review anything.
 */
class ReviewPresenter extends RequirementsPresenter
{
    /**
     * The coverage statuses the filter offers, by requirement anchor. A calendar period and a
     * recurring cycle cannot produce the same answers - "overdue" needs a due date and "missing"
     * needs a period - so offering one fixed list would offer filters that never match.
     */
    private const STATUS_FILTERS = array(
        'calendar' => array('missing', 'fulfilled', 'no-credits-required', 'additional-credits', 'none'),
        'last_fulfilment' => array('overdue', 'never-fulfilled', 'fulfilled', 'additional-credits')
    );

    /**
     * The statuses that count as work still to do, for the summary line above the table.
     */
    private const OPEN_STATUSES = array('missing', 'overdue', 'never-fulfilled');

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(
        string $requirementUuid = '',
        string $period = '',
        string $quarter = '',
        string $status = ''
    ): void {
        global $gDb, $gL10n, $gCurrentSession;

        $this->setHtmlID('adm_requirements_review');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_REVIEW'));

        $this->addPageFunctionsMenuItem('menu_item_requirements_activities',
            $gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'), $this->pageUrl('index.php'), 'bi-clipboard-check');
        $this->addPageFunctionsMenuItem('menu_item_requirements_rules',
            $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPES'), $this->pageUrl('rules.php'), 'bi-list-check');

        $allTypes = (new RequirementTypeService($gDb))->findTypes();
        if ($allTypes === array()) {
            $this->assignSmartyVariable('noRequirements', $gL10n->get('PLG_REQUIREMENTS_REVIEW_NO_REQUIREMENTS'));
            $this->addHtmlByTemplate('requirements.review.filter.tpl');
            return;
        }

        $selectedType = $allTypes[0];
        foreach ($allTypes as $type) {
            if ($requirementUuid !== '' && $type['rqt_uuid'] === $requirementUuid) {
                $selectedType = $type;
                break;
            }
        }
        $typeEntity = new RequirementType($gDb);
        $typeEntity->setArray($selectedType);
        $kind = FulfilmentService::periodControlKind($typeEntity);
        $anchor = (string) $selectedType['rqt_period_anchor'] === 'calendar' ? 'calendar' : 'last_fulfilment';

        // A period the control cannot make sense of falls back to today rather than refusing the
        // page: the filter is a way of looking, and a bad one should not become an error message.
        $periodDate = '';
        if ($period !== '') {
            try {
                $periodDate = FulfilmentService::periodInputToDateForKind($kind, $period, $quarter);
            } catch (Exception) {
                $periodDate = '';
            }
        }

        $statusOptions = self::STATUS_FILTERS[$anchor];
        if (!in_array($status, $statusOptions, true)) {
            $status = '';
        }

        $coverage = (new FulfilmentService($gDb))->coverageForMembers(
            (int) $selectedType['rqt_id'],
            $periodDate,
            $status
        );

        $this->assignFilter($allTypes, $selectedType, $anchor, $kind, $coverage, $statusOptions, $status);

        $form = new FormPresenter(
            'adm_requirements_review_form',
            'requirements.review.list.tpl',
            $this->pageUrl('review.php', array(
                'mode' => 'save',
                'requirement' => (string) $selectedType['rqt_uuid'],
                'period' => $period,
                'periodq' => $quarter,
                'status' => $status
            )),
            $this
        );

        $rows = array();
        $contributions = 0;
        foreach ($coverage['members'] as $member) {
            $rows[] = $this->memberRow($form, $member, $coverage, (string) $selectedType['rqt_uuid'], $anchor);
            $contributions += count($member['contributions']);
        }

        if ($contributions > 0) {
            $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));
        }

        // The contributions of a member sit in a collapsed row of their own, so a roster of two
        // hundred stays readable; a reviewer working through the claims opens them all at once.
        // Bootstrap 5 has no jQuery plugin bridge, so this drives the collapse through its own API.
        $this->addJavascript('
            $("#adm_req_toggle_all").on("click", function () {
                var button = $(this);
                var open = button.attr("data-open") === "1";
                document.querySelectorAll(".adm-req-contributions").forEach(function (element) {
                    var collapse = bootstrap.Collapse.getOrCreateInstance(element, {toggle: false});
                    if (open) { collapse.hide(); } else { collapse.show(); }
                });
                button.attr("data-open", open ? "0" : "1");
                button.find(".adm-req-toggle-label")
                    .text(button.attr(open ? "data-label-expand" : "data-label-collapse"));
            });
        ', true);

        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('hasContributions', $contributions > 0);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('PLG_REQUIREMENTS_REVIEW_NO_MEMBERS'));
        $this->assignSmartyVariable('showLastFulfilment', $anchor === 'last_fulfilment');
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * The filter card above the table, and the summary of what it produced.
     *
     * @param array<int,array<string,mixed>> $allTypes
     * @param array<string,mixed> $selectedType
     * @param array<string,mixed> $coverage
     * @param array<int,string> $statusOptions
     * @throws Exception|\Smarty\Exception
     */
    private function assignFilter(
        array $allTypes,
        array $selectedType,
        string $anchor,
        string $kind,
        array $coverage,
        array $statusOptions,
        string $status
    ): void {
        global $gL10n;

        $typeOptions = array();
        foreach ($allTypes as $type) {
            $typeOptions[] = array(
                'uuid' => $type['rqt_uuid'],
                'name' => SecurityUtils::encodeHTML((string) $type['rqt_name']),
                'selected' => $type['rqt_uuid'] === $selectedType['rqt_uuid']
            );
        }

        $statusEntries = array();
        foreach ($statusOptions as $option) {
            $statusEntries[] = array(
                'value' => $option,
                'name' => $gL10n->get(self::statusLabelKey($option)),
                'selected' => $option === $status
            );
        }

        $fulfilled = 0;
        $open = 0;
        foreach ($coverage['members'] as $member) {
            if ($member['status'] === 'fulfilled') {
                ++$fulfilled;
            } elseif (in_array($member['status'], self::OPEN_STATUSES, true)) {
                ++$open;
            }
        }

        $parts = FulfilmentService::periodControlValue($kind, $coverage['periodReference']);

        $this->assignSmartyVariable('noRequirements', '');
        $this->assignSmartyVariable('filterUrl', $this->plugin->getUrl('review.php'));
        $this->assignSmartyVariable('typeOptions', $typeOptions);
        $this->assignSmartyVariable('statusEntries', $statusEntries);
        $this->assignSmartyVariable('periodKind', $kind);
        $this->assignSmartyVariable('periodValue', SecurityUtils::encodeHTML($parts['value']));
        $this->assignSmartyVariable('quarterValue', $parts['quarter']);
        $this->assignSmartyVariable('maxYear', (string) ((int) date('Y') + 1));
        // A recurring requirement has no period to pick; the date says which day the cycles are
        // judged as of, which is a different question and gets a different label.
        $this->assignSmartyVariable('periodLabel', $anchor === 'calendar'
            ? $gL10n->get('SYS_PERIOD')
            : $gL10n->get('PLG_REQUIREMENTS_REVIEW_AS_OF'));
        $this->assignSmartyVariable('summary', $gL10n->get('PLG_REQUIREMENTS_REVIEW_SUMMARY', array(
            count($coverage['members']),
            $fulfilled,
            $open
        )));
        // Admidio's Smarty does not escape by default, so a value that reaches a template escapes
        // itself on the way in - the same rule the type names above follow.
        $this->assignSmartyVariable('coverageHeadline', $gL10n->get('PLG_REQUIREMENTS_REVIEW_FOR', array(
            SecurityUtils::encodeHTML((string) $selectedType['rqt_name']),
            SecurityUtils::encodeHTML((string) $coverage['periodLabel'])
        )));
        $this->addHtmlByTemplate('requirements.review.filter.tpl');
    }

    /**
     * One member of the roster, with an accepted-credits input per contribution.
     *
     * @param array<string,mixed> $member
     * @param array<string,mixed> $coverage
     * @return array<string,mixed>
     * @throws Exception
     */
    private function memberRow(
        FormPresenter $form,
        array $member,
        array $coverage,
        string $requirementUuid,
        string $anchor
    ): array {
        global $gL10n;

        // The evidence belongs beside the number it supports: a reviewer who has to leave the page
        // to look at a certificate will set the accepted value without looking at it.
        $filesByActivity = array();
        if (ActivityFileService::isEnabled()) {
            $filesByActivity = $this->filesFor(array_values(array_unique(array_map(
                static fn(array $contribution): int => (int) $contribution['activityId'],
                $member['contributions']
            ))));
        }

        $contributions = array();
        foreach ($member['contributions'] as $contribution) {
            $field = 'accepted_' . $contribution['creditId'];
            $accepted = $contribution['acceptedCredits'] === null
                ? '' : self::formatCredits($contribution['acceptedCredits']);

            $form->addInput($field, $gL10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED'), $accepted,
                array('type' => 'number', 'minNumber' => 0, 'step' => 0.01));

            $contributions[] = array(
                'title' => SecurityUtils::encodeHTML((string) $contribution['title']),
                'url' => $this->pageUrl('index.php', array(
                    'mode' => 'edit',
                    'uuid' => $contribution['activityUuid']
                )),
                'date' => (string) $contribution['date'],
                'periodReference' => (string) $contribution['periodReference'],
                'claimed' => self::formatCredits($contribution['claimedCredits']),
                'acceptedId' => $field,
                'acceptedValue' => SecurityUtils::encodeHTML($accepted),
                // A draft is reported as pending rather than counted (REQ-DEC-022), which a reviewer
                // looking at a number that does not add up needs to be told.
                'counts' => (bool) $contribution['counts'],
                'files' => $filesByActivity[(int) $contribution['activityId']] ?? array(),
                'statusText' => $gL10n->get(match ((string) $contribution['status']) {
                    'draft' => 'PLG_REQUIREMENTS_STATUS_DRAFT',
                    'withdrawn' => 'PLG_REQUIREMENTS_STATUS_WITHDRAWN',
                    default => 'PLG_REQUIREMENTS_STATUS_SUBMITTED'
                })
            );
        }

        // The activity list of one member, narrowed to this requirement. A recurring requirement has
        // no period to narrow by: every contribution of theirs is part of the running cycle.
        $activityParameters = array('mode' => 'list', 'requirement' => $requirementUuid,
            'user' => (string) $member['userUuid']);
        if ($anchor === 'calendar') {
            $activityParameters['period'] = (string) $coverage['periodReference'];
        }

        return array(
            'id' => 'adm_requirements_review_' . $member['userId'],
            'name' => SecurityUtils::encodeHTML((string) $member['name']),
            'activitiesUrl' => $this->pageUrl('index.php', $activityParameters),
            'required' => $member['required'] === null ? '' : self::formatCredits($member['required']),
            'attained' => self::formatCredits($member['attained']),
            'pending' => $member['pending'] > 0 ? self::formatCredits($member['pending']) : '',
            'percent' => $member['percent'],
            'status' => (string) $member['status'],
            'statusText' => $gL10n->get(self::statusLabelKey((string) $member['status'])),
            'lastFulfilment' => (string) ($member['lastFulfilment'] ?? ''),
            'nextDue' => (string) ($member['nextDue'] ?? ''),
            'warnings' => array_map(
                static fn(string $warning): string => SecurityUtils::encodeHTML($warning),
                $member['warnings']
            ),
            'contributions' => $contributions
        );
    }

    /**
     * The certificate files of these activities, as download links keyed by activity id.
     *
     * One query for the whole roster rather than one per contribution: a review page of two hundred
     * members would otherwise open the file table two hundred times.
     *
     * @param array<int,int> $activityIds
     * @return array<int,array<int,array{name:string,url:string}>>
     * @throws Exception
     */
    private function filesFor(array $activityIds): array
    {
        global $gDb;

        if ($activityIds === array()) {
            return array();
        }

        $rows = $gDb->getArrayFromSql(
            'SELECT raf_activity_id, raf_uuid, raf_name
               FROM ' . RequirementsTables::activityFiles() . '
              WHERE raf_activity_id IN (' . implode(', ', array_fill(0, count($activityIds), '?')) . ')
           ORDER BY raf_timestamp_create, raf_id',
            $activityIds
        );

        $byActivity = array();
        foreach ($rows as $row) {
            $byActivity[(int) $row['raf_activity_id']][] = array(
                'name' => SecurityUtils::encodeHTML((string) $row['raf_name']),
                'url' => $this->pageUrl('file.php', array('uuid' => $row['raf_uuid']))
            );
        }

        return $byActivity;
    }

    /**
     * The translation key a coverage status reads as. The statuses themselves are the calculator's
     * vocabulary (§8.1); this is the only place that turns them into a sentence.
     */
    public static function statusLabelKey(string $status): string
    {
        return match ($status) {
            'fulfilled' => 'PLG_REQUIREMENTS_COVERAGE_FULFILLED',
            'missing' => 'PLG_REQUIREMENTS_COVERAGE_MISSING',
            'overdue' => 'PLG_REQUIREMENTS_COVERAGE_OVERDUE',
            'never-fulfilled' => 'PLG_REQUIREMENTS_COVERAGE_NEVER_FULFILLED',
            'no-credits-required' => 'PLG_REQUIREMENTS_COVERAGE_NO_CREDITS_REQUIRED',
            'additional-credits' => 'PLG_REQUIREMENTS_COVERAGE_ADDITIONAL_CREDITS',
            default => 'PLG_REQUIREMENTS_COVERAGE_NONE'
        };
    }

    /**
     * The coverage statuses that may be filtered for a requirement of this anchor.
     *
     * @return array<int,string>
     */
    public static function statusFilters(string $anchor): array
    {
        return self::STATUS_FILTERS[$anchor] ?? self::STATUS_FILTERS['calendar'];
    }
}
