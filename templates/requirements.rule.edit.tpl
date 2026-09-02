<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_ROLE')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.custom-content.tpl' data=$elements['rrr_requirement']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['rrr_role_id']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rrr_required_credits']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_PARTIAL_PERIOD_MODE')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['rrr_partial_period_mode']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['rrr_proration_basis']}
            {include 'sys-template-parts/form.checkbox.tpl' data=$elements['rrr_membership_at_period_start']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_VALIDITY')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.input.tpl' data=$elements['rrr_valid_from']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rrr_valid_until']}
        </div>
    </div>
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
    {include file="sys-template-parts/system.info-create-edit.tpl"}
</form>
