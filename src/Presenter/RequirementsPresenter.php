<?php
namespace AdmidioPlugin\Requirements\Presenter;

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

        if (!Component::isVisible(RequirementsPlugin::ID)) {
            throw new Exception('SYS_NO_RIGHTS');
        }
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
        global $gCurrentSession, $gL10n;

        return array(
            'dataHref' => 'callUrlHideElement(\'' . $rowHtmlId . '\', \'' . $url . '\', \''
                . $gCurrentSession->getCsrfToken() . '\')',
            'dataMessage' => $message,
            'icon' => 'bi bi-trash',
            'tooltip' => $gL10n->get('SYS_DELETE')
        );
    }
}
