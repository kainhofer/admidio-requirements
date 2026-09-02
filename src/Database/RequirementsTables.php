<?php
namespace AdmidioPlugin\Requirements\Database;

final class RequirementsTables
{
    public static function providers(): string
    {
        return TABLE_PREFIX . '_req_providers';
    }

    public static function activities(): string
    {
        return TABLE_PREFIX . '_req_activities';
    }

    public static function requirementTypes(): string
    {
        return TABLE_PREFIX . '_req_requirement_types';
    }

    public static function requirementRoleRules(): string
    {
        return TABLE_PREFIX . '_req_requirement_role_rules';
    }

    public static function activityCredits(): string
    {
        return TABLE_PREFIX . '_req_activity_credits';
    }

    private function __construct()
    {
    }
}
