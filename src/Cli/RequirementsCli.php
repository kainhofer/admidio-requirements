<?php
namespace AdmidioPlugin\Requirements\Cli;

use Admidio\Infrastructure\Cli\CliApplication;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ProviderService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use InvalidArgumentException;
use Throwable;

final class RequirementsCli
{
    public static function providerList(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId, $gCurrentUser;

        $sql = 'SELECT rqp_uuid AS uuid, rqp_name AS name, rqp_short_name AS short_name,
                       rqp_public AS public, rqp_qualified AS qualified
                  FROM ' . RequirementsTables::providers() . '
                 WHERE rqp_org_id = ?';
        $params = array((int)$gCurrentOrgId);
        if (!$gCurrentUser->isAdministrator()) {
            $sql .= ' AND (rqp_public = true OR rqp_usr_id_create = ?)';
            $params[] = (int)$gCurrentUser->getValue('usr_id');
        }
        $sql .= ' ORDER BY rqp_qualified DESC, rqp_name';

        CliApplication::writeRows(
            $gDb->queryPrepared($sql, $params)->fetchAll(),
            CliApplication::optionString($options, 'format', 'table'),
            $options
        );
        return 0;
    }

    public static function providerAdd(array $arguments, array $options): int
    {
        global $gDb;

        $provider = new Provider($gDb);
        $values = array(
            'rqp_name' => CliApplication::requireArgument($arguments, 0, 'name')
        );
        self::copyStringOption($options, 'short-name', $values, 'rqp_short_name');
        self::copyStringOption($options, 'address', $values, 'rqp_address');
        self::copyStringOption($options, 'url', $values, 'rqp_url');
        self::copyStringOption($options, 'description', $values, 'rqp_description');
        self::copyBoolOption($options, 'public', $values, 'rqp_public');
        self::copyBoolOption($options, 'qualified', $values, 'rqp_qualified');

        return self::mutate($options, function () use ($gDb, $provider, $values): array {
            (new ProviderService($gDb))->save($provider, $values);
            return self::providerRow($provider);
        });
    }

    public static function providerUpdate(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $provider = new Provider($gDb, self::resolvePluginId(
            RequirementsTables::providers(),
            'rqp_id',
            'rqp_uuid',
            CliApplication::requireArgument($arguments, 0, 'provider'),
            'Provider',
            'rqp_org_id = ?',
            array((int)$gCurrentOrgId)
        ));
        $values = array();
        self::copyStringOption($options, 'name', $values, 'rqp_name');
        self::copyStringOption($options, 'short-name', $values, 'rqp_short_name');
        self::copyStringOption($options, 'address', $values, 'rqp_address');
        self::copyStringOption($options, 'url', $values, 'rqp_url');
        self::copyStringOption($options, 'description', $values, 'rqp_description');
        self::copyBoolOption($options, 'public', $values, 'rqp_public');
        self::copyBoolOption($options, 'qualified', $values, 'rqp_qualified');

        return self::mutate($options, function () use ($gDb, $provider, $values): array {
            (new ProviderService($gDb))->save($provider, $values);
            return self::providerRow($provider);
        });
    }

    public static function requirementList(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $rows = $gDb->queryPrepared(
            'SELECT rqt_uuid AS uuid, rqt_name AS name, rqt_short_name AS short_name,
                    rqt_period_unit AS period_unit, rqt_period_count AS period_count,
                    rqt_period_anchor AS period_anchor, rqt_multiple_roles_mode AS multiple_roles,
                    rqt_active AS active
               FROM ' . RequirementsTables::requirementTypes() . '
              WHERE rqt_org_id = ?
              ORDER BY rqt_sequence, rqt_name',
            array((int)$gCurrentOrgId)
        )->fetchAll();

        CliApplication::writeRows($rows, CliApplication::optionString($options, 'format', 'table'), $options);
        return 0;
    }

