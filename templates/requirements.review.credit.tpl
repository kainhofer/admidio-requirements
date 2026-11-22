{* One credit in the activities of a review row: the claim, and the decision on it - as inputs where
   the current user decides on it, as text otherwise. A part ("thereof") is a line of its own under
   the credit of its parent, named by $label. *}
<tr class="adm-req-credit" data-credit="{$c.creditId}" data-claimed="{$c.claimedRaw}" data-status="{$c.reviewStatus}">
    <td>
        {if $label}
            <span class="ps-3 text-body-secondary"><i class="bi bi-arrow-return-right"></i> {$l10n->get('PLG_REQUIREMENTS_THEREOF')} {$label nofilter}</span>
        {else}
            <a class="openPopup" href="javascript:void(0);" data-href="{$c.viewUrl}" data-class="modal-lg">{$c.title nofilter}</a>
            {if $c.activityStatusText}<span class="badge text-bg-secondary">{$c.activityStatusText}</span>{/if}
            {foreach $c.files as $f}
                <a href="{$f.url}" class="badge text-bg-light text-decoration-none" title="{$f.name nofilter}">
                    <i class="bi bi-paperclip"></i> {$f.name nofilter}</a>
            {/foreach}
        {/if}
        {if $c.changed}<div class="small text-warning-emphasis"><i class="bi bi-pencil-square"></i> {$c.changed}</div>{/if}
    </td>
    <td class="small">{if not $label}{$c.date}{/if}</td>
    <td class="small">{if not $label}{$c.periodReference}{/if}</td>
    <td class="text-end">{$c.claimed}</td>
    {if $c.decidable}
        <td>
            <input type="number" step="0.01" min="0" value="{$c.acceptedValue}" aria-label="{$l10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED')}"
                   class="form-control form-control-sm adm-req-accepted" style="max-width: 7rem;">
        </td>
        <td>
            <select class="form-select form-select-sm adm-req-decision" aria-label="{$l10n->get('PLG_REQUIREMENTS_REVIEW_STATUS')}">
                {foreach $statusOptions as $value => $name}
                    <option value="{$value}" {if ($value neq '' and $value neq 'open' and $value eq $c.reviewStatus) or ($value eq '' and ($c.reviewStatus eq '' or $c.reviewStatus eq 'open'))}selected{/if}>{$name}</option>
                {/foreach}
            </select>
        </td>
        <td>
            <textarea rows="1" class="form-control form-control-sm adm-req-comment" aria-label="{$l10n->get('PLG_REQUIREMENTS_REVIEW_COMMENTS')}">{$c.comment nofilter}</textarea>
        </td>
    {else}
        <td>{if $c.acceptedValue neq ''}{$c.acceptedValue}{else}&ndash;{/if}</td>
        <td>{if $c.reviewStatusText}<span class="badge {$c.reviewStatusClass}">{$c.reviewStatusText}</span>{else}&ndash;{/if}</td>
        <td class="small">{$c.comment nofilter}</td>
    {/if}
</tr>
