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
        global $gCurrentOrgId, $gCurrentUser, $gValidLogin;

        if (!$gValidLogin
            || !Component::isVisible(RequirementsPlugin::ID)
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
                $result[] = array(
                    'requirement' => (string)$type->getValue('rqt_name'),
                    'shortName' => (string)$type->getValue('rqt_short_name'),
                    'period' => null,
                    'attained' => null,
                    'required' => null,
                    'coverage' => null,
                    'status' => 'recurring-not-calculated',
                    'warnings' => array('last_fulfilment calculation is not implemented in this increment.'),
                    'activities' => array()
                );
                continue;
            }

            [$periodStart, $periodEnd, $label] = self::periodFor($type, $reference);
            $rules = $this->loadRules($type, $periodStart, $periodEnd);
            $roleIds = array_values(array_unique(array_map(
                static fn(array $rule): int => (int)$rule['rrr_role_id'],
                $rules
            )));
            $memberships = $this->loadMemberships($userId, $roleIds, $periodStart, $periodEnd);

            [$required, $warnings] = $this->calculateRequired(
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
            $attained = array_sum(array_column($credits, 'effectiveCredits'));
            if ($required <= 0.0 && $credits === array()) {
                continue;
            }

            $coverage = $required > 0.0 ? min(100.0, ($attained / $required) * 100.0) : null;

            $result[] = array(
                'requirement' => (string)$type->getValue('rqt_name'),
                'shortName' => (string)$type->getValue('rqt_short_name'),
                'period' => $label,
                'periodReference' => $periodStart->format('Y-m-d'),
                'attained' => round($attained, 2),
                'required' => $required > 0.0 ? round($required, 2) : null,
                'coverage' => $coverage === null ? null : round($coverage, 1),
                'status' => $required <= 0.0 ? 'additional-credits' : ($attained >= $required ? 'fulfilled' : 'missing'),
                'warnings' => $warnings,
                'activities' => $credits
            );
        }

        return $result;
    }

    /**
     * Fulfilment of every yearly calendar requirement for the current year and the $previousYears
     * years before it. Used by the overview widget on top of the activity list.
     *
     * @return array<int,array{requirement:string,shortName:string,periods:array<int,array<string,mixed>>}>
     * @throws Exception
     */
    public function coverageHistoryForUser(int $userId, int $previousYears = 4): array
    {
        $previousYears = max(0, min(20, $previousYears));
        $currentYear = (int) date('Y');

        /** @var array<string,array{requirement:string,shortName:string,periods:array<string,array<string,mixed>>}> $byRequirement */
        $byRequirement = array();

        for ($year = $currentYear - $previousYears; $year <= $currentYear; ++$year) {
            foreach ($this->coverageForUser($userId, $year . '-06-15') as $entry) {
                if (($entry['period'] ?? null) !== (string) $year) {
                    // Only yearly calendar requirements produce a bare-year period label.
                    continue;
                }

                $key = $entry['shortName'] !== '' ? $entry['shortName'] : $entry['requirement'];
                if (!array_key_exists($key, $byRequirement)) {
                    $byRequirement[$key] = array(
                        'requirement' => $entry['requirement'],
                        'shortName' => $entry['shortName'],
                        'periods' => array()
                    );
                }

                $required = $entry['required'] === null ? 0.0 : (float) $entry['required'];
                $attained = (float) $entry['attained'];
                $byRequirement[$key]['periods'][(string) $year] = array(
                    'label' => (string) $year,
                    'attained' => round($attained, 2),
                    'required' => $required > 0.0 ? round($required, 2) : null,
                    'percent' => $required > 0.0 ? (int) round(min(100.0, ($attained / $required) * 100.0)) : null,
                    'status' => $entry['status'],
                    'warnings' => $entry['warnings']
                );
            }
        }

        $result = array();
        foreach ($byRequirement as $requirement) {
            $periods = array();
            for ($year = $currentYear - $previousYears; $year <= $currentYear; ++$year) {
                $periods[] = $requirement['periods'][(string) $year] ?? array(
                    'label' => (string) $year,
                    'attained' => 0.0,
                    'required' => null,
                    'percent' => null,
                    'status' => 'none',
                    'warnings' => array()
                );
            }
            $result[] = array(
                'requirement' => $requirement['requirement'],
                'shortName' => $requirement['shortName'],
                'periods' => $periods
            );
        }

        usort($result, static fn(array $a, array $b): int => strcasecmp($a['requirement'], $b['requirement']));
        return $result;
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
        $count = (int)$type->getValue('rqt_period_count');
        if ($count !== 1) {
            throw new Exception('PLG_REQUIREMENTS_CALENDAR_MULTI_PERIOD_UNSUPPORTED');
        }

        $unit = (string)$type->getValue('rqt_period_unit');
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
            'SELECT rrr.*
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
     * @return array<int,array<int,array{start:string,end:string}>>
     */
    private function loadMemberships(
        int $userId,
        array $roleIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        if ($roleIds === array()) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $params = array_merge(
            array($userId, $end->format('Y-m-d'), $start->format('Y-m-d')),
            $roleIds
        );
        $statement = $this->db->queryPrepared(
            'SELECT mem_rol_id, mem_begin, mem_end
               FROM ' . TBL_MEMBERS . '
              WHERE mem_usr_id = ?
                AND mem_begin <= ?
                AND mem_end >= ?
                AND mem_rol_id IN (' . $placeholders . ')
              ORDER BY mem_rol_id, mem_begin',
            $params
        );

        $memberships = array();
        while ($row = $statement->fetch()) {
            $memberships[(int)$row['mem_rol_id']][] = array(
                'start' => (string)$row['mem_begin'],
                'end' => (string)$row['mem_end']
            );
        }
        return $memberships;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<int,array<int,array{start:string,end:string}>> $memberships
     * @return array{0:float,1:array<int,string>}
     */
    private function calculateRequired(
        RequirementType $type,
        array $rules,
        array $memberships,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd
    ): array {
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
            foreach ($roleRules as $rule) {
                if ((bool)$rule['rrr_membership_at_period_start']
                    && !$this->dateInMemberships($periodStart, $roleMemberships)) {
                    continue;
                }

                if (!$this->hasQualifyingOverlap($rule, $roleMemberships, $periodStart, $periodEnd)) {
                    continue;
                }

                if ((string)$rule['rrr_partial_period_mode'] === 'full') {
                    $fullValues[] = (float)$rule['rrr_required_credits'];
                } else {
                    $fraction = $this->prorationFraction(
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

            if (count($fullValues) > 1) {
                $warnings[] = 'Role ' . $roleId . ' has several full rules in this period; highest value used.';
            }
            if ($fullValues !== array() && $proRata > 0.0) {
                $warnings[] = 'Role ' . $roleId . ' mixes full and pro-rata rules in this period; highest resulting obligation used.';
            }

            $full = $fullValues === array() ? 0.0 : max($fullValues);
            $roleAmounts[] = max($full, $proRata);
        }

        if ($roleAmounts === array()) {
            return array(0.0, $warnings);
        }

        $mode = (string)$type->getValue('rqt_multiple_roles_mode');
        $required = match ($mode) {
            'sum' => array_sum($roleAmounts),
            'minimum' => min($roleAmounts),
            default => max($roleAmounts)
        };

        return array($required, $warnings);
    }

    /**
     * @param array<int,array{start:string,end:string}> $memberships
     */
    private function prorationFraction(
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
            if ($this->dateInMemberships($unitStart, $memberships)) {
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
    private function hasQualifyingOverlap(
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
    private function dateInMemberships(DateTimeImmutable $date, array $memberships): bool
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
     * @return array<int,array<string,mixed>>
     */
    private function loadCredits(int $userId, int $requirementTypeId, string $periodReference): array
    {
        global $gCurrentOrgId;

        $statement = $this->db->queryPrepared(
            'SELECT rqa.rqa_uuid, rqa.rqa_title, rqa.rqa_begin_date,
                    rac.rac_uuid, rac.rac_credits_claimed, rac.rac_credits_accepted
               FROM ' . RequirementsTables::activityCredits() . ' AS rac
         INNER JOIN ' . RequirementsTables::activities() . ' AS rqa
                 ON rqa.rqa_id = rac.rac_activity_id
              WHERE rqa.rqa_org_id = ?
                AND rqa.rqa_user_id = ?
                AND rac.rac_requirement_type_id = ?
                AND rac.rac_period_reference = ?
                AND rqa.rqa_status <> ?
              ORDER BY rqa.rqa_begin_date, rqa.rqa_title',
            array((int)$gCurrentOrgId, $userId, $requirementTypeId, $periodReference, 'withdrawn')
        );

        $credits = array();
        while ($row = $statement->fetch()) {
            $effective = $row['rac_credits_accepted'] === null
                ? (float)$row['rac_credits_claimed'] : (float)$row['rac_credits_accepted'];
            $credits[] = array(
                'activityUuid' => (string)$row['rqa_uuid'],
                'creditUuid' => (string)$row['rac_uuid'],
                'title' => (string)$row['rqa_title'],
                'date' => (string)$row['rqa_begin_date'],
                'claimedCredits' => (float)$row['rac_credits_claimed'],
                'acceptedCredits' => $row['rac_credits_accepted'] === null ? null : (float)$row['rac_credits_accepted'],
                'effectiveCredits' => $effective
            );
        }
        return $credits;
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
