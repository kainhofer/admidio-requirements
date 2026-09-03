<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Changelog\Service\ChangelogService;
use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;

/**
 * Shared base for the requirements plugin pages. It resolves the plugin, registers the plugin
 * template directory with Smarty and offers small URL/permission helpers.
 */
abstract class RequirementsPresenter extends PagePresenter
{
    protected Plugin $plugin;

    /**
     * @throws Exception
     */
    public function __construct(string $objectUUID = '')
    {
        $this->plugin = PluginRegistry::requireEnabled(RequirementsPlugin::ID);

        parent::__construct($objectUUID);

        $templates = $this->plugin->getDirectory(Plugin::DIR_TEMPLATES);
        if ($templates !== null) {
            $this->addTemplateFolder($templates);
        }

        if (!RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * Offer the change history of the given plugin tables in the page-functions menu.
     *
     * `displayHistoryButton()` decides for itself whether the button appears: it checks that the
     * changelog is switched on, that those tables are logged, and that the current user may read
     * them - which for the requirements tables is the callback in plugin.php.
     *
     * @param array<int,string> $tables
     * @param array<string,mixed> $parameters uuid, id or related_id to narrow the history down
     */
    protected function addHistoryMenuItem(string $area, array $tables, array $parameters = array()): void
    {
        ChangelogService::displayHistoryButton($this, 'requirements_' . $area, $tables, true, $parameters);
    }

    /**
     * A credit amount as it should be read: two decimals at most, and no trailing zeros, so that
     * 7.50 reads as 7.5 and 8.00 as 8. An unset value stays an empty cell rather than becoming 0.
     */
    protected static function formatCredits(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    protected function isAdmin(): bool
    {
        return Component::isAdministrable(RequirementsPlugin::ID);
    }

    /**
     * Build an URL to one of the plugin pages with the given query parameters.
     */
    protected function pageUrl(string $page, array $parameters = array()): string
    {
        return SecurityUtils::encodeUrl($this->plugin->getUrl($page), $parameters);
    }

    /**
     * A delete action entry for sys-template-parts/list.functions.tpl.
     */
    protected function deleteAction(string $rowHtmlId, string $url, string $message): array
    {
        global $gL10n;

        return $this->confirmedPostAction($rowHtmlId, $url, $message, 'bi bi-trash', $gL10n->get('SYS_DELETE'));
    }

    /**
     * An action entry for sys-template-parts/list.functions.tpl that asks for confirmation and then
     * posts to $url with the CSRF token.
     *
     * Without $jsCallback the row fades out on success, which is what a delete wants. An action that
     * only changes a record - submitting an activity, say - names a global JavaScript function
     * instead, so the row stays and the page can show the new state.
     */
    protected function confirmedPostAction(
        string $rowHtmlId,
        string $url,
        string $message,
        string $icon,
        string $tooltip,
        string $jsCallback = ''
    ): array {
        global $gCurrentSession;

        return array(
            'dataHref' => 'callUrlHideElement(\'' . $rowHtmlId . '\', \'' . $url . '\', \''
                . $gCurrentSession->getCsrfToken() . '\''
                . ($jsCallback === '' ? '' : ', \'' . $jsCallback . '\'') . ')',
            'dataMessage' => $message,
            'icon' => $icon,
            'tooltip' => $tooltip
        );
    }
}
