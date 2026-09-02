<?php
/**
 ***********************************************************************************************
 * Requirements plugin landing page.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $plugin = PluginRegistry::get(RequirementsPlugin::ID);
    if ($plugin === null || !Component::isVisible(RequirementsPlugin::ID)) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $page = PagePresenter::withHtmlIDAndHeadline(
        'adm_requirements',
        $gL10n->get('PLG_REQUIREMENTS_NAME')
    );
    $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), $plugin->icon);

    $page->addPageFunctionsMenuItem(
        'menu_item_requirements_activities',
        $gL10n->get('PLG_REQUIREMENTS_ACTIVITIES'),
        $plugin->getUrl('activities.php'),
        'bi-clipboard-check'
    );
    $page->addPageFunctionsMenuItem(
        'menu_item_requirements_providers',
        $gL10n->get('PLG_REQUIREMENTS_PROVIDERS'),
        $plugin->getUrl('providers.php'),
        'bi-shop'
    );
    if (Component::isAdministrable(RequirementsPlugin::ID)) {
        $page->addPageFunctionsMenuItem(
            'menu_item_requirements_rules',
            $gL10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPES'),
            $plugin->getUrl('rules.php'),
            'bi-list-check'
        );
    }

    $page->addTemplateFolder($plugin->getDirectory(Plugin::DIR_TEMPLATES));
    $page->addTemplateFile('plugin.requirements.tpl');
    $page->assignSmartyVariable('pluginDescription', $gL10n->get('PLG_REQUIREMENTS_DESCRIPTION'));
    $page->show();
} catch (Throwable $e) {
    handleException($e);
}
