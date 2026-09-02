<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_NAME')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqt_name']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqt_short_name']}
            {include 'sys-template-parts/form.multiline.tpl' data=$elements['rqt_description']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqt_sequence']}
            {include 'sys-template-parts/form.checkbox.tpl' data=$elements['rqt_active']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_PERIOD')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['rqt_period_unit']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqt_period_count']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['rqt_period_anchor']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['rqt_multiple_roles_mode']}
        </div>
    </div>
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
    {include file="sys-template-parts/system.info-create-edit.tpl"}
</form>
