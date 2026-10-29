<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
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

        // A member's own requirement is theirs alone; administration does not reach into it.
        if ($type->isNewRecord()) {
            $type->setValue('rqt_org_id', $gCurrentOrgId);
        } elseif ((int)$type->getValue('rqt_org_id') !== (int)$gCurrentOrgId || self::ownerOf($type) !== null) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->applyTypeValues($type, $values);

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

    /**
     * The member a requirement type belongs to, or null for a requirement of the organization.
     */
    public static function ownerOf(RequirementType $type): ?int
    {
        $owner = (int) $type->getValue('rqt_usr_id');
        return $owner > 0 ? $owner : null;
    }

    /**
     * Validate and set the fields of a requirement type, for the organization's requirements and a
     * member's own alike. The short name has to be unique among the requirements the owner sees:
     * an organization's among the organization's, a member's among those and their own.
     *
     * @throws Exception
     */
    private function applyTypeValues(RequirementType $type, array $values): void
    {
        global $gCurrentOrgId;

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
            $owner = self::ownerOf($type);
            $duplicates = (int)$this->db->queryPrepared(
                'SELECT COUNT(*)
                   FROM ' . RequirementsTables::requirementTypes() . '
                  WHERE rqt_org_id = ?
                    AND rqt_short_name = ?
                    AND rqt_id <> ?
                    AND (rqt_usr_id IS NULL OR rqt_usr_id = ?)',
                array((int)$gCurrentOrgId, $shortName, (int)$type->getValue('rqt_id'), $owner ?? 0)
            )->fetchColumn();
            if ($duplicates > 0) {
                throw new Exception('PLG_REQUIREMENTS_REQUIREMENT_SHORT_NAME_DUPLICATE');
            }
        }
    }

    public function saveRule(RequirementRoleRule $rule, RequirementType $type, array $values): RequirementRoleRule
    {
        global $gCurrentOrgId;

        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if ((int)$type->getValue('rqt_org_id') !== (int)$gCurrentOrgId || self::ownerOf($type) !== null) {
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

        // A member's own target is theirs to change, like a requirement of their own.
        if ((int)$rule->getValue('rrr_usr_id') > 0) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $roleId = (int)($values['rrr_role_id'] ?? $rule->getValue('rrr_role_id'));
        $role = new Role($this->db, $roleId);
        if ((int)$role->getValue('rol_id') === 0 || (int)$role->getValue('cat_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->applyRuleValues($rule, $type, $roleId, null, $values);

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
     * Validate and set the fields of a rule: for the holders of $roleId, or - with $userId instead -
     * a member's own target, for a requirement of their own or one of the organization's.
     *
     * @throws Exception
     */
    private function applyRuleValues(
        RequirementRoleRule $rule,
        RequirementType $type,
        ?int $roleId,
        ?int $userId,
        array $values
    ): void {
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
        $rule->setValue('rrr_usr_id', $userId);
        $rule->setValue('rrr_required_credits', number_format($credits, 2, '.', ''));
        $rule->setValue('rrr_partial_period_mode', $mode);
        $rule->setValue('rrr_membership_at_period_start', (bool)($values['rrr_membership_at_period_start']
            ?? $rule->getValue('rrr_membership_at_period_start')));
        $rule->setValue('rrr_proration_basis', $basis);
        $rule->setValue('rrr_valid_from', $validFrom);
        $rule->setValue('rrr_valid_until', $validUntil);

        $this->assertNoOverlappingRule($rule);
    }

    /**
     * The requirement types of the current organization, ordered for display, and with $ownerId
     * also that member's own requirements after them. Nobody else's own requirements are ever
     * part of the answer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findTypes(string $query = '', ?int $ownerId = null): array
    {
        global $gCurrentOrgId;

        $sql = 'SELECT rqt_id, rqt_uuid, rqt_usr_id, rqt_name, rqt_short_name, rqt_description,
                       rqt_period_unit, rqt_period_count, rqt_period_anchor,
                       rqt_multiple_roles_mode, rqt_active, rqt_sequence
                  FROM ' . RequirementsTables::requirementTypes() . '
                 WHERE rqt_org_id = ?
                   AND (rqt_usr_id IS NULL OR rqt_usr_id = ?)';
        $params = array((int) $gCurrentOrgId, $ownerId ?? 0);

        if ($query !== '') {
            $sql .= ' AND (rqt_name LIKE ? OR rqt_short_name LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }
        // rqt_id last, so that two types with the same sequence and name keep a stable order.
        $sql .= ' ORDER BY CASE WHEN rqt_usr_id IS NULL THEN 0 ELSE 1 END, rqt_sequence, rqt_name, rqt_id';

        return $this->db->getArrayFromSql($sql, $params);
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
                 WHERE rqt.rqt_org_id = ?
                   AND rqt.rqt_usr_id IS NULL';
        $params = array((int) $gCurrentOrgId);

        if ($requirementTypeId !== null) {
            $sql .= ' AND rrr.rrr_requirement_type_id = ?';
            $params[] = $requirementTypeId;
        }
        $sql .= ' ORDER BY rqt.rqt_sequence, rqt.rqt_name, rol.rol_name, rrr.rrr_valid_from';

        // System roles such as the board store a translation key (INS_BOARD) as their name.
        return array_map(static function (array $rule): array {
            $rule['role_name'] = Language::translateIfTranslationStrId((string) $rule['role_name']);
            return $rule;
        }, $this->db->getArrayFromSql($sql, $params));
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
            || (int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId
            || self::ownerOf($type) !== null) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->deleteTypeRecord($type);
    }

    /**
     * Delete a requirement type that the caller may delete. Rules cascade, credits block it.
     *
     * @throws Exception
     */
    private function deleteTypeRecord(RequirementType $type): void
    {
        // rac_requirement_type_id is ON DELETE RESTRICT, so the database would refuse this anyway -
        // but with a driver error instead of a sentence that says which records are in the way.
        $credits = (int) $this->db->queryPrepared(
            'SELECT COUNT(*)
               FROM ' . RequirementsTables::activityCredits() . '
              WHERE rac_requirement_type_id = ?',
            array((int) $type->getValue('rqt_id'))
        )->fetchColumn();
        if ($credits > 0) {
            throw new Exception('PLG_REQUIREMENTS_REQUIREMENT_HAS_CREDITS', array($credits));
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
              WHERE rrr_id = ?
                AND rqt_usr_id IS NULL
                AND rrr_role_id IS NOT NULL',
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

    /**
     * The rules of the organization's requirements for the roles a member holds today - what the
     * organization asks of them, which is all of the organization's requirements a member is shown.
     *
     * @return array<int,array<string,mixed>> rows as findRules() returns them
     */
    public function findRulesForMember(int $userId): array
    {
        global $gCurrentOrgId;

        $rules = $this->db->getArrayFromSql(
            'SELECT rrr.rrr_id, rrr.rrr_uuid, rrr.rrr_requirement_type_id, rrr.rrr_role_id,
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
         INNER JOIN ' . TBL_MEMBERS . ' AS mem
                 ON mem.mem_rol_id = rrr.rrr_role_id
                AND mem.mem_usr_id = ?
                AND mem.mem_begin <= ?
                AND mem.mem_end >= ?
              WHERE rqt.rqt_org_id = ?
                AND rqt.rqt_usr_id IS NULL
                AND rqt.rqt_active = true
                AND (rrr.rrr_valid_until IS NULL OR rrr.rrr_valid_until >= ?)
              ORDER BY rqt.rqt_sequence, rqt.rqt_name, rol.rol_name, rrr.rrr_valid_from',
            array($userId, DATE_NOW, DATE_NOW, (int) $gCurrentOrgId, DATE_NOW)
        );

        return array_map(static function (array $rule): array {
            $rule['role_name'] = Language::translateIfTranslationStrId((string) $rule['role_name']);
            return $rule;
        }, $rules);
    }

    /**
     * The requirements a member tracks for themselves, each with the target of its rule.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findOwnTypes(int $userId): array
    {
        global $gCurrentOrgId;

        return $this->db->getArrayFromSql(
            'SELECT rqt.*, rrr.rrr_uuid, rrr.rrr_required_credits, rrr.rrr_partial_period_mode,
                    rrr.rrr_valid_from, rrr.rrr_valid_until
               FROM ' . RequirementsTables::requirementTypes() . ' AS rqt
          LEFT JOIN ' . RequirementsTables::requirementRoleRules() . ' AS rrr
                 ON rrr.rrr_requirement_type_id = rqt.rqt_id
              WHERE rqt.rqt_org_id = ?
                AND rqt.rqt_usr_id = ?
              ORDER BY rqt.rqt_name, rqt.rqt_id',
            array((int) $gCurrentOrgId, $userId)
        );
    }

    /**
     * A member's own target for a requirement - the one rule of a requirement of their own, or
     * their target for one of the organization's - or a new rule if there is none yet.
     */
    public function ownRule(RequirementType $type, int $userId): RequirementRoleRule
    {
        $rule = new RequirementRoleRule($this->db);
        if ((int) $type->getValue('rqt_id') > 0) {
            $rule->readDataByColumns(array(
                'rrr_requirement_type_id' => (int) $type->getValue('rqt_id'),
                'rrr_usr_id' => $userId
            ));
        }
        return $rule;
    }

    /**
     * The member's own targets for requirements of the organization, with the requirement.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findOwnTargets(int $userId): array
    {
        global $gCurrentOrgId;

        return $this->db->getArrayFromSql(
            'SELECT rqt.*, rrr.rrr_uuid, rrr.rrr_required_credits, rrr.rrr_partial_period_mode,
                    rrr.rrr_valid_from, rrr.rrr_valid_until
               FROM ' . RequirementsTables::requirementRoleRules() . ' AS rrr
         INNER JOIN ' . RequirementsTables::requirementTypes() . ' AS rqt
                 ON rqt.rqt_id = rrr.rrr_requirement_type_id
              WHERE rqt.rqt_org_id = ?
                AND rqt.rqt_usr_id IS NULL
                AND rrr.rrr_usr_id = ?
              ORDER BY rqt.rqt_sequence, rqt.rqt_name, rqt.rqt_id',
            array((int) $gCurrentOrgId, $userId)
        );
    }

    /**
     * Save the current member's own target for a requirement of the organization: one that does
     * not apply to them through a role - an insurance-distribution obligation of their employer,
     * say - but that they want to track against the credits the organization books to it.
     *
     * The target is a rule for the member instead of a role, so it is combined with any role rule
     * of the requirement by its multiple-roles mode, as if it were one more role. Nobody else sees
     * it: the administration and the review deal in role rules only.
     *
     * @param array<string,mixed> $values rrr_* fields
     * @throws Exception
     */
    public function saveOwnTarget(RequirementRoleRule $rule, RequirementType $type, array $values): RequirementRoleRule
    {
        global $gCurrentOrgId, $gCurrentUser;

        $userId = (int) $gCurrentUser->getValue('usr_id');
        if (!RequirementsPlugin::isUsableByCurrentUser()
            || (int) $type->getValue('rqt_id') === 0
            || (int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId
            || self::ownerOf($type) !== null
            || (!$rule->isNewRecord() && (
                (int) $rule->getValue('rrr_usr_id') !== $userId
                || (int) $rule->getValue('rrr_requirement_type_id') !== (int) $type->getValue('rqt_id')
            ))) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if ($rule->isNewRecord() && !(bool) $type->getValue('rqt_active')) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        if ((string) ($values['rrr_valid_from'] ?? '') === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('PLG_REQUIREMENTS_OWN_SINCE'));
        }

        $this->applyRuleValues($rule, $type, null, $userId, $values);

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
     * Delete one of the current member's own targets for a requirement of the organization.
     *
     * @throws Exception
     */
    public function deleteOwnTarget(RequirementRoleRule $rule): void
    {
        global $gCurrentUser;

        if (!RequirementsPlugin::isUsableByCurrentUser()
            || (int) $rule->getValue('rrr_id') === 0
            || (int) $rule->getValue('rrr_usr_id') !== (int) $gCurrentUser->getValue('usr_id')) {
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

    /**
     * Save a requirement the current member tracks for themselves - a company or licence obligation
     * the organization does not set - together with its target.
     *
     * It is an ordinary requirement type with the member as owner, and its target an ordinary rule
     * without a role, so every calculation treats it the way it treats the organization's. The rule
     * applies from `rrr_valid_from`, which is required here: it is where the member's obligation
     * begins, and without it the overview could page back without end.
     *
     * @param array<string,mixed> $values rqt_* fields of the type and rrr_* fields of its rule
     * @throws Exception
     */
    public function saveOwnType(RequirementType $type, array $values): RequirementType
    {
        global $gCurrentOrgId, $gCurrentUser;

        $userId = (int) $gCurrentUser->getValue('usr_id');
        if (!RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if ($type->isNewRecord()) {
            $type->setValue('rqt_org_id', $gCurrentOrgId);
            $type->setValue('rqt_usr_id', $userId);
        } elseif ((int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId || self::ownerOf($type) !== $userId) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if ((string) ($values['rrr_valid_from'] ?? '') === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('PLG_REQUIREMENTS_OWN_SINCE'));
        }

        // One role is all there is, so the way several roles combine does not arise.
        $values['rqt_multiple_roles_mode'] = 'maximum';
        $this->applyTypeValues($type, $values);

        $this->db->startTransaction();
        try {
            $type->save();
            $rule = $this->ownRule($type, $userId);
            $this->applyRuleValues($rule, $type, null, $userId, $values);
            $rule->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $type;
    }

    /**
     * Delete one of the current member's own requirements. As with the organization's, a
     * requirement that credits were booked to stays until they are moved or removed.
     *
     * @throws Exception
     */
    public function deleteOwnType(RequirementType $type): void
    {
        global $gCurrentOrgId, $gCurrentUser;

        if (!RequirementsPlugin::isUsableByCurrentUser()
            || (int) $type->getValue('rqt_id') === 0
            || (int) $type->getValue('rqt_org_id') !== (int) $gCurrentOrgId
            || self::ownerOf($type) !== (int) $gCurrentUser->getValue('usr_id')) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->deleteTypeRecord($type);
    }

    private function assertNoOverlappingRule(RequirementRoleRule $rule): void
    {
        $from = $rule->getValue('rrr_valid_from', 'database');
        $until = $rule->getValue('rrr_valid_until', 'database');

        $sql = 'SELECT COUNT(*)
                  FROM ' . RequirementsTables::requirementRoleRules() . '
                 WHERE rrr_requirement_type_id = ?
                   AND COALESCE(rrr_role_id, 0) = ?
                   AND COALESCE(rrr_usr_id, 0) = ?
                   AND rrr_id <> ?
                   AND (rrr_valid_until IS NULL OR ? IS NULL OR rrr_valid_until >= ?)
                   AND (rrr_valid_from IS NULL OR ? IS NULL OR rrr_valid_from <= ?)';

        $params = array(
            (int)$rule->getValue('rrr_requirement_type_id'),
            (int)$rule->getValue('rrr_role_id'),
            (int)$rule->getValue('rrr_usr_id'),
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
