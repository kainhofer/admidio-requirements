<?php
namespace AdmidioPlugin\Requirements;

use Admidio\Components\Entity\Component;
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

    /**
     * Whether the acting user may use the plugin in the current organization at all.
     *
     * `Component::isVisible()` answers the plugin's access preference, which for a plugin resolves
     * to `PluginWidget::isVisible()` and therefore only asks whether somebody is logged in. Being
     * logged in is not the same as belonging to this organization - Admidio has one login across
     * organizations - so a valid login who is a member of a different organization would otherwise
     * pass every "may this person act" gate here.
     *
     * The record checks in the services are separate and stay: this is the gate for the actor, they
     * are the gate for the record.
     */
    public static function isUsableByCurrentUser(): bool
    {
        global $gCurrentUser, $gValidLogin;

        return !empty($gValidLogin)
            && Component::isVisible(self::ID)
            && isset($gCurrentUser)
            && $gCurrentUser->isMemberOfOrganization();
    }

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
