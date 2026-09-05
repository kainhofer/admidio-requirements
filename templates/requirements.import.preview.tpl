{include 'requirements.import.rows.tpl'}

<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {if isset($elements['adm_button_import'])}
        {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_import']}
    {/if}
    <a href="{$restartUrl}" class="btn btn-secondary">
        <i class="bi bi-arrow-counterclockwise"></i> {$l10n->get('PLG_REQUIREMENTS_IMPORT_RESTART')}
    </a>
</form>
