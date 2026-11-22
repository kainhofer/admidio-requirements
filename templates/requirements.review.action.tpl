{* A review action of the member: what it covers, a message or reason, and the reviewer where the
   member may choose one. *}
<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    <div class="card admidio-field-group">
        <div class="card-header">{$scope nofilter}</div>
        <div class="card-body">
            <p>{$actionHelp}</p>
            {if $requestMessage}
                <div class="alert alert-secondary"><i class="bi bi-chat-left-text"></i> {$requestMessage nofilter}</div>
            {/if}
            {include 'sys-template-parts/form.multiline.tpl' data=$elements['message']}
            {if $hasReviewer}
                {include 'sys-template-parts/form.select.tpl' data=$elements['reviewer']}
            {/if}
        </div>
    </div>
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
</form>