    public static function requirementAdd(array $arguments, array $options): int
    {
        global $gDb;

        $type = new RequirementType($gDb);
        $values = self::requirementValues($options);
        $values['rqt_name'] = CliApplication::requireArgument($arguments, 0, 'name');

        return self::mutate($options, function () use ($gDb, $type, $values): array {
            (new RequirementTypeService($gDb))->saveType($type, $values);
            return self::requirementRow($type);
        });
    }

    public static function requirementUpdate(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $type = new RequirementType($gDb, self::resolvePluginId(
            RequirementsTables::requirementTypes(),
            'rqt_id',
            'rqt_uuid',
            CliApplication::requireArgument($arguments, 0, 'requirement'),
            'Requirement',
            'rqt_org_id = ?',
            array((int)$gCurrentOrgId)
        ));
        $values = self::requirementValues($options);

        return self::mutate($options, function () use ($gDb, $type, $values): array {
            (new RequirementTypeService($gDb))->saveType($type, $values);
            return self::requirementRow($type);
        });
    }

    public static function ruleAdd(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $type = self::loadRequirement(CliApplication::requireArgument($arguments, 0, 'requirement'));
        $rule = new RequirementRoleRule($gDb);
        $values = self::ruleValues($options);
        $values['rrr_role_id'] = (int)CliApplication::requireArgument($arguments, 1, 'role');
        $values['rrr_required_credits'] = (float)CliApplication::requireArgument($arguments, 2, 'credits');

        return self::mutate($options, function () use ($gDb, $rule, $type, $values): array {
            (new RequirementTypeService($gDb))->saveRule($rule, $type, $values);
            return self::ruleRow($rule);
        });
    }

