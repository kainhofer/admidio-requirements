{* One row of the review page: the member with their coverage and review, and - collapsed below - the
   credits behind it with the reviewer's decision on each. A row is a tbody of its own, so that it can
   be saved and replaced on its own. *}
<tbody class="adm-req-review-row" id="{$row.id}" data-user="{$row.userUuid}" data-requirement="{$row.typeUuid}" data-period="{$row.period}">
    <tr>
        <td>
            {$row.name nofilter}
            {if $row.isSelf}<span class="badge text-bg-light border" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_OWN_ROW_HELP')}">{$l10n->get('PLG_REQUIREMENTS_REVIEW_OWN_ROW')}</span>{/if}
            {foreach $row.warnings as $warning}
                <i class="bi bi-exclamation-triangle-fill text-warning" data-bs-toggle="tooltip" title="{$warning nofilter}"></i>
            {/foreach}
            <div class="small text-body-secondary">{$row.requirementName nofilter}{if $row.periodLabel} &middot; {$row.periodLabel nofilter}{/if}</div>
        </td>
        <td class="text-end">
            {if $row.awaitingShare}
                <span class="text-body-secondary small" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_NOT_SHARED_YET')}">
                    <i class="bi bi-eye-slash"></i> {$l10n->get('PLG_REQUIREMENTS_REVIEW_STATE_REQUESTED')}</span>
            {else}
            <div class="adm-req-credit-cell adm-req-status-{$row.status}" data-bs-toggle="tooltip"
                 title="{$row.statusText}{if $row.percent !== null} ({$row.percent}%){/if}">
                {if $row.percent !== null}
                    <div class="adm-req-fill" style="width: {$row.percent}%;"></div>
                    {if $row.pendingPercent gt 0}
                        <div class="adm-req-fill-pending" style="left: {$row.percent}%; width: {$row.pendingPercent}%;"></div>
                    {/if}
                {/if}
                <span class="adm-req-credit-text">
                    {if $row.status eq 'fulfilled'}<i class="bi bi-check-circle-fill text-success"></i>
                    {elseif $row.status eq 'missing' or $row.status eq 'overdue' or $row.status eq 'never-fulfilled'}<i class="bi bi-exclamation-circle-fill text-danger"></i>{/if}
                    {if $row.claimed}<del class="text-body-secondary">{$row.claimed}</del>{/if}
                    <strong>{$row.attained}</strong>
                    {if $row.pending}<span class="small fst-italic">{$l10n->get('PLG_REQUIREMENTS_PENDING_PLUS', [$row.pending])}</span>{/if}
                </span>
            </div>
            {/if}
        </td>
        <td class="text-end">
            {if $row.required}{$row.required}{else}&ndash;{/if}
            {if $row.lastFulfilment}<div class="small text-body-secondary">{$l10n->get('PLG_REQUIREMENTS_LAST_FULFILMENT')}: {$row.lastFulfilment}</div>{/if}
            {if $row.nextDue}<div class="small text-body-secondary">{$l10n->get('PLG_REQUIREMENTS_NEXT_DUE')} {$row.nextDue}</div>{/if}
        </td>
        {if $reviewsEnabled}
            <td>
                {foreach $row.reviews as $review}
                    <div class="mb-1">
                        <span class="badge {$review.statusClass}">{$review.statusText}</span>
                        {if $review.isActivity}<span class="small text-body-secondary">{$l10n->get('PLG_REQUIREMENTS_ACTIVITY')}</span>{/if}
                        {if $review.reviewer}<span class="small"><i class="bi bi-person"></i> {$review.reviewer nofilter}</span>{/if}
                        {if $review.due}<span class="small {if $review.overdue}text-danger fw-bold{else}text-body-secondary{/if}">{$l10n->get('PLG_REQUIREMENTS_REVIEW_DUE_BY', [$review.due])}</span>{/if}
                        {if $review.message}<i class="bi bi-chat-left-text text-body-secondary" data-bs-toggle="tooltip" title="{$review.message}"></i>{/if}
                        {if $review.resultComment}<i class="bi bi-chat-left-quote text-body-secondary" data-bs-toggle="tooltip" title="{$review.resultComment}"></i>{/if}
                    </div>
                {foreachelse}
                    {if $row.remindSubmission}<span class="badge text-bg-warning">{$l10n->get('PLG_REQUIREMENTS_REVIEW_NOT_SUBMITTED')}</span>{else}&ndash;{/if}
                {/foreach}
            </td>
        {/if}
        <td class="text-end text-nowrap">
            {if $row.contributions|@count gt 0}
                <a class="admidio-icon-link" href="javascript:void(0);" data-bs-toggle="collapse"
                   data-bs-target="#{$row.id}_claims" aria-expanded="false">
                    <i class="bi bi-chevron-expand" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_SHOW_CONTRIBUTIONS')}"></i></a>
            {/if}
            {if $row.activitiesUrl}
                <a class="admidio-icon-link" href="{$row.activitiesUrl}">
                    <i class="bi bi-list-ul" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_SHOW_ACTIVITIES')}"></i></a>
            {/if}
            {if $row.canDecide}
                <a class="admidio-icon-link adm-req-accept-all" href="javascript:void(0);">
                    <i class="bi bi-check2-all" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_ACCEPT_ALL')}"></i></a>
            {/if}
            {foreach $row.reviews as $review}
                {if $review.canFinish}
                    <a class="admidio-icon-link adm-req-finish" href="javascript:void(0);" data-review="{$review.uuid}">
                        <i class="bi bi-flag-fill" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_FINISH')}"></i></a>
                {/if}
                {if $review.canCancel}
                    <a class="admidio-icon-link adm-req-cancel" href="javascript:void(0);" data-review="{$review.uuid}">
                        <i class="bi bi-x-circle" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_CANCEL')}"></i></a>
                {/if}
                {if $review.canRemind}
                    <a class="admidio-icon-link adm-req-remind" href="javascript:void(0);" data-review="{$review.uuid}">
                        <i class="bi bi-bell" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_REMIND')}"></i></a>
                {/if}
            {/foreach}
            {if $row.remindSubmission}
                <a class="admidio-icon-link adm-req-remind" href="javascript:void(0);">
                    <i class="bi bi-bell" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_REMIND')}"></i></a>
            {/if}
            {if $row.canRequest}
                <a class="admidio-icon-link" href="{$row.canRequest}">
                    <i class="bi bi-envelope-paper" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_REQUEST')}"></i></a>
            {/if}
            {if $row.canDecide}
                <button type="button" class="btn btn-sm btn-outline-primary adm-req-save ms-1">
                    <i class="bi bi-check-lg"></i> {$l10n->get('SYS_SAVE')}</button>
            {/if}
            <div class="adm-req-row-alert small" style="display: none;"></div>
        </td>
    </tr>
    {if $row.contributions|@count gt 0}
        <tr class="adm-req-claims">
            <td colspan="{if $reviewsEnabled}5{else}4{/if}" class="p-0 border-0">
                <div class="collapse adm-req-contributions" id="{$row.id}_claims">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 14rem;">{$l10n->get('PLG_REQUIREMENTS_ACTIVITY')}</th>
                                <th>{$l10n->get('SYS_DATE')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD')}</th>
                                <th class="text-end">{$l10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_REVIEW_STATUS')}</th>
                                <th style="min-width: 12rem;">{$l10n->get('PLG_REQUIREMENTS_REVIEW_COMMENTS')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $row.contributions as $c}
                                {include 'requirements.review.credit.tpl' c=$c label=''}
                                {foreach $c.parts as $part}
                                    {include 'requirements.review.credit.tpl' c=$part label=$part.name}
                                {/foreach}
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
    {/if}
</tbody>
