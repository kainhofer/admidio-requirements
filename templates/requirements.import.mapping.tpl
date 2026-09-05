{if $sampleRows|@count gt 0}
    <div class="card admidio-field-group mb-3">
        <div class="card-header">{$sampleLabel}</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-condensed table-sm" style="max-width: 100%;">
                    <thead>
                        <tr>
                            {foreach $sampleHeaders as $header}
                                <th>{$header nofilter}</th>
                            {/foreach}
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $sampleRows as $row}
                            <tr>
                                {foreach $row as $cell}
                                    <td style="word-break: break-word;">{$cell nofilter}</td>
                                {/foreach}
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
{/if}

<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}

    {foreach $groups as $group}
        <div class="card admidio-field-group">
            <div class="card-header">{$group.label}</div>
            <div class="card-body">
                {if $group.help neq ''}
                    <p class="text-muted">{$group.help}</p>
                {/if}
                {foreach $group.fields as $field}
                    {include 'sys-template-parts/form.select.tpl' data=$elements[$field.id]}
                {/foreach}
            </div>
        </div>
    {/foreach}

    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_next']}
</form>
