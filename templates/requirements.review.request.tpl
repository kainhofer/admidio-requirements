{* A reviewer asks members to share their activities of a requirement for review. *}
<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <p>{$l10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST_DESC')}</p>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_REVIEW')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['requirement']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['period']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['periods_back']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_MEMBERS')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['members']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['sample']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_MESSAGE')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.input.tpl' data=$elements['due_date']}
            {include 'sys-template-parts/form.multiline.tpl' data=$elements['message']}
            {if isset($elements['reviewer'])}
                {include 'sys-template-parts/form.select.tpl' data=$elements['reviewer']}
            {/if}
        </div>
    </div>
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
</form>
