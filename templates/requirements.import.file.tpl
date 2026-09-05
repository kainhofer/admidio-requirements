<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}

    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$introduction}
        <div class="mt-2"><small><code>{$columnHelp}</code></small></div>
    </div>

    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_FILE')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['format']}
            {include 'sys-template-parts/form.file.tpl' data=$elements['userfile']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['import_sheet']}
            {include 'sys-template-parts/form.checkbox.tpl' data=$elements['first_row_header']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['import_coding']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['import_separator']}
        </div>
    </div>

    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_IMPORT_DUPLICATES')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['duplicates']}
        </div>
    </div>

    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_next']}
</form>
