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
                'typeId' => (int)$type->getValue('rqt_id'),
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

        if (!$gValidLogin
            || !Component::isVisible(RequirementsPlugin::ID)
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
              WHERE rqt_org_id = ? AND rqt_period_anchor = ?
              ORDER BY rqt_sequence, rqt_name',
            array((int) $gCurrentOrgId, 'calendar')
        );

        $today = self::date(DATE_NOW);
        $result = array();

        foreach ($rows as $row) {
            $type = new RequirementType($this->db);
            $type->setArray($row);
            $typeId = (int) $type->getValue('rqt_id');

            if (!in_array($typeId, $applicable, true) || (int) $type->getValue('rqt_period_count') !== 1) {
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

        [$required, $warnings] = $this->calculateRequired($type, $rules, $memberships, $periodStart, $periodEnd);

        $credits = $this->loadCredits($userId, (int) $type->getValue('rqt_id'), $periodStart->format('Y-m-d'));
        $attained = (float) array_sum(array_column($credits, 'effectiveCredits'));

        if ($required > 0.0) {
            $status = $attained >= $required ? 'fulfilled' : 'missing';
        } else {
            $status = $attained > 0.0 ? 'additional-credits' : 'none';
        }

        return array(
            'label' => $label,
            'periodReference' => $periodStart->format('Y-m-d'),
            'attained' => round($attained, 2),
            'required' => $required > 0.0 ? round($required, 2) : null,
            'percent' => $required > 0.0 ? (int) round(min(100.0, ($attained / $required) * 100.0)) : null,
            'status' => $status,
            'warnings' => $warnings
        );
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
        if ((string) $type->getValue('rqt_period_anchor') !== 'calendar') {
            return 'date';
        }
        return match ((string) $type->getValue('rqt_period_unit')) {
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
        $raw = trim($raw);
        $maxYear = (int) date('Y') + 1;
        $kind = self::periodControlKind($type);

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
