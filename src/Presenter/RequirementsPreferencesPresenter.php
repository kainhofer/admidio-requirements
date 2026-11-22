<?php
namespace AdmidioPlugin\Requirements\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginPanel;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PreferencesPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;

/**
 * The preferences panel of the plugin.
 *
 * The form Admidio generates from the manifest would do, except for one setting: the reviewers may be
 * individual members as well as roles, and the generated form knows how to pick roles but not
 * members. So the panel is built here, control by control from the manifest as the generated one is,
 * with a member selection for the reviewers. It is saved by the preferences page like any other panel.
 */
final class RequirementsPreferencesPresenter
{
    /** Core's template of a generated plugin panel, which routes each control by its type. */
    private const TEMPLATE = 'preferences/preferences.plugin.tpl';

    private function __construct()
    {
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public static function createForm(PreferencesPresenter $page): string
    {
        global $gL10n, $gCurrentSession;

        $plugin = PluginRegistry::requireEnabled(RequirementsPlugin::ID);
        $panel = PluginPanel::normalizeId($plugin->id);
        $values = $plugin->getSettingValues();

        $form = new FormPresenter(
            'adm_preferences_form_' . $panel,
            self::TEMPLATE,
            SecurityUtils::encodeUrl(
                ADMIDIO_URL . FOLDER_MODULES . '/preferences.php',
                array('mode' => 'save', 'panel' => $panel)
            ),
            null,
            array('class' => 'form-preferences')
        );

        foreach ($plugin->settings as $name => $definition) {
            // A setting without a label is not meant to be typed into, see PluginSettingsPresenter.
            if (($definition['label'] ?? '') === '') {
                continue;
            }
            self::addControl($form, $name, $definition, $values[$name] ?? $definition['default']);
        }

        $form->addSubmitButton(
            'adm_button_save_' . $panel,
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );

        $smarty = $page->getSmartyTemplate();
        $form->addToSmarty($smarty);
        $gCurrentSession->addFormObject($form);

        return $smarty->fetch(self::TEMPLATE);
    }

    /**
     * @param array<string,mixed> $definition
     * @throws Exception
     */
    private static function addControl(FormPresenter $form, string $name, array $definition, mixed $value): void
    {
        global $gDb, $gCurrentOrgId, $gProfileFields;

        $label = Language::translateIfTranslationStrId((string) $definition['label']);
        $options = array();
        if (($definition['description'] ?? '') !== '') {
            $options['helpTextId'] = $definition['description'];
        }

        if ($name === RequirementsPlugin::SETTING_REVIEW_USERS) {
            // The current members of the organization, by name.
            $sql = 'SELECT DISTINCT usr.usr_id, CONCAT(last_name.usd_value, \', \', first_name.usd_value) AS name
                      FROM ' . TBL_USERS . ' AS usr
                INNER JOIN ' . TBL_MEMBERS . ' AS mem ON mem.mem_usr_id = usr.usr_id
                INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id
                INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id
                 LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                        ON last_name.usd_usr_id = usr.usr_id AND last_name.usd_usf_id = ?
                 LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                        ON first_name.usd_usr_id = usr.usr_id AND first_name.usd_usf_id = ?
                     WHERE usr.usr_valid = true
                       AND mem.mem_begin <= ?
                       AND mem.mem_end >= ?
                       AND cat.cat_org_id = ?
                  ORDER BY name';
            $options['defaultValue'] = array_values(array_filter(array_map(
                'intval',
                is_array($value) ? $value : explode(',', (string) $value)
            )));
            $options['multiselect'] = true;
            $options['showContextDependentFirstEntry'] = false;
            $form->addSelectBoxFromSql($name, $label, $gDb, array('query' => $sql, 'params' => array(
                (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
                (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
                DATE_NOW,
                DATE_NOW,
                (int) $gCurrentOrgId
            )), $options);
            return;
        }

        switch ($definition['type']) {
            case 'bool':
            case 'boolean':
                $form->addCheckbox($name, $label, (bool) $value, $options);
                break;

            case 'enum':
                $labels = $definition['valueLabels'] ?? array();
                $entries = array();
                foreach ($definition['values'] as $entry) {
                    $entry = (string) $entry;
                    $entries[$entry] = isset($labels[$entry])
                        ? Language::translateIfTranslationStrId($labels[$entry]) : $entry;
                }
                $options['defaultValue'] = (string) $value;
                $options['showContextDependentFirstEntry'] = false;
                $form->addSelectBox($name, $label, $entries, $options);
                break;

            case 'roles':
                $sql = 'SELECT rol_id, rol_name, cat_name
                          FROM ' . TBL_ROLES . '
                    INNER JOIN ' . TBL_CATEGORIES . '
                            ON cat_id = rol_cat_id
                         WHERE rol_valid  = true
                           AND rol_system = false
                           AND cat_org_id = ? -- $gCurrentOrgId
                           AND cat_name_intern <> \'EVENTS\'
                      ORDER BY cat_sequence, rol_name';
                $options['defaultValue'] = Plugin::toRoleIds($value);
                $options['multiselect'] = true;
                $options['showContextDependentFirstEntry'] = false;
                $form->addSelectBoxFromSql($name, $label, $gDb,
                    array('query' => $sql, 'params' => array($gCurrentOrgId)), $options);
                break;

            case 'int':
            case 'integer':
                $options['type'] = 'number';
                $options['minNumber'] = $definition['min'] ?? null;
                $options['maxNumber'] = $definition['max'] ?? null;
                $options['step'] = $definition['step'] ?? null;
                $form->addInput($name, $label, (string) $value, $options);
                break;

            default:
                $form->addInput($name, $label, is_array($value) ? implode(',', $value) : (string) $value, $options);
                break;
        }
    }
}
