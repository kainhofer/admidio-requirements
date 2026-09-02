<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Service\ProviderService;

/**
 * Provider list and edit form of the requirements plugin.
 */
class ProviderPresenter extends RequirementsPresenter
{
    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(string $query = ''): void
    {
        global $gDb, $gL10n, $gCurrentUserId;

        $this->setHtmlID('adm_requirements_providers');
        $this->setHeadline($gL10n->get('PLG_REQUIREMENTS_PROVIDERS'));

        $this->addPageFunctionsMenuItem(
            'menu_item_requirements_provider_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_PROVIDER'))),
            $this->pageUrl('providers.php', array('mode' => 'edit')),
            'bi-plus-circle-fill'
        );

        $this->assignSmartyVariable('searchUrl', $this->plugin->getUrl('providers.php'));
        $this->assignSmartyVariable('query', $query);
        $this->assignSmartyVariable('searchPlaceholder', $gL10n->get('PLG_REQUIREMENTS_SEARCH_PROVIDERS'));

        $headers = array(
            $gL10n->get('SYS_NAME'),
            $gL10n->get('PLG_REQUIREMENTS_PROVIDER_SHORT_NAME'),
            $gL10n->get('SYS_WEBSITE'),
            $gL10n->get('PLG_REQUIREMENTS_PROVIDER_PUBLIC'),
            $gL10n->get('PLG_REQUIREMENTS_PROVIDER_QUALIFIED'),
            ''
        );

        $rows = array();
        foreach ((new ProviderService($gDb))->findVisible($query) as $provider) {
            $editable = $this->isAdmin() || (int) $provider['rqp_usr_id_create'] === (int) $gCurrentUserId;
            $rowId = 'adm_requirements_provider_' . $provider['rqp_uuid'];
            $editUrl = $this->pageUrl('providers.php', array('mode' => 'edit', 'uuid' => $provider['rqp_uuid']));

            $name = $editable
                ? '<a href="' . $editUrl . '">' . SecurityUtils::encodeHTML($provider['rqp_name']) . '</a>'
                : SecurityUtils::encodeHTML($provider['rqp_name']);

            $website = '';
            if (!empty($provider['rqp_url'])) {
                $website = '<a href="' . SecurityUtils::encodeHTML($provider['rqp_url']) . '" target="_blank" rel="noopener">'
                    . SecurityUtils::encodeHTML($provider['rqp_url']) . '</a>';
            }

            $actions = array();
            if ($editable) {
                $actions[] = array(
                    'url' => $editUrl,
                    'icon' => 'bi bi-pencil-square',
                    'tooltip' => $gL10n->get('SYS_EDIT')
                );
                $actions[] = $this->deleteAction(
                    $rowId,
                    $this->pageUrl('providers.php', array('mode' => 'delete', 'uuid' => $provider['rqp_uuid'])),
                    $gL10n->get('SYS_WANT_DELETE_ENTRY', array($provider['rqp_name']))
                );
            }

            $rows[] = array(
                'id' => $rowId,
                'uuid' => $provider['rqp_uuid'],
                'data' => array(
                    $name,
                    SecurityUtils::encodeHTML((string) $provider['rqp_short_name']),
                    $website,
                    $provider['rqp_public'] ? $gL10n->get('SYS_YES') : $gL10n->get('SYS_NO'),
                    $provider['rqp_qualified'] ? $gL10n->get('SYS_YES') : $gL10n->get('SYS_NO')
                ),
                'actions' => $actions
            );
        }

        $this->assignSmartyVariable('headers', $headers);
        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('SYS_NO_ENTRIES'));
        $this->addHtmlByTemplate('requirements.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(string $providerUUID, string $returnUrl = ''): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $provider = new Provider($gDb);
        if ($providerUUID !== '') {
            $provider->readDataByUuid($providerUUID);
            if ((int) $provider->getValue('rqp_id') === 0 || !$provider->isEditable()) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $this->setHeadline($gL10n->get('SYS_EDIT_VAR', array($gL10n->get('PLG_REQUIREMENTS_PROVIDER'))));
        } else {
            $this->setHeadline($gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_REQUIREMENTS_PROVIDER'))));
        }
        $this->setHtmlID('adm_requirements_provider_edit');

        $form = new FormPresenter(
            'adm_requirements_provider_edit_form',
            'requirements.provider.edit.tpl',
            $this->pageUrl('providers.php', array('mode' => 'save', 'uuid' => $providerUUID, 'return' => $returnUrl)),
            $this
        );
        $form->addInput(
            'rqp_name',
            $gL10n->get('SYS_NAME'),
            (string) $provider->getValue('rqp_name'),
            array('maxLength' => 255, 'property' => FormPresenter::FIELD_REQUIRED)
        );
        $form->addInput(
            'rqp_short_name',
            $gL10n->get('PLG_REQUIREMENTS_PROVIDER_SHORT_NAME'),
            (string) $provider->getValue('rqp_short_name'),
            array('maxLength' => 255, 'helpTextId' => 'PLG_REQUIREMENTS_PROVIDER_SHORT_NAME_HELP')
        );
        $form->addInput(
            'rqp_url',
            $gL10n->get('SYS_WEBSITE'),
            (string) $provider->getValue('rqp_url'),
            array('maxLength' => 2000, 'type' => 'url')
        );
        $form->addMultilineTextInput(
            'rqp_address',
            $gL10n->get('SYS_ADDRESS'),
            (string) $provider->getValue('rqp_address'),
            3
        );
        $form->addMultilineTextInput(
            'rqp_description',
            $gL10n->get('SYS_DESCRIPTION'),
            (string) $provider->getValue('rqp_description'),
            4
        );
        $form->addCheckbox(
            'rqp_public',
            $gL10n->get('PLG_REQUIREMENTS_PROVIDER_PUBLIC'),
            $provider->isNewRecord() ? true : (bool) $provider->getValue('rqp_public'),
            array('helpTextId' => 'PLG_REQUIREMENTS_PROVIDER_PUBLIC_HELP')
        );
        if ($this->isAdmin()) {
            $form->addCheckbox(
                'rqp_qualified',
                $gL10n->get('PLG_REQUIREMENTS_PROVIDER_QUALIFIED'),
                (bool) $provider->getValue('rqp_qualified'),
                array('helpTextId' => 'PLG_REQUIREMENTS_PROVIDER_QUALIFIED_HELP')
            );
        }
        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('userCreatedName', $provider->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $provider->getValue('rqp_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $provider->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $provider->getValue('rqp_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
