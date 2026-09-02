<?php
namespace AdmidioPlugin\Requirements;

use Admidio\Infrastructure\Plugins\PluginRegistry;

final class RequirementsPlugin
{
    public const ID = 'requirements';

    public const SETTING_ACCESS = 'requirements_plugin_enabled';
    public const SETTING_ACTIVITY_OWNER_EDIT_POLICY = 'requirements_activity_owner_edit_policy';
    public const SETTING_PRORATION_BASIS_DEFAULT = 'requirements_proration_basis_default';
    public const SETTING_OVERVIEW_PREVIOUS_YEARS = 'requirements_overview_previous_years';

    public const EDIT_POLICY_ALWAYS = 'always';
    public const EDIT_POLICY_UNTIL_SUBMITTED = 'until_submitted';

    public static function setting(string $name): mixed
    {
        $plugin = PluginRegistry::get(self::ID);
        if ($plugin === null) {
            return null;
        }

        $settings = $plugin->getSettingValues();
        return $settings[$name] ?? null;
    }

    private function __construct()
    {
    }
}
