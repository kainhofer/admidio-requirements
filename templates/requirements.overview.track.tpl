{* One bar of the overview: the label of its period (unless it is the bar of a part, which shares the
   period of its parent), the track with its fill and numbers, and the link to its contributions. *}
{if $p.url}<a class="adm-req-bar-link" href="{$p.url}" title="{$l10n->get('PLG_REQUIREMENTS_SHOW_CONTRIBUTIONS')}">{/if}
{if $label}<div class="adm-req-bar-label">{$p.label}</div>{/if}
<div class="adm-req-track{if $p.required is not null} has-target{/if}{if not $label} adm-req-part-track{/if}">
    {if $p.percent is not null}
        <div class="adm-req-fill {if $p.status eq 'fulfilled'}ok{elseif $p.status eq 'overdue' or $p.status eq 'never-fulfilled'}over{elseif $p.status eq 'missing'}miss{else}none{/if}"
             style="width: {$p.percent}%;"></div>
    {/if}
    <div class="adm-req-val adm-req-val-in">
        {if $name}{$name}: {/if}
        {if $p.required is not null}
            {$p.attained} / {$p.required}{if $p.percent is not null} ({$p.percent}%){/if}
        {elseif $p.attained gt 0}
            {$p.attained}
        {else}
            &ndash;
        {/if}
    </div>
</div>
{if $p.url}</a>{/if}
