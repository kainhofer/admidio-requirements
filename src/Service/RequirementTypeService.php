<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Roles\Entity\Role;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use Throwable;

final class RequirementTypeService
{
    public const PERIOD_UNITS = array('year', 'quarter', 'month', 'week');
    public const PERIOD_ANCHORS = array('calendar', 'last_fulfilment');
    public const MULTIPLE_ROLES_MODES = array('maximum', 'minimum', 'sum');
    public const PARTIAL_PERIOD_MODES = array('full', 'pro_rata');
    public const PRORATION_BASES = array('month', 'day', 'week');

    public function __construct(private readonly Database $db)
    {
    }

    public function saveType(RequirementType $type, array $values): RequirementType
    {
        global $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if ($type->isNewRecord()) {
            $type->setValue('rqt_org_id', $gCurrentOrgId);
        } elseif ((int)$type->getValue('rqt_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        foreach (array('rqt_name', 'rqt_short_name', 'rqt_description') as $field) {
            if (array_key_exists($field, $values)) {
                $type->setValue($field, is_string($values[$field]) ? trim($values[$field]) : $values[$field]);
            }
        }

        $unit = (string)($values['rqt_period_unit'] ?? $type->getValue('rqt_period_unit'));
        $anchor = (string)($values['rqt_period_anchor'] ?? ($type->getValue('rqt_period_anchor') ?: 'calendar'));
        $multipleMode = (string)($values['rqt_multiple_roles_mode']
            ?? ($type->getValue('rqt_multiple_roles_mode') ?: 'maximum'));
        $count = (int)($values['rqt_period_count'] ?? ($type->getValue('rqt_period_count') ?: 1));

        if (!in_array($unit, self::PERIOD_UNITS, true)
            || !in_array($anchor, self::PERIOD_ANCHORS, true)
            || !in_array($multipleMode, self::MULTIPLE_ROLES_MODES, true)
            || $count < 1 || $count > 10) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        if ($anchor === 'calendar' && $count !== 1) {
            throw new Exception('PLG_REQUIREMENTS_CALENDAR_MULTI_PERIOD_UNSUPPORTED');
        }

        $type->setValue('rqt_period_unit', $unit);
        $type->setValue('rqt_period_anchor', $anchor);
        $type->setValue('rqt_multiple_roles_mode', $multipleMode);
        $type->setValue('rqt_period_count', $count);
        $type->setValue(
            'rqt_active',
            array_key_exists('rqt_active', $values)
                ? (bool)$values['rqt_active']
                : ($type->isNewRecord() ? true : (bool)$type->getValue('rqt_active'))
        );
        $type->setValue('rqt_sequence', max(0, (int)($values['rqt_sequence'] ?? $type->getValue('rqt_sequence'))));

        if (trim((string)$type->getValue('rqt_name', 'database')) === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_NAME'));
        }

        $shortName = trim((string)$type->getValue('rqt_short_name', 'database'));
        if ($shortName !== '') {
            $duplicates = (int)$this->db->queryPrepared(
                'SELECT COUNT(*)
                   FROM ' . RequirementsTables::requirementTypes() . '
                  WHERE rqt_org_id = ?
                    AND rqt_short_name = ?
                    AND rqt_id <> ?',
                array((int)$gCurrentOrgId, $shortName, (int)$type->getValue('rqt_id'))
            )->fetchColumn();
            if ($duplicates > 0) {
                throw new Exception('PLG_REQUIREMENTS_REQUIREMENT_SHORT_NAME_DUPLICATE');
            }
        }

        $this->db->startTransaction();
        try {
            $type->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $type;
    }

    public function saveRule(RequirementRoleRule $rule, RequirementType $type, array $values): RequirementRoleRule
    {
        global $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if ((int)$type->getValue('rqt_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if (!$rule->isNewRecord()) {
            $currentRuleOrg = (int)$this->db->queryPrepared(
                'SELECT rqt_org_id
                   FROM ' . RequirementsTables::requirementRoleRules() . '
             INNER JOIN ' . RequirementsTables::requirementTypes() . '
                     ON rqt_id = rrr_requirement_type_id
                  WHERE rrr_id = ?',
                array((int)$rule->getValue('rrr_id'))
            )->fetchColumn();
            if ($currentRuleOrg !== (int)$gCurrentOrgId) {
                throw new Exception('SYS_NO_RIGHTS');
            }
        }

        $roleId = (int)($values['rrr_role_id'] ?? $rule->getValue('rrr_role_id'));
        $role = new Role($this->db, $roleId);
        if ((int)$role->getValue('rol_id') === 0 || (int)$role->getValue('cat_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $mode = (string)($values['rrr_partial_period_mode'] ?? ($rule->getValue('rrr_partial_period_mode') ?: 'pro_rata'));
        $basis = (string)($values['rrr_proration_basis']
            ?? ($rule->getValue('rrr_proration_basis')
                ?: RequirementsPlugin::setting(RequirementsPlugin::SETTING_PRORATION_BASIS_DEFAULT)
                ?: 'month'));

        if (!in_array($mode, self::PARTIAL_PERIOD_MODES, true) || !in_array($basis, self::PRORATION_BASES, true)) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $credits = (float)($values['rrr_required_credits'] ?? $rule->getValue('rrr_required_credits'));
        if ($credits < 0) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $validFrom = $this->normalizeOptionalDate($values['rrr_valid_from'] ?? $rule->getValue('rrr_valid_from'));
        $validUntil = $this->normalizeOptionalDate($values['rrr_valid_until'] ?? $rule->getValue('rrr_valid_until'));
        if ($validFrom !== null && $validUntil !== null && $validFrom > $validUntil) {
            throw new Exception('PLG_REQUIREMENTS_INVALID_VALIDITY_PERIOD');
        }

        $rule->setValue('rrr_requirement_type_id', (int)$type->getValue('rqt_id'));
        $rule->setValue('rrr_role_id', $roleId);
        $rule->setValue('rrr_required_credits', number_format($credits, 2, '.', ''));
        $rule->setValue('rrr_partial_period_mode', $mode);
        $rule->setValue('rrr_membership_at_period_start', (bool)($values['rrr_membership_at_period_start']
            ?? $rule->getValue('rrr_membership_at_period_start')));
        $rule->setValue('rrr_proration_basis', $basis);
        $rule->setValue('rrr_valid_from', $validFrom);
        $rule->setValue('rrr_valid_until', $validUntil);

        $this->assertNoOverlappingRule($rule);

        $this->db->startTransaction();
        try {
            $rule->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $rule;
    }

    /**
     * All requirement types of the current organization, ordered for display.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findTypes(): array
    {
        global $gCurrentOrgId;

        return $this->db->getArrayFromSql(
            'SELECT rqt_id, rqt_uuid, rqt_name, rqt_short_name, rqt_description,
                    rqt_period_unit, rqt_period_count, rqt_period_anchor,
                    rqt_multiple_roles_mode, rqt_active, rqt_sequence
               FROM ' . RequirementsTables::requirementTypes() . '
              WHERE rqt_org_id = ?
              ORDER BY rqt_sequence, rqt_name',
            array((int) $gCurrentOrgId)
        );
    }

    /**
     * Role rules, optionally restricted to one requirement type, with the role name joined.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findRules(?int $requirementTypeId = null): array
    {
        global $gCurrentOrgId;

        $sql = 'SELECT rrr.rrr_id, rrr.rrr_uuid, rrr.rrr_requirement_type_id, rrr.rrr_role_id,
                       rrr.rrr_required_credits, rrr.rrr_partial_period_mode,
                       rrr.rrr_membership_at_period_start, rrr.rrr_proration_basis,
                       rrr.rrr_valid_from, rrr.rrr_valid_until,
                       rol.rol_name AS role_name, rqt.rqt_name AS requirement_name,
                       rqt.rqt_uuid AS requirement_uuid
                  FROM ' . RequirementsTables::requirementRoleRules() . ' AS rrr
            INNER JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt
                    ON rqt.rqt_id = rrr.rrr_requirement_type_id
            INNER JOIN ' . TBL_ROLES . ' AS rol
                    ON rol.rol_id = rrr.rrr_role_id
                 WHERE rqt.rqt_org_id = ?';
        $params = array((int) $gCurrentOrgId);

        if ($requirementTypeId !== null) {
            $sql .= ' AND rrr.rrr_requirement_type_id = ?';
            $params[] = $requirementTypeId;
        }
        $sql .= ' ORDER BY rqt.rqt_sequence, rqt.rqt_name, rol.rol_name, rrr.rrr_valid_from';

        return $this->db->getArrayFromSql($sql, $params);
    }

    /**
     * Physically delete a requirement type. Role rules cascade; credits block the delete.
     *
     * @throws Exception
     */
    public function deleteType(RequirementType $type): void
    {
        global $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID)
            || (int) $type->getValue('rqt_id') === 0
            || (int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->db->startTransaction();
        try {
            $type->delete();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * Delete a single role rule after verifying its requirement type belongs to the current org.
     *
     * @throws Exception
     */
    public function deleteRule(RequirementRoleRule $rule): void
    {
        global $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID) || (int) $rule->getValue('rrr_id') === 0) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $ruleOrg = (int) $this->db->queryPrepared(
            'SELECT rqt_org_id
               FROM ' . RequirementsTables::requirementRoleRules() . '
         INNER JOIN ' . RequirementsTables::requirementTypes() . '
                 ON rqt_id = rrr_requirement_type_id
              WHERE rrr_id = ?',
            array((int) $rule->getValue('rrr_id'))
        )->fetchColumn();
        if ($ruleOrg !== (int) $gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->db->startTransaction();
        try {
            $rule->delete();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    private function assertNoOverlappingRule(RequirementRoleRule $rule): void
    {
        $from = $rule->getValue('rrr_valid_from', 'database');
        $until = $rule->getValue('rrr_valid_until', 'database');

        $sql = 'SELECT COUNT(*)
                  FROM ' . RequirementsTables::requirementRoleRules() . '
                 WHERE rrr_requirement_type_id = ?
                   AND rrr_role_id = ?
                   AND rrr_id <> ?
                   AND (rrr_valid_until IS NULL OR ? IS NULL OR rrr_valid_until >= ?)
                   AND (rrr_valid_from IS NULL OR ? IS NULL OR rrr_valid_from <= ?)';

        $params = array(
            (int)$rule->getValue('rrr_requirement_type_id'),
            (int)$rule->getValue('rrr_role_id'),
            (int)$rule->getValue('rrr_id'),
            $from,
            $from,
            $until,
            $until
        );

        if ((int)$this->db->queryPrepared($sql, $params)->fetchColumn() > 0) {
            throw new Exception('PLG_REQUIREMENTS_RULE_VALIDITY_OVERLAP');
        }
    }

    private function normalizeOptionalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        if ($date === false || $date->format('Y-m-d') !== (string)$value) {
            throw new Exception('SYS_DATE_INVALID');
        }

        return $date->format('Y-m-d');
    }
}
