<?php
namespace AdmidioPlugin\Requirements;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Plugins\PluginWidget;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\Requirements\Presenter\ActivityPresenter;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;

final class RequirementsPlugin
{
    public const ID = 'requirements';

    public const SETTING_ACCESS = 'requirements_plugin_enabled';
    public const SETTING_ACTIVITY_OWNER_EDIT_POLICY = 'requirements_activity_owner_edit_policy';
    public const SETTING_PRORATION_BASIS_DEFAULT = 'requirements_proration_basis_default';
    public const SETTING_OVERVIEW_PREVIOUS_YEARS = 'requirements_overview_previous_years';
    public const SETTING_WIDGET_ACCESS = 'requirements_widget_enabled';
    public const SETTING_WIDGET_PREVIOUS_PERIODS = 'requirements_widget_previous_periods';

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

    /**
     * Put the fulfilment overview on the overview page as a widget. Who sees it is its own
     * preference, so an organization can keep the plugin and switch the widget off.
     */
    public static function registerWidget(): void
    {
        $plugin = PluginRegistry::get(self::ID);
        if ($plugin === null) {
            return;
        }

        PluginWidget::register($plugin, array(self::class, 'renderWidget'), array(
            'enabledPreference' => self::SETTING_WIDGET_ACCESS,
            'sequence' => 30
        ));
    }

    /**
     * The widget: the current member's fulfilment overview, for the running period and the
     * configured number of previous ones. A visitor who may not use the plugin, and a member nothing
     * applies to, get no widget at all rather than an empty box.
     *
     * @throws \Admidio\Infrastructure\Exception|\Smarty\Exception
     */
    public static function renderWidget(PagePresenter $page, Plugin $plugin): string
    {
        global $gDb, $gCurrentUser;

        if (!self::isUsableByCurrentUser()) {
            return '';
        }

        $userId = (int) $gCurrentUser->getValue('usr_id');
        $overview = (new FulfilmentService($gDb))->coverageHistoryForUser(
            $userId,
            (int) (self::setting(self::SETTING_WIDGET_PREVIOUS_PERIODS) ?? 1)
        );
        if ($overview === array()) {
            return '';
        }

        return $plugin->renderTemplate($page, 'requirements.overview.widget.tpl', array(
            'overview' => ActivityPresenter::linkOverview(
                $overview,
                (new RequirementTypeService($gDb))->findTypes('', $userId),
                $plugin
            ),
            'activitiesUrl' => $plugin->getUrl('index.php')
        ));
    }

    private function __construct()
    {
    }
}
