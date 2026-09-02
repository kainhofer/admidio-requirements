<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {foreach $elements as $element}
        {if $element.id eq 'adm_button_save'}
        {elseif $element.type eq 'select'}
            {include 'sys-template-parts/form.select.tpl' data=$element}
        {elseif $element.type eq 'checkbox'}
            {include 'sys-template-parts/form.checkbox.tpl' data=$element}
        {elseif $element.type eq 'multiline'}
            {include 'sys-template-parts/form.multiline.tpl' data=$element}
        {elseif $element.type eq 'custom-content'}
            {include 'sys-template-parts/form.custom-content.tpl' data=$element}
        {elseif $element.type eq 'editor'}
            {include 'sys-template-parts/form.editor.tpl' data=$element}
        {elseif $element.type eq 'radio'}
            {include 'sys-template-parts/form.radio.tpl' data=$element}
        {else}
            {include 'sys-template-parts/form.input.tpl' data=$element}
        {/if}
    {/foreach}

    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
    {include file="sys-template-parts/system.info-create-edit.tpl"}
</form>
