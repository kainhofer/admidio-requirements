{* The row table of the preview and of the result. The two pages ask different questions of the
   same list - "what would happen" and "what happened" - so the status column reads differently
   while the columns beside it stay the same. *}
<div class="alert {if $hasErrors}alert-warning{else}alert-info{/if}" role="alert">
    <i class="bi {if $hasErrors}bi-exclamation-triangle-fill{else}bi-info-circle-fill{/if}"></i> {$summary}
</div>

{if $rows|@count eq 0}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$messageNoData}
    </div>
{else}
    <div class="table-responsive">
        <table class="table table-condensed table-hover" style="max-width: 100%;">
            <thead>
                <tr>
                    <th>{$l10n->get('SYS_ROW')}</th>
                    <th>{$l10n->get('PLG_REQUIREMENTS_STATUS')}</th>
                    <th>{$l10n->get('SYS_MEMBER')}</th>
                    <th>{$l10n->get('SYS_TITLE')}</th>
                    <th>{$l10n->get('PLG_REQUIREMENTS_BEGIN_DATE')}</th>
                    <th>{$l10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED')}</th>
                    <th>{$l10n->get('SYS_MESSAGE')}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $rows as $row}
                    <tr class="{if $row.status eq 'error'}table-danger{elseif $row.status eq 'skip'}table-warning{/if}">
                        <td>{$row.row}</td>
                        <td>{$row.statusText}</td>
                        <td style="word-break: break-word;">{$row.member nofilter}</td>
                        <td style="word-break: break-word;">
                            {if $row.url neq ''}
                                <a href="{$row.url}">{$row.title nofilter}</a>
                            {else}
                                {$row.title nofilter}
                            {/if}
                        </td>
                        <td>{$row.begin nofilter}</td>
                        <td>
                            {foreach $row.credits as $credit}
                                <div>{$credit nofilter}</div>
                            {/foreach}
                        </td>
                        <td style="word-break: break-word;">
                            {foreach $row.messages as $message}
                                <div>{$message nofilter}</div>
                            {/foreach}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {if $truncated neq ''}
        <p class="text-muted">{$truncated}</p>
    {/if}
{/if}
