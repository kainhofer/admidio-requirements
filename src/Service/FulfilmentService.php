<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use DateInterval;
use DateTimeImmutable;

final class FulfilmentService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function coverageForUser(int $userId, string $referenceDate): array
    {
        global $gCurrentOrgId, $gCurrentUser, $gValidLogin, $gL10n;

        if (!RequirementsPlugin::isUsableByCurrentUser()
            || (
                $userId !== (int)$gCurrentUser->getValue('usr_id')
                && !Component::isAdministrable(RequirementsPlugin::ID)
            )) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $user = new User($this->db, null, $userId);
        $user->setOrganization((int)$gCurrentOrgId);
        if ((int)$user->getValue('usr_id') === 0 || !$user->isMemberOfOrganization()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $reference = self::date($referenceDate);
        $types = $this->db->queryPrepared(
            'SELECT *
               FROM ' . RequirementsTables::requirementTypes() . '
              WHERE rqt_org_id = ?
              ORDER BY rqt_sequence, rqt_name',
            array((int)$gCurrentOrgId)
        );

        $result = array();
        while ($row = $types->fetch()) {
            $type = new RequirementType($this->db);
            $type->setArray($row);

            if ((string)$type->getValue('rqt_period_anchor') !== 'calendar') {
                $recurring = $this->lastFulfilmentCoverage($type, $userId, $reference);
                if ($recurring !== null) {
                    $result[] = $recurring;
                }
                continue;
            }

            [$periodStart, $periodEnd, $label] = self::periodFor($type, $reference);
            $rules = $this->loadRules($type, $periodStart, $periodEnd);
            $roleIds = array_values(array_unique(array_map(
                static fn(array $rule): int => (int)$rule['rrr_role_id'],
                $rules
            )));
            $memberships = $this->loadMemberships($userId, $roleIds, $periodStart, $periodEnd);

            [$required, $warnings, $hasRule] = $this->calculateRequired(
                $type,
                $rules,
                $memberships,
                $periodStart,
                $periodEnd
            );

            $credits = $this->loadCredits(
                $userId,
                (int)$type->getValue('rqt_id'),
                $periodStart->format('Y-m-d')
            );
            [$attained, $pending] = self::sumCredits($credits);
            if ($required <= 0.0 && $credits === array() && !$hasRule) {
                continue;
            }

            $coverage = $required > 0.0 ? min(100.0, ($attained / $required) * 100.0) : null;

            $result[] = array(
                'typeId' => (int)$type->getValue('rqt_id'),
                'requirement' => (string)$type->getValue('rqt_name'),
                'shortName' => (string)$type->getValue('rqt_short_name'),
                'period' => $label,
                'periodReference' => $periodStart->format('Y-m-d'),
                'attained' => round($attained, 2),
                'pending' => round($pending, 2),
                'required' => $required > 0.0 ? round($required, 2) : null,
                'coverage' => $coverage === null ? null : round($coverage, 1),
                'status' => self::coverageStatus($required, $attained, $hasRule),
                'warnings' => $warnings,
                'activities' => $credits
            );
        }

        return $result;
    }

    /**
     * Coverage of a requirement that recurs from its last fulfilment (§8.3, REQ-DEC-005).
     *
     * There is no calendar period here, so nothing can be pro-rated and nothing is bounded by a
     * year: the cycles are found by walking the contributions in the order of their credited period
     * and accumulating them until the target is reached. The date of the contribution that reaches
     * it is the renewal date, accumulation restarts from zero, and the next due date is that renewal
     * plus the configured interval.
     *
     * Excess credit does not carry into the new cycle, which is the point of the rule: a member who
     * collects 30 hours against a 15-hour requirement has renewed once, not twice.
     *
     * A target of zero has no threshold to cross, which is the binary case the concept settles
     * separately: any positive effective credit renews it.
     *
     * @return array<string,mixed>|null null when the requirement does not apply to this member and
     *         carries no credits of theirs either
     * @throws Exception
     */
    private function lastFulfilmentCoverage(
        RequirementType $type,
        int $userId,
        DateTimeImmutable $reference
    ): ?array {
        [$target, $warnings, $hasRule] = $this->recurringTarget($type, $userId, $reference);

        return self::recurringOutcome(
            $type,
            $this->loadAllCredits($userId, (int) $type->getValue('rqt_id')),
            $target,
            $hasRule,
            $warnings,
            $reference
        );
    }

    /**
     * The recurring coverage row itself, once the obligation and the contributions are known.
     *
     * Split out of lastFulfilmentCoverage() because the administrative review view needs the same
     * answer for a whole roster and loads its contributions in one query rather than one per member
     * (§9). Everything here is a function of the arguments, so both callers get the same row.
     *
     * @param array<int,array<string,mixed>> $credits every contribution, in credited-period order
     * @param array<int,string> $warnings
     * @return array<string,mixed>|null null when the requirement does not apply to this member and
     *         carries no credits of theirs either
     * @throws Exception
     */
    private static function recurringOutcome(
        RequirementType $type,
        array $credits,
        float $target,
        bool $hasRule,
        array $warnings,
        DateTimeImmutable $reference
    ): ?array {
        $typeId = (int) $type->getValue('rqt_id');

        if (!$hasRule && $credits === array()) {
            return null;
        }

        $counting = array_values(array_filter($credits, static fn(array $c): bool => $c['counts']));
        $pending = array_sum(array_column(
            array_filter($credits, static fn(array $c): bool => !$c['counts']),
            'effectiveCredits'
        ));

        [$renewals, $running] = self::renewalCycles($counting, $target);
        $lastRenewal = $renewals === array() ? null : end($renewals);

        $nextDue = $lastRenewal === null
            ? null
            : self::date($lastRenewal)->add(self::renewalInterval($type))->format('Y-m-d');

        if ($lastRenewal === null) {
            $status = $hasRule ? 'never-fulfilled' : 'additional-credits';
        } elseif ($nextDue !== null && $nextDue <= $reference->format('Y-m-d')) {
            $status = 'overdue';
        } else {
            $status = 'fulfilled';
        }

        return array(
            'typeId' => $typeId,
            'requirement' => (string) $type->getValue('rqt_name'),
            'shortName' => (string) $type->getValue('rqt_short_name'),
            'period' => self::renewalLabel($type),
            'periodReference' => $lastRenewal,
            'attained' => round($running, 2),
            'pending' => round((float) $pending, 2),
            'required' => $target > 0.0 ? round($target, 2) : null,
            'coverage' => $target > 0.0 ? round(min(100.0, ($running / $target) * 100.0), 1) : null,
            'status' => $status,
            'lastFulfilment' => $lastRenewal,
            'nextDue' => $nextDue,
            'warnings' => $warnings,
            'activities' => $credits
        );
    }

    /**
     * Walk the contributions of a recurring requirement and find the dates it was renewed on.
     *
     * This is the threshold-crossing rule of §8.3 (REQ-DEC-005) on its own: accumulate the effective
     * credits in the order of their credited period, and every time the running total reaches the
     * target, that contribution's date is a renewal and the total starts again from zero. Excess is
     * deliberately dropped rather than carried, so 30 hours against a 15-hour requirement renew it
     * once, not twice.
     *
     * A target of zero or less has no threshold to cross. That is the binary case - a certificate
     * either exists or it does not - and any positive contribution renews it.
     *
     * @param array<int,array<string,mixed>> $credits contributions that count, ordered by their
     *        credited-period date, each with `periodReference` and `effectiveCredits`
     * @return array{0:array<int,string>,1:float} the renewal dates in order, and how much has been
     *         accumulated towards the next one
     */
    public static function renewalCycles(array $credits, float $target): array
    {
        $renewals = array();
        $running = 0.0;

        foreach ($credits as $credit) {
            $effective = (float) $credit['effectiveCredits'];

            if ($target <= 0.0) {
                if ($effective > 0.0) {
                    $renewals[] = (string) $credit['periodReference'];
                }
                continue;
            }

            $running += $effective;
            if ($running >= $target) {
                $renewals[] = (string) $credit['periodReference'];
                $running = 0.0;
            }
        }

        return array($renewals, $running);
    }

    /**
     * The credit target of a recurring requirement: the rules valid at the reference date, for the
     * roles the member holds then, combined by the requirement's multiple-roles mode.
     *
     * Nothing is pro-rated. Proration answers "how much of this period were you a member for", and a
     * recurring requirement has no period - the obligation is simply what the rule says.
     *
     * @return array{0:float,1:array<int,string>,2:bool}
     * @throws Exception
     */
    private function recurringTarget(RequirementType $type, int $userId, DateTimeImmutable $reference): array
    {
        $rules = $this->loadRules($type, $reference, $reference);
        $roleIds = array_values(array_unique(array_map(
            static fn(array $rule): int => (int) $rule['rrr_role_id'],
            $rules
        )));
        $memberships = $this->loadMemberships($userId, $roleIds, $reference, $reference);

        // `full` for every row: see above.
        foreach ($rules as $index => $rule) {
            $rules[$index]['rrr_partial_period_mode'] = 'full';
        }

        return self::calculateRequiredCredits(
            (string) $type->getValue('rqt_multiple_roles_mode'),
            $rules,
            $memberships,
            $reference,
            $reference
        );
    }

    /**
     * The gap between one fulfilment and the next, from the unit and count of the requirement.
     *
     * Unlike a calendar period a count above one is well defined here: the cycle is measured from
     * the renewal date, so "every two years" needs no alignment anchor (§8.4).
     *
     * @throws Exception
     */
    private static function renewalInterval(RequirementType $type): DateInterval
    {
        $count = max(1, (int) $type->getValue('rqt_period_count'));

        return new DateInterval(match ((string) $type->getValue('rqt_period_unit')) {
            'year' => 'P' . $count . 'Y',
            'quarter' => 'P' . ($count * 3) . 'M',
            'month' => 'P' . $count . 'M',
            'week' => 'P' . ($count * 7) . 'D',
            default => throw new Exception('SYS_INVALID_PAGE_VIEW')
        });
    }

    private static function renewalLabel(RequirementType $type): string
    {
        global $gL10n;

        $count = max(1, (int) $type->getValue('rqt_period_count'));
        $unit = $gL10n->get(match ((string) $type->getValue('rqt_period_unit')) {
            'quarter' => 'PLG_REQUIREMENTS_PERIOD_QUARTER',
            'month' => 'PLG_REQUIREMENTS_PERIOD_MONTH',
            'week' => 'PLG_REQUIREMENTS_PERIOD_WEEK',
            default => 'PLG_REQUIREMENTS_PERIOD_YEAR'
        });

        return $gL10n->get('PLG_REQUIREMENTS_PERIOD_RECURRING', array($count . ' ' . $unit));
    }

    /**
     * Fulfilment of every calendar requirement the member's roles currently carry, for the running
     * period and the $previousPeriods before it. Used by the overview bars above the activity list.
     *
     * The period unit follows the requirement, so a yearly requirement yields years and a quarterly
     * one quarters. Requirements without a role rule for this member are not shown at all.
     *
     * @return array<int,array{typeId:int,requirement:string,shortName:string,periods:array<int,array<string,mixed>>}>
     * @throws Exception
     */
    public function coverageHistoryForUser(int $userId, int $previousPeriods = 4): array
    {
        global $gCurrentOrgId, $gCurrentUser, $gValidLogin;

        if (!RequirementsPlugin::isUsableByCurrentUser()
            || (
                $userId !== (int) $gCurrentUser->getValue('usr_id')
                && !Component::isAdministrable(RequirementsPlugin::ID)
            )) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $previousPeriods = max(0, min(20, $previousPeriods));
        $applicable = $this->applicableRequirementTypeIds($userId);
        if ($applicable === array()) {
            return array();
        }

        $rows = $this->db->getArrayFromSql(
            'SELECT * FROM ' . RequirementsTables::requirementTypes() . '
              WHERE rqt_org_id = ?
              ORDER BY rqt_sequence, rqt_name',
            array((int) $gCurrentOrgId)
        );

        $today = self::date(DATE_NOW);
        $result = array();

        foreach ($rows as $row) {
            $type = new RequirementType($this->db);
            $type->setArray($row);
            $typeId = (int) $type->getValue('rqt_id');

            if (!in_array($typeId, $applicable, true)) {
                continue;
            }

            // A requirement that recurs from its last fulfilment has no periods to lay side by
            // side. It gets one bar: how far the running cycle has come, labelled with its due date.
            if ((string) $type->getValue('rqt_period_anchor') !== 'calendar') {
                $recurring = $this->lastFulfilmentCoverage($type, $userId, $today);
                if ($recurring !== null) {
                    $result[] = array(
                        'typeId' => $typeId,
                        'requirement' => $recurring['requirement'],
                        'shortName' => $recurring['shortName'],
                        'periods' => array(array(
                            'label' => $recurring['nextDue'] ?? $recurring['period'],
                            'periodReference' => $recurring['periodReference'],
                            'attained' => $recurring['attained'],
                            'pending' => $recurring['pending'],
                            'required' => $recurring['required'],
                            'percent' => $recurring['coverage'] === null ? null : (int) round($recurring['coverage']),
                            'status' => $recurring['status'],
                            'warnings' => $recurring['warnings']
                        ))
                    );
                }
                continue;
            }

            if ((int) $type->getValue('rqt_period_count') !== 1) {
                continue;
            }

            // Walk back whole periods from the start of the running one, so no period is skipped.
            $unit = (string) $type->getValue('rqt_period_unit');
            [$anchor, $stepSize, $stepUnit] = match ($unit) {
                'quarter' => array(self::periodFor($type, $today)[0], 3, 'months'),
                'month' => array($today->modify('first day of this month'), 1, 'month'),
                'week' => array($today->modify('monday this week'), 7, 'days'),
                default => array($today->setDate((int) $today->format('Y'), 1, 1), 1, 'year')
            };

            $periods = array();
            for ($i = $previousPeriods; $i >= 0; --$i) {
                $reference = $i === 0
                    ? $anchor
                    : $anchor->modify('-' . ($i * $stepSize) . ' ' . $stepUnit);
                $periods[] = $this->periodCoverage($type, $userId, $reference);
            }

            $result[] = array(
                'typeId' => $typeId,
                'requirement' => (string) $type->getValue('rqt_name'),
                'shortName' => (string) $type->getValue('rqt_short_name'),
                'periods' => $periods
            );
        }

        return $result;
    }

    /**
     * Required and attained credits of one requirement in the period that contains $reference.
     *
     * @return array<string,mixed>
     * @throws Exception
     */
    private function periodCoverage(RequirementType $type, int $userId, DateTimeImmutable $reference): array
    {
        [$periodStart, $periodEnd, $label] = self::periodFor($type, $reference);

        $rules = $this->loadRules($type, $periodStart, $periodEnd);
        $roleIds = array_values(array_unique(array_map(
            static fn(array $rule): int => (int) $rule['rrr_role_id'],
            $rules
        )));
        $memberships = $this->loadMemberships($userId, $roleIds, $periodStart, $periodEnd);

        [$required, $warnings, $hasRule] = $this->calculateRequired(
            $type,
            $rules,
            $memberships,
            $periodStart,
            $periodEnd
        );

        $credits = $this->loadCredits($userId, (int) $type->getValue('rqt_id'), $periodStart->format('Y-m-d'));
        [$attained, $pending] = self::sumCredits($credits);

        return array(
            'label' => $label,
            'periodReference' => $periodStart->format('Y-m-d'),
            'attained' => round($attained, 2),
            'pending' => round($pending, 2),
            'required' => $required > 0.0 ? round($required, 2) : null,
            'percent' => $required > 0.0 ? (int) round(min(100.0, ($attained / $required) * 100.0)) : null,
            'status' => self::coverageStatus($required, $attained, $hasRule),
            'warnings' => $warnings
        );
    }

    /**
     * Per-member coverage of one requirement, for the administrative review view (§9).
     *
     * The member's own page asks "how am I doing"; this asks the reviewer's question instead - who
     * does this requirement concern, who has met it, and what are the claims each answer is made of.
     * It therefore also covers members who have since left the role but still carry credits for the
     * period, which the member's own overview deliberately leaves out (§8.5): on their page that is
     * history, in a review of the period it is the record under review.
     *
     * The queries are batched rather than looped - one for the candidates, one for the rules, one
     * for the memberships and one for the credits, whatever the size of the roster - but the policy
     * is the same calculateRequiredCredits() and the same recurringOutcome() the single-member path
     * uses, so a roster row and a member's own bar cannot disagree.
     *
     * @param string $periodReference any day of the requested period; empty means today
     * @param string $statusFilter    keep only members with this coverage status; empty keeps all
     * @return array{typeId:int,requirement:string,shortName:string,anchor:string,
     *     periodReference:string,periodLabel:string,members:array<int,array<string,mixed>>}
     * @throws Exception
     */
    public function coverageForMembers(
        int $requirementTypeId,
        string $periodReference = '',
        string $statusFilter = ''
    ): array {
        if (!RequirementsPlugin::isUsableByCurrentUser()
            || !Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        // OrganizationScope answers a requirement of another organization with an empty record, so
        // a foreign id reads the same way an unknown one does.
        $type = new RequirementType($this->db, $requirementTypeId);
        $typeId = (int) $type->getValue('rqt_id');
        if ($typeId === 0) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $reference = self::date($periodReference === '' ? DATE_NOW : $periodReference);
        $calendar = (string) $type->getValue('rqt_period_anchor') === 'calendar';
        [$start, $end, $label] = $calendar
            ? self::periodFor($type, $reference)
            : array($reference, $reference, self::renewalLabel($type));

        $rules = $this->loadRules($type, $start, $end);
        if (!$calendar) {
            // Nothing is pro-rated when there is no period; see recurringTarget().
            foreach ($rules as $index => $rule) {
                $rules[$index]['rrr_partial_period_mode'] = 'full';
            }
        }
        $roleIds = array_values(array_unique(array_map(
            static fn(array $rule): int => (int) $rule['rrr_role_id'],
            $rules
        )));

        // A recurring requirement has no period to filter the contributions by: its cycles are found
        // by walking all of them in order.
        $creditPeriod = $calendar ? $start->format('Y-m-d') : null;
        $candidates = $this->reviewCandidates($roleIds, $typeId, $start, $end, $creditPeriod);
        $userIds = array_keys($candidates);
        $memberships = $this->membershipsForUsers($userIds, $roleIds, $start, $end);
        $credits = $this->readCreditsForUsers($userIds, $typeId, $creditPeriod);
        $mode = (string) $type->getValue('rqt_multiple_roles_mode');

        $members = array();
        foreach ($candidates as $userId => $candidate) {
            $userCredits = $credits[$userId] ?? array();
            [$required, $warnings, $hasRule] = self::calculateRequiredCredits(
                $mode,
                $rules,
                $memberships[$userId] ?? array(),
                $start,
                $end
            );

            if ($calendar) {
                // A candidate whose rules were all gated out and who contributed nothing is not part
                // of the roster: the review answers who this requirement concerns.
                if ($required <= 0.0 && !$hasRule && $userCredits === array()) {
                    continue;
                }

                [$attained, $pending] = self::sumCredits($userCredits);
                $row = array(
                    'required' => $required > 0.0 ? round($required, 2) : null,
                    'attained' => round($attained, 2),
                    'pending' => round($pending, 2),
                    'percent' => $required > 0.0
                        ? (int) round(min(100.0, ($attained / $required) * 100.0))
                        : null,
                    'status' => self::coverageStatus($required, $attained, $hasRule),
                    'lastFulfilment' => null,
                    'nextDue' => null,
                    'warnings' => $warnings
                );
            } else {
                $outcome = self::recurringOutcome(
                    $type,
                    $userCredits,
                    $required,
                    $hasRule,
                    $warnings,
                    $reference
                );
                if ($outcome === null) {
                    continue;
                }

                $row = array(
                    'required' => $outcome['required'],
                    'attained' => $outcome['attained'],
                    'pending' => $outcome['pending'],
                    'percent' => $outcome['coverage'] === null ? null : (int) round($outcome['coverage']),
                    'status' => $outcome['status'],
                    'lastFulfilment' => $outcome['lastFulfilment'],
                    'nextDue' => $outcome['nextDue'],
                    'warnings' => $outcome['warnings']
                );
            }

            if ($statusFilter !== '' && $row['status'] !== $statusFilter) {
                continue;
            }

            $members[] = $candidate + $row + array('contributions' => $userCredits);
        }

        return array(
            'typeId' => $typeId,
            'requirement' => (string) $type->getValue('rqt_name'),
            'shortName' => (string) $type->getValue('rqt_short_name'),
            'anchor' => $calendar ? 'calendar' : 'last_fulfilment',
            'periodReference' => $calendar ? $start->format('Y-m-d') : $reference->format('Y-m-d'),
            'periodLabel' => $label,
            'members' => $members
        );
    }

    /**
     * Everybody one requirement period concerns: the members of a role its rules name, and everybody
     * who carries a contribution to it. The second half is not redundant - a member who left the role
     * last year still has to appear in the review of the period they collected credits in.
     *
     * Both halves are organization-scoped, the roles through their category and the activities
     * through their own column, so a member of another organization can never enter the roster.
     *
     * @param array<int,int> $roleIds
     * @param string|null $periodReference restrict the credit half to one credited period
     * @return array<int,array{userId:int,userUuid:string,firstName:string,lastName:string,name:string}>
     *         keyed by user id, ordered by name
     * @throws Exception
     */
    private function reviewCandidates(
        array $roleIds,
        int $requirementTypeId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string $periodReference
    ): array {
        global $gCurrentOrgId, $gProfileFields;

        $params = array(
            (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id')
        );

        $conditions = array();
        if ($roleIds !== array()) {
            $conditions[] = 'usr.usr_id IN (SELECT mem_usr_id
                                              FROM ' . TBL_MEMBERS . '
                                             WHERE mem_rol_id IN ('
                . implode(', ', array_fill(0, count($roleIds), '?')) . ')
                                               AND mem_begin <= ?
                                               AND mem_end >= ?)';
            $params = array_merge($params, $roleIds, array($end->format('Y-m-d'), $start->format('Y-m-d')));
        }

        $creditCondition = 'usr.usr_id IN (SELECT rqa.rqa_user_id
                                             FROM ' . RequirementsTables::activityCredits() . ' AS rac
                                       INNER JOIN ' . RequirementsTables::activities() . ' AS rqa
                                               ON rqa.rqa_id = rac.rac_activity_id
                                            WHERE rqa.rqa_org_id = ?
                                              AND rac.rac_requirement_type_id = ?
                                              AND rqa.rqa_status <> ?';
        $params[] = (int) $gCurrentOrgId;
        $params[] = $requirementTypeId;
        $params[] = 'withdrawn';
        if ($periodReference !== null) {
            $creditCondition .= ' AND rac.rac_period_reference = ?';
            $params[] = $periodReference;
        }
        $conditions[] = $creditCondition . ')';

        $statement = $this->db->queryPrepared(
            'SELECT usr.usr_id, usr.usr_uuid, usr.usr_login_name,
                    first_name.usd_value AS first_name, last_name.usd_value AS last_name
               FROM ' . TBL_USERS . ' AS usr
          LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                 ON first_name.usd_usr_id = usr.usr_id AND first_name.usd_usf_id = ?
          LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                 ON last_name.usd_usr_id = usr.usr_id AND last_name.usd_usf_id = ?
              WHERE (' . implode(' OR ', $conditions) . ')
           ORDER BY last_name.usd_value, first_name.usd_value, usr.usr_id',
            $params
        );

        $candidates = array();
        while ($row = $statement->fetch()) {
            $first = (string) ($row['first_name'] ?? '');
            $last = (string) ($row['last_name'] ?? '');
            $name = trim($last . ', ' . $first, ' ,');

            $candidates[(int) $row['usr_id']] = array(
                'userId' => (int) $row['usr_id'],
                'userUuid' => (string) $row['usr_uuid'],
                'firstName' => $first,
                'lastName' => $last,
                // A profile without either name part would otherwise be an unclickable empty cell.
                'name' => $name === '' ? (string) $row['usr_login_name'] : $name
            );
        }
        return $candidates;
    }

    /**
     * How a period reads once required and attained are known.
     *
     * `fulfilled` / `missing` need a positive target. A target of exactly zero is a real
     * configuration - a rule that says "be enrolled, collect nothing" - so it gets its own
     * `no-credits-required` answer instead of being reported as if no rule applied at all (finding
     * 3.5). Without any applicable rule, credits are `additional-credits` and nothing is `none`.
     */
    private static function coverageStatus(float $required, float $attained, bool $hasRule): string
    {
        if ($required > 0.0) {
            return $attained >= $required ? 'fulfilled' : 'missing';
        }
        if ($hasRule) {
            return 'no-credits-required';
        }
        return $attained > 0.0 ? 'additional-credits' : 'none';
    }

    /**
     * IDs of the requirement types that currently apply to a user: those with at least one role
     * rule for a role the user is a member of right now (membership active today, role category in
     * the current organization).
     *
     * @return array<int,int>
     */
    public function applicableRequirementTypeIds(int $userId): array
    {
        global $gCurrentOrgId;

        $rows = $this->db->queryPrepared(
            'SELECT DISTINCT rrr.rrr_requirement_type_id
               FROM ' . RequirementsTables::requirementRoleRules() . ' AS rrr
         INNER JOIN ' . TBL_MEMBERS . ' AS mem
                 ON mem.mem_rol_id = rrr.rrr_role_id
                AND mem.mem_usr_id = ?
                AND mem.mem_begin <= ?
                AND mem.mem_end >= ?
         INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = rrr.rrr_role_id
         INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
              WHERE cat.cat_org_id = ?',
            array($userId, DATE_NOW, DATE_NOW, (int) $gCurrentOrgId)
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }

    /**
     * The kind of control the credited-period picker should use for a requirement type:
     * year | quarter | month | week for calendar requirements, otherwise a plain date.
     */
    public static function periodControlKind(RequirementType $type): string
    {
        return self::periodControlKindFor(
            (string) $type->getValue('rqt_period_anchor'),
            (string) $type->getValue('rqt_period_unit')
        );
    }

    /**
     * The same answer from the two stored values, without a requirement-type record. Reading a
     * column out of an Entity means a database, and the control kind is a pure decision - so the
     * decision lives here and the entity-facing method above only unpacks the record.
     */
    public static function periodControlKindFor(string $anchor, string $unit): string
    {
        if ($anchor !== 'calendar') {
            return 'date';
        }
        return match ($unit) {
            'year' => 'year',
            'quarter' => 'quarter',
            'month' => 'month',
            'week' => 'week',
            default => 'date'
        };
    }

    /**
     * The value(s) a period control should be pre-filled with for a given Y-m-d reference date.
     *
     * @return array{value:string,quarter:string} value is the control's own value (year, YYYY-MM,
     *         YYYY-Www or a date); quarter is "1".."4" for the quarter control, otherwise "".
     */
    public static function periodControlValue(string $kind, string $referenceYmd): array
    {
        $empty = array('value' => '', 'quarter' => '');
        if ($referenceYmd === '') {
            return $empty;
        }
        try {
            $date = self::date(substr($referenceYmd, 0, 10));
        } catch (Exception) {
            return $empty;
        }
        $year = (int) $date->format('Y');
        if ($year < 1900 || $year > (int) date('Y') + 1) {
            return $empty;
        }

        return match ($kind) {
            'year' => array('value' => $date->format('Y'), 'quarter' => ''),
            'quarter' => array(
                'value' => $date->format('Y'),
                'quarter' => (string) (intdiv((int) $date->format('n') - 1, 3) + 1)
            ),
            'month' => array('value' => $date->format('Y-m'), 'quarter' => ''),
            'week' => array('value' => $date->format('o-\WW'), 'quarter' => ''),
            default => array('value' => $date->format('Y-m-d'), 'quarter' => '')
        };
    }

    /**
     * Turn the value(s) a credited-period control submitted into a Y-m-d date that
     * normalizePeriodReference() can snap to the period start.
     *
     * @param string $raw     the control's own value (year, YYYY-MM, YYYY-Www or a date)
     * @param string $quarter "1".."4" when the requirement uses a quarter control
     * @throws Exception SYS_DATE_INVALID
     */
    public static function periodInputToDate(RequirementType $type, string $raw, string $quarter = ''): string
    {
        return self::periodInputToDateForKind(self::periodControlKind($type), $raw, $quarter);
    }

    /**
     * @throws Exception SYS_DATE_INVALID
     */
    public static function periodInputToDateForKind(string $kind, string $raw, string $quarter = ''): string
    {
        $raw = trim($raw);
        $maxYear = (int) date('Y') + 1;

        switch ($kind) {
            case 'year':
                if (preg_match('/^(\d{4})$/', $raw, $m) === 1) {
                    $year = (int) $m[1];
                    if ($year >= 1900 && $year <= $maxYear) {
                        return $year . '-01-01';
                    }
                }
                break;

            case 'quarter':
                if (preg_match('/^(\d{4})$/', $raw, $m) === 1
                    && preg_match('/^[1-4]$/', $quarter) === 1) {
                    $year = (int) $m[1];
                    if ($year >= 1900 && $year <= $maxYear) {
                        return sprintf('%04d-%02d-01', $year, (((int) $quarter) - 1) * 3 + 1);
                    }
                }
                break;

            case 'month':
                if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1
                    && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
                    return $raw . '-01';
                }
                break;

            case 'week':
                if (preg_match('/^(\d{4})-W(\d{2})$/', $raw, $m) === 1
                    && (int) $m[2] >= 1 && (int) $m[2] <= 53) {
                    return (new DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2], 1)->format('Y-m-d');
                }
                break;

            default:
                $date = self::date($raw);
                if ((int) $date->format('Y') < 1900 || (int) $date->format('Y') > $maxYear) {
                    break;
                }
                return $date->format('Y-m-d');
        }

        throw new Exception('SYS_DATE_INVALID');
    }

    public static function normalizePeriodReference(RequirementType $type, string $reference): string
    {
        $date = self::date($reference);
        if ((string)$type->getValue('rqt_period_anchor') === 'last_fulfilment') {
            return $date->format('Y-m-d');
        }

        [$start] = self::periodFor($type, $date);
        return $start->format('Y-m-d');
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:string}
     */
    public static function periodFor(RequirementType $type, DateTimeImmutable $reference): array
    {
        return self::periodForUnit(
            (string)$type->getValue('rqt_period_unit'),
            (int)$type->getValue('rqt_period_count'),
            $reference
        );
    }

    /**
     * The period that contains $reference, from the two stored values rather than from a record.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:string} start, end (inclusive), label
     * @throws Exception PLG_REQUIREMENTS_CALENDAR_MULTI_PERIOD_UNSUPPORTED for a count above one
     */
    public static function periodForUnit(string $unit, int $count, DateTimeImmutable $reference): array
    {
        if ($count !== 1) {
            throw new Exception('PLG_REQUIREMENTS_CALENDAR_MULTI_PERIOD_UNSUPPORTED');
        }

        switch ($unit) {
            case 'year':
                $start = $reference->setDate((int)$reference->format('Y'), 1, 1);
                $end = $start->modify('+1 year -1 day');
                $label = $start->format('Y');
                break;

            case 'quarter':
                $month = (int)$reference->format('n');
                $quarter = intdiv($month - 1, 3) + 1;
                $startMonth = (($quarter - 1) * 3) + 1;
                $start = $reference->setDate((int)$reference->format('Y'), $startMonth, 1);
                $end = $start->modify('+3 months -1 day');
                $label = 'Q' . $quarter . ' ' . $start->format('Y');
                break;

            case 'month':
                $start = $reference->modify('first day of this month');
                $end = $start->modify('+1 month -1 day');
                $label = $start->format('Y-m');
                break;

            case 'week':
                $start = $reference->modify('monday this week');
                $end = $start->modify('+6 days');
                $label = $start->format('o-\WW');
                break;

            default:
                throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        return array(
            self::date($start->format('Y-m-d')),
            self::date($end->format('Y-m-d')),
            $label
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadRules(RequirementType $type, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        global $gCurrentOrgId;

        $statement = $this->db->queryPrepared(
            'SELECT rrr.*, rol.rol_name
               FROM ' . RequirementsTables::requirementRoleRules() . ' AS rrr
         INNER JOIN ' . TBL_ROLES . ' AS rol
                 ON rol.rol_id = rrr.rrr_role_id
         INNER JOIN ' . TBL_CATEGORIES . ' AS cat
                 ON cat.cat_id = rol.rol_cat_id
              WHERE rrr.rrr_requirement_type_id = ?
                AND cat.cat_org_id = ?
                AND (rrr.rrr_valid_from IS NULL OR rrr.rrr_valid_from <= ?)
                AND (rrr.rrr_valid_until IS NULL OR rrr.rrr_valid_until >= ?)
              ORDER BY rrr.rrr_role_id, rrr.rrr_valid_from, rrr.rrr_id',
            array(
                (int)$type->getValue('rqt_id'),
                (int)$gCurrentOrgId,
                $end->format('Y-m-d'),
                $start->format('Y-m-d')
            )
        );

        return $statement->fetchAll();
    }

    /**
     * @param array<int,int> $roleIds
     * @return array<int,array<int,array{start:string,end:string}>> by role id
     */
    private function loadMemberships(
        int $userId,
        array $roleIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        return $this->membershipsForUsers(array($userId), $roleIds, $start, $end)[$userId] ?? array();
    }

    /**
     * The memberships of several members in the rule roles, in one query.
     *
     * The single-member read above is this query with one id: the review view walks a whole roster
     * and must not ask the database once per member (§9), and two loaders that could drift apart
     * would be two chances to answer the same question differently.
     *
     * @param array<int,int> $userIds
     * @param array<int,int> $roleIds
     * @return array<int,array<int,array<int,array{start:string,end:string}>>> by user id, then role id
     */
    private function membershipsForUsers(
        array $userIds,
        array $roleIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        if ($userIds === array() || $roleIds === array()) {
            return array();
        }

        $params = array_merge(
            array($end->format('Y-m-d'), $start->format('Y-m-d')),
            $userIds,
            $roleIds
        );
        $statement = $this->db->queryPrepared(
            'SELECT mem_usr_id, mem_rol_id, mem_begin, mem_end
               FROM ' . TBL_MEMBERS . '
              WHERE mem_begin <= ?
                AND mem_end >= ?
                AND mem_usr_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')
                AND mem_rol_id IN (' . implode(', ', array_fill(0, count($roleIds), '?')) . ')
              ORDER BY mem_usr_id, mem_rol_id, mem_begin',
            $params
        );

        $memberships = array();
        while ($row = $statement->fetch()) {
            $memberships[(int)$row['mem_usr_id']][(int)$row['mem_rol_id']][] = array(
                'start' => (string)$row['mem_begin'],
                'end' => (string)$row['mem_end']
            );
        }
        return $memberships;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<int,array<int,array{start:string,end:string}>> $memberships
     * @return array{0:float,1:array<int,string>,2:bool} required credits, configuration warnings,
     *         and whether any role rule applied to this user in this period at all - which a
     *         required amount of zero cannot distinguish on its own
     */
    private function calculateRequired(
        RequirementType $type,
        array $rules,
        array $memberships,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd
    ): array {
        return self::calculateRequiredCredits(
            (string)$type->getValue('rqt_multiple_roles_mode'),
            $rules,
            $memberships,
            $periodStart,
            $periodEnd
        );
    }

    /**
     * The obligation of one member for one requirement period.
     *
     * Takes the multiple-roles mode rather than the requirement type, so that the whole calculation
     * is a function of plain rows and dates: the rules as they come out of the database, and the
     * membership intervals as they come out of TBL_MEMBERS. Nothing here needs a record, which is
     * what makes the policy of §6.2, §6.3 and §6.4 testable without a database.
     *
     * @param array<int,array<string,mixed>> $rules rows of req_requirement_role_rules, each with
     *        rrr_role_id, rrr_required_credits, rrr_partial_period_mode, rrr_proration_basis,
     *        rrr_membership_at_period_start, rrr_valid_from, rrr_valid_until and optionally rol_name
     * @param array<int,array<int,array{start:string,end:string}>> $memberships by role id
     * @return array{0:float,1:array<int,string>,2:bool} required credits, configuration warnings,
     *         and whether any role rule applied to this user in this period at all - which a
     *         required amount of zero cannot distinguish on its own
     */
    public static function calculateRequiredCredits(
        string $multipleRolesMode,
        array $rules,
        array $memberships,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd
    ): array {
        global $gL10n;

        $byRole = array();
        foreach ($rules as $rule) {
            $byRole[(int)$rule['rrr_role_id']][] = $rule;
        }

        $roleAmounts = array();
        $warnings = array();

        foreach ($byRole as $roleId => $roleRules) {
            $roleMemberships = $memberships[$roleId] ?? array();
            if ($roleMemberships === array()) {
                continue;
            }

            $fullValues = array();
            $proRata = 0.0;
            $applied = false;
            foreach ($roleRules as $rule) {
                if ((bool)$rule['rrr_membership_at_period_start']
                    && !self::dateInMemberships($periodStart, $roleMemberships)) {
                    continue;
                }

                if (!self::hasQualifyingOverlap($rule, $roleMemberships, $periodStart, $periodEnd)) {
                    continue;
                }

                $applied = true;
                if ((string)$rule['rrr_partial_period_mode'] === 'full') {
                    $fullValues[] = (float)$rule['rrr_required_credits'];
                } else {
                    $fraction = self::prorationFraction(
                        $roleMemberships,
                        $periodStart,
                        $periodEnd,
                        (string)$rule['rrr_proration_basis'],
                        $rule['rrr_valid_from'] === null ? null : (string)$rule['rrr_valid_from'],
                        $rule['rrr_valid_until'] === null ? null : (string)$rule['rrr_valid_until']
                    );
                    $proRata += (float)$rule['rrr_required_credits'] * $fraction;
                }
            }

            // Holding the role is not enough: if every rule of it was gated out or lies outside the
            // period, the role takes no part in the combination below. Contributing a zero would
            // make `minimum` collapse to nothing and would report "a rule applied" when none did.
            if (!$applied) {
                continue;
            }

            $roleName = (string)($roleRules[0]['rol_name'] ?? $roleId);
            if (count($fullValues) > 1) {
                $warnings[] = $gL10n->get('PLG_REQUIREMENTS_WARNING_SEVERAL_FULL_RULES', array($roleName));
            }
            if ($fullValues !== array() && $proRata > 0.0) {
                $warnings[] = $gL10n->get('PLG_REQUIREMENTS_WARNING_MIXED_RULES', array($roleName));
            }

            $full = $fullValues === array() ? 0.0 : max($fullValues);
            $roleAmounts[] = max($full, $proRata);
        }

        if ($roleAmounts === array()) {
            return array(0.0, $warnings, false);
        }

        $required = match ($multipleRolesMode) {
            'sum' => array_sum($roleAmounts),
            'minimum' => min($roleAmounts),
            default => max($roleAmounts)
        };

        return array($required, $warnings, true);
    }

    /**
     * @param array<int,array{start:string,end:string}> $memberships
     */
    public static function prorationFraction(
        array $memberships,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        string $basis,
        ?string $validFrom,
        ?string $validUntil
    ): float {
        $unitStarts = self::prorationUnitStarts($periodStart, $periodEnd, $basis);
        if ($unitStarts === array()) {
            return 0.0;
        }

        $qualifying = 0;
        foreach ($unitStarts as $unitStart) {
            if ($validFrom !== null && $unitStart->format('Y-m-d') < $validFrom) {
                continue;
            }
            if ($validUntil !== null && $unitStart->format('Y-m-d') > $validUntil) {
                continue;
            }
            if (self::dateInMemberships($unitStart, $memberships)) {
                ++$qualifying;
            }
        }

        return $qualifying / count($unitStarts);
    }

    /**
     * @return array<int,DateTimeImmutable>
     */
    public static function prorationUnitStarts(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $basis
    ): array {
        if (!in_array($basis, RequirementTypeService::PRORATION_BASES, true)) {
            throw new \InvalidArgumentException('Unknown proration basis.');
        }

        $starts = array($start);
        $cursor = match ($basis) {
            'day' => $start->add(new DateInterval('P1D')),
            'week' => $start->modify('next monday'),
            'month' => $start->modify('first day of next month')
        };
        $step = match ($basis) {
            'day' => new DateInterval('P1D'),
            'week' => new DateInterval('P7D'),
            'month' => new DateInterval('P1M')
        };

        while ($cursor <= $end) {
            $starts[] = $cursor;
            $cursor = $cursor->add($step);
        }

        return $starts;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<int,array{start:string,end:string}> $memberships
     */
    private static function hasQualifyingOverlap(
        array $rule,
        array $memberships,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd
    ): bool {
        $ruleStart = $periodStart;
        if ($rule['rrr_valid_from'] !== null) {
            $validFrom = self::date((string)$rule['rrr_valid_from']);
            if ($validFrom > $ruleStart) {
                $ruleStart = $validFrom;
            }
        }

        $ruleEnd = $periodEnd;
        if ($rule['rrr_valid_until'] !== null) {
            $validUntil = self::date((string)$rule['rrr_valid_until']);
            if ($validUntil < $ruleEnd) {
                $ruleEnd = $validUntil;
            }
        }

        if ($ruleStart > $ruleEnd) {
            return false;
        }

        foreach ($memberships as $membership) {
            if ((string)$membership['start'] <= $ruleEnd->format('Y-m-d')
                && (string)$membership['end'] >= $ruleStart->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array{start:string,end:string}> $memberships
     */
    public static function dateInMemberships(DateTimeImmutable $date, array $memberships): bool
    {
        $value = $date->format('Y-m-d');
        foreach ($memberships as $membership) {
            if ((string)$membership['start'] <= $value && (string)$membership['end'] >= $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Activity statuses whose credits are never counted towards a requirement.
     *
     * `withdrawn` speaks for itself. `draft` is REQ-DEC-022: a draft only exists under the
     * `until_submitted` policy, which an organization chooses precisely because submitting should
     * mean something - so a member must not be able to watch their own coverage turn green without
     * ever handing the activity over. Draft credits are reported separately as "pending" rather than
     * silently disappearing.
     */
    private const NON_COUNTING_STATUSES = array('withdrawn', 'draft');

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadCredits(int $userId, int $requirementTypeId, string $periodReference): array
    {
        return $this->readCredits($userId, $requirementTypeId, $periodReference);
    }

    /**
     * Every contribution to one requirement, in the order of their credited period.
     *
     * A recurring requirement has no period to filter by - its cycles are found by walking the
     * contributions in order - so this is the same query without the period condition.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadAllCredits(int $userId, int $requirementTypeId): array
    {
        return $this->readCredits($userId, $requirementTypeId, null);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readCredits(int $userId, int $requirementTypeId, ?string $periodReference): array
    {
        return $this->readCreditsForUsers(array($userId), $requirementTypeId, $periodReference)[$userId] ?? array();
    }

    /**
     * The contributions of several members to one requirement, keyed by user id and in the order of
     * their credited period. The single-member read above is this query with one id; see
     * membershipsForUsers() for why the review view cannot afford one query per member.
     *
     * @param array<int,int> $userIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function readCreditsForUsers(
        array $userIds,
        int $requirementTypeId,
        ?string $periodReference
    ): array {
        global $gCurrentOrgId;

        if ($userIds === array()) {
            return array();
        }

        $params = array_merge(
            array((int)$gCurrentOrgId, $requirementTypeId, 'withdrawn'),
            $userIds
        );
        $periodCondition = '';
        if ($periodReference !== null) {
            $periodCondition = ' AND rac.rac_period_reference = ?';
            $params[] = $periodReference;
        }

        $statement = $this->db->queryPrepared(
            'SELECT rqa.rqa_user_id, rqa.rqa_uuid, rqa.rqa_title, rqa.rqa_begin_date, rqa.rqa_status,
                    rac.rac_id, rac.rac_uuid, rac.rac_period_reference,
                    rac.rac_credits_claimed, rac.rac_credits_accepted
               FROM ' . RequirementsTables::activityCredits() . ' AS rac
         INNER JOIN ' . RequirementsTables::activities() . ' AS rqa
                 ON rqa.rqa_id = rac.rac_activity_id
              WHERE rqa.rqa_org_id = ?
                AND rac.rac_requirement_type_id = ?
                AND rqa.rqa_status <> ?
                AND rqa.rqa_user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')'
            . $periodCondition . '
              ORDER BY rqa.rqa_user_id, rac.rac_period_reference, rqa.rqa_begin_date, rqa.rqa_title',
            $params
        );

        $credits = array();
        while ($row = $statement->fetch()) {
            $effective = $row['rac_credits_accepted'] === null
                ? (float)$row['rac_credits_claimed'] : (float)$row['rac_credits_accepted'];
            $credits[(int)$row['rqa_user_id']][] = array(
                'activityUuid' => (string)$row['rqa_uuid'],
                'creditId' => (int)$row['rac_id'],
                'creditUuid' => (string)$row['rac_uuid'],
                'title' => (string)$row['rqa_title'],
                'date' => (string)$row['rqa_begin_date'],
                'periodReference' => (string)$row['rac_period_reference'],
                'status' => (string)$row['rqa_status'],
                'counts' => !in_array((string)$row['rqa_status'], self::NON_COUNTING_STATUSES, true),
                'claimedCredits' => (float)$row['rac_credits_claimed'],
                'acceptedCredits' => $row['rac_credits_accepted'] === null ? null : (float)$row['rac_credits_accepted'],
                'effectiveCredits' => $effective
            );
        }
        return $credits;
    }

    /**
     * Effective credits of the contributions that count, and of those still waiting to be submitted.
     *
     * @param array<int,array<string,mixed>> $credits
     * @return array{0:float,1:float} attained, pending
     */
    private static function sumCredits(array $credits): array
    {
        $attained = 0.0;
        $pending = 0.0;
        foreach ($credits as $credit) {
            if ($credit['counts']) {
                $attained += (float) $credit['effectiveCredits'];
            } else {
                $pending += (float) $credit['effectiveCredits'];
            }
        }
        return array($attained, $pending);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new Exception('SYS_DATE_INVALID');
        }
        return $date;
    }
}