    public static function ruleUpdate(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $rule = new RequirementRoleRule($gDb, self::resolvePluginId(
            RequirementsTables::requirementRoleRules(),
            'rrr_id',
            'rrr_uuid',
            CliApplication::requireArgument($arguments, 0, 'rule'),
            'Requirement rule'
        ));
        $type = new RequirementType($gDb, (int)$rule->getValue('rrr_requirement_type_id'));
        if ((int)$type->getValue('rqt_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $values = self::ruleValues($options);
        self::copyIntOption($options, 'role', $values, 'rrr_role_id');
        self::copyFloatOption($options, 'credits', $values, 'rrr_required_credits');

        return self::mutate($options, function () use ($gDb, $rule, $type, $values): array {
            (new RequirementTypeService($gDb))->saveRule($rule, $type, $values);
            return self::ruleRow($rule);
        });
    }

    public static function activityAdd(array $arguments, array $options): int
    {
        global $gDb, $gCurrentUser;

        $activity = new Activity($gDb);
        $values = self::activityValues($options);
        $values['rqa_title'] = CliApplication::requireArgument($arguments, 0, 'title');
        $values['rqa_begin_date'] = CliApplication::optionString($options, 'begin');

        $user = array_key_exists('user', $options)
            ? CliApplication::resolveUser(CliApplication::optionString($options, 'user'))
            : $gCurrentUser;

        return self::mutate($options, function () use ($gDb, $activity, $values, $user): array {
            (new ActivityService($gDb))->save($activity, $values, (int)$user->getValue('usr_id'));
            return self::activityRow($activity);
        });
    }

    public static function activityUpdate(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $activity = new Activity($gDb, self::resolvePluginId(
            RequirementsTables::activities(),
            'rqa_id',
            'rqa_uuid',
            CliApplication::requireArgument($arguments, 0, 'activity'),
            'Activity',
            'rqa_org_id = ?',
            array((int)$gCurrentOrgId)
        ));
        $values = self::activityValues($options);

        return self::mutate($options, function () use ($gDb, $activity, $values): array {
            (new ActivityService($gDb))->save($activity, $values);
            return self::activityRow($activity);
        });
    }

    public static function activityDelete(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $activity = new Activity($gDb, self::resolvePluginId(
            RequirementsTables::activities(),
            'rqa_id',
            'rqa_uuid',
            CliApplication::requireArgument($arguments, 0, 'activity'),
            'Activity',
            'rqa_org_id = ?',
            array((int)$gCurrentOrgId)
        ));
        CliApplication::confirm('Delete activity "' . $activity->getValue('rqa_title') . '"?', $options);

        return self::mutate($options, function () use ($gDb, $activity): array {
            $row = self::activityRow($activity);
            (new ActivityService($gDb))->delete($activity);
            $row['deleted'] = true;
            return $row;
        });
    }

    public static function creditSet(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $activity = new Activity($gDb, self::resolvePluginId(
            RequirementsTables::activities(),
            'rqa_id',
            'rqa_uuid',
            CliApplication::requireArgument($arguments, 0, 'activity'),
            'Activity',
            'rqa_org_id = ?',
            array((int)$gCurrentOrgId)
        ));
        $type = self::loadRequirement(CliApplication::requireArgument($arguments, 1, 'requirement'));
        $period = CliApplication::requireArgument($arguments, 2, 'period');
        $claimed = (float)CliApplication::requireArgument($arguments, 3, 'claimed');
        $accepted = array_key_exists('accepted', $options)
            ? CliApplication::optionFloat($options, 'accepted') : null;

        $existingId = self::findCreditId(
            (int)$activity->getValue('rqa_id'),
            (int)$type->getValue('rqt_id'),
            FulfilmentService::normalizePeriodReference($type, $period)
        );
        $credit = new ActivityCredit($gDb, $existingId);

        return self::mutate($options, function () use (
            $gDb, $credit, $activity, $type, $period, $claimed, $accepted
        ): array {
            (new ActivityService($gDb))->saveCredit($credit, $activity, $type, $period, $claimed, $accepted);
            return array(
                'uuid' => $credit->getValue('rac_uuid'),
                'activity' => $activity->getValue('rqa_uuid'),
                'requirement' => $type->getValue('rqt_name'),
                'period_reference' => $credit->getValue('rac_period_reference'),
                'claimed' => $credit->getValue('rac_credits_claimed'),
                'accepted' => $credit->getValue('rac_credits_accepted')
            );
        });
    }

    public static function report(array $arguments, array $options): int
    {
        global $gCurrentUser;

        $user = array_key_exists('user', $options)
            ? CliApplication::resolveUser(CliApplication::optionString($options, 'user'))
            : $gCurrentUser;

        if ((int)$user->getValue('usr_id') !== (int)$gCurrentUser->getValue('usr_id')
            && !$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        if (!$user->isMemberOfOrganization()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $period = CliApplication::optionString($options, 'period', DATE_NOW);
        $coverage = (new FulfilmentService($GLOBALS['gDb']))->coverageForUser(
            (int)$user->getValue('usr_id'),
            $period
        );

        $rows = array();
        foreach ($coverage as $entry) {
            $rows[] = array(
                'requirement' => $entry['requirement'],
                'period' => $entry['period'],
                'attained' => $entry['attained'],
                'required' => $entry['required'],
                'coverage_percent' => $entry['coverage'],
                'status' => $entry['status'],
                'warnings' => implode(' | ', $entry['warnings'])
            );
            if (CliApplication::optionBool($options, 'details', false)) {
                foreach ($entry['activities'] as $activity) {
                    $rows[] = array(
                        'requirement' => '  ↳ ' . $activity['title'],
                        'period' => $activity['date'],
                        'attained' => $activity['effectiveCredits'],
                        'required' => null,
                        'coverage_percent' => null,
                        'status' => 'activity',
                        'warnings' => ''
                    );
                }
            }
        }

        CliApplication::writeRows(
            $rows,
            CliApplication::optionString($options, 'format', 'table'),
            $options
        );
        return 0;
    }

    private static function mutate(array $options, callable $callback): int
    {
        global $gDb;

        $dryRun = CliApplication::optionBool($options, 'dry-run', false) ?? false;
        if ($dryRun) {
            $gDb->startTransaction();
        }

        try {
            $row = $callback();
            if ($dryRun) {
                $gDb->rollback();
                $row['dry_run'] = true;
            }
            CliApplication::writeRows(
                array($row),
                CliApplication::optionString($options, 'format', 'table'),
                $options
            );
            return 0;
        } catch (Throwable $exception) {
            if ($dryRun) {
                $gDb->rollback();
            }
            throw $exception;
        }
    }

    private static function loadRequirement(string $reference): RequirementType
    {
        global $gDb, $gCurrentOrgId;

        $id = self::resolvePluginId(
            RequirementsTables::requirementTypes(),
            'rqt_id',
            'rqt_uuid',
            $reference,
            'Requirement',
            'rqt_org_id = ?',
            array((int)$gCurrentOrgId),
            'rqt_short_name'
        );
        return new RequirementType($gDb, $id);
    }

    private static function resolvePluginId(
        string $table,
        string $idColumn,
        string $uuidColumn,
        string $reference,
        string $label,
        string $additionalWhere = '',
        array $additionalParams = array(),
        ?string $shortNameColumn = null
    ): int {
        global $gDb;

        if (ctype_digit($reference)) {
            $where = $idColumn . ' = ?';
            $params = array((int)$reference);
        } else {
            $where = $uuidColumn . ' = ?';
            $params = array($reference);
            if ($shortNameColumn !== null) {
                $where = '(' . $where . ' OR ' . $shortNameColumn . ' = ?)';
                $params[] = $reference;
            }
        }
        if ($additionalWhere !== '') {
            $where .= ' AND (' . $additionalWhere . ')';
            $params = array_merge($params, $additionalParams);
        }

        $ids = array_values(array_unique(array_map(
            'intval',
            $gDb->queryPrepared(
                'SELECT ' . $idColumn . ' FROM ' . $table . ' WHERE ' . $where,
                $params
            )->fetchAll(\PDO::FETCH_COLUMN)
        )));
        if (count($ids) === 0) {
            throw new InvalidArgumentException($label . ' "' . $reference . '" was not found.');
        }
        if (count($ids) > 1) {
            throw new InvalidArgumentException($label . ' reference "' . $reference . '" is ambiguous.');
        }
        return $ids[0];
    }

    private static function findCreditId(int $activityId, int $typeId, string $periodReference): int
    {
        global $gDb;

        $ids = $gDb->queryPrepared(
            'SELECT rac_id
               FROM ' . RequirementsTables::activityCredits() . '
              WHERE rac_activity_id = ?
                AND rac_requirement_type_id = ?
                AND rac_period_reference = ?',
            array($activityId, $typeId, $periodReference)
        )->fetchAll(\PDO::FETCH_COLUMN);

        if (count($ids) > 1) {
            throw new InvalidArgumentException('Several credits exist for this activity, requirement and period.');
        }
        return isset($ids[0]) ? (int)$ids[0] : 0;
    }

    private static function providerRow(Provider $provider): array
    {
        return array(
            'uuid' => $provider->getValue('rqp_uuid'),
            'name' => $provider->getValue('rqp_name'),
            'short_name' => $provider->getValue('rqp_short_name'),
            'public' => (bool)$provider->getValue('rqp_public'),
            'qualified' => (bool)$provider->getValue('rqp_qualified')
        );
    }

    private static function requirementRow(RequirementType $type): array
    {
        return array(
            'uuid' => $type->getValue('rqt_uuid'),
            'name' => $type->getValue('rqt_name'),
            'short_name' => $type->getValue('rqt_short_name'),
            'period_unit' => $type->getValue('rqt_period_unit'),
            'period_count' => $type->getValue('rqt_period_count'),
            'period_anchor' => $type->getValue('rqt_period_anchor'),
            'multiple_roles' => $type->getValue('rqt_multiple_roles_mode'),
            'active' => (bool)$type->getValue('rqt_active')
        );
    }

    private static function ruleRow(RequirementRoleRule $rule): array
    {
        return array(
            'uuid' => $rule->getValue('rrr_uuid'),
            'requirement_id' => $rule->getValue('rrr_requirement_type_id'),
            'role_id' => $rule->getValue('rrr_role_id'),
            'required_credits' => $rule->getValue('rrr_required_credits'),
            'partial_mode' => $rule->getValue('rrr_partial_period_mode'),
            'period_start_membership' => (bool)$rule->getValue('rrr_membership_at_period_start'),
            'proration_basis' => $rule->getValue('rrr_proration_basis'),
            'valid_from' => $rule->getValue('rrr_valid_from'),
            'valid_until' => $rule->getValue('rrr_valid_until')
        );
    }

    private static function activityRow(Activity $activity): array
    {
        return array(
            'uuid' => $activity->getValue('rqa_uuid'),
            'user_id' => $activity->getValue('rqa_user_id'),
            'title' => $activity->getValue('rqa_title'),
            'begin_date' => $activity->getValue('rqa_begin_date'),
            'end_date' => $activity->getValue('rqa_end_date'),
            'certificate_date' => $activity->getValue('rqa_certificate_date'),
            'status' => $activity->getValue('rqa_status')
        );
    }

    private static function requirementValues(array $options): array
    {
        $values = array();
        self::copyStringOption($options, 'name', $values, 'rqt_name');
        self::copyStringOption($options, 'short-name', $values, 'rqt_short_name');
        self::copyStringOption($options, 'description', $values, 'rqt_description');
        self::copyStringOption($options, 'period-unit', $values, 'rqt_period_unit');
        self::copyIntOption($options, 'period-count', $values, 'rqt_period_count');
        self::copyStringOption($options, 'period-anchor', $values, 'rqt_period_anchor');
        self::copyStringOption($options, 'multiple-roles', $values, 'rqt_multiple_roles_mode');
        self::copyBoolOption($options, 'active', $values, 'rqt_active');
        self::copyIntOption($options, 'sequence', $values, 'rqt_sequence');
        return $values;
    }

    private static function ruleValues(array $options): array
    {
        $values = array();
        self::copyStringOption($options, 'partial-mode', $values, 'rrr_partial_period_mode');
        self::copyBoolOption($options, 'member-at-period-start', $values, 'rrr_membership_at_period_start');
        self::copyStringOption($options, 'proration-basis', $values, 'rrr_proration_basis');
        self::copyStringOption($options, 'valid-from', $values, 'rrr_valid_from');
        self::copyStringOption($options, 'valid-until', $values, 'rrr_valid_until');
        return $values;
    }

    private static function activityValues(array $options): array
    {
        $map = array(
            'title' => 'rqa_title',
            'description' => 'rqa_description',
            'url' => 'rqa_url',
            'location' => 'rqa_location',
            'begin' => 'rqa_begin_date',
            'begin-time' => 'rqa_begin_time',
            'end' => 'rqa_end_date',
            'end-time' => 'rqa_end_time',
            'certificate' => 'rqa_certificate_date',
            'comments' => 'rqa_comments',
            'status' => 'rqa_status',
            'review-status' => 'rqa_review_status',
            'review-comments' => 'rqa_review_comments'
        );
        $values = array();
        foreach ($map as $option => $field) {
            self::copyStringOption($options, $option, $values, $field);
        }
        self::copyIntOption($options, 'provider', $values, 'rqa_provider_id');
        self::copyFloatOption($options, 'attendance', $values, 'rqa_attendance');
        return $values;
    }

    private static function copyStringOption(array $options, string $option, array &$values, string $field): void
    {
        if (array_key_exists($option, $options)) {
            $values[$field] = CliApplication::optionString($options, $option);
        }
    }

    private static function copyIntOption(array $options, string $option, array &$values, string $field): void
    {
        if (array_key_exists($option, $options)) {
            $values[$field] = CliApplication::optionInt($options, $option);
        }
    }

    private static function copyFloatOption(array $options, string $option, array &$values, string $field): void
    {
        if (array_key_exists($option, $options)) {
            $values[$field] = CliApplication::optionFloat($options, $option);
        }
    }

    private static function copyBoolOption(array $options, string $option, array &$values, string $field): void
    {
        if (array_key_exists($option, $options)) {
            $values[$field] = CliApplication::optionBool($options, $option);
        }
    }

    private function __construct()
    {
    }
}
