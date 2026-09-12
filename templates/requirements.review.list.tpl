<style>
    .adm-req-review .adm-req-track { position: relative; height: 1rem; width: 6rem; background: var(--bs-secondary-bg, #e9ecef); border-radius: .2rem; overflow: hidden; }
    .adm-req-review .adm-req-fill { position: absolute; top: 0; left: 0; bottom: 0; }
    .adm-req-review .adm-req-fill.ok { background: var(--bs-success, #198754); }
    .adm-req-review .adm-req-fill.miss { background: var(--bs-warning, #ffc107); }
    .adm-req-review .adm-req-fill.over { background: var(--bs-danger, #dc3545); }
    .adm-req-review .adm-req-fill.none { background: var(--bs-secondary, #adb5bd); }
    .adm-req-review .adm-req-claims { background: var(--bs-tertiary-bg, #f8f9fa); }
    .adm-req-review .adm-req-accepted { max-width: 7rem; }
</style>

<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}

    {if $rows|@count eq 0}
        <div class="alert alert-info" role="alert">
            <i class="bi bi-info-circle-fill"></i> {$messageNoData}
        </div>
    {else}
        {if $hasContributions}
            <div class="mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="adm_req_toggle_all" data-open="0"
                        data-label-expand="{$l10n->get('PLG_REQUIREMENTS_REVIEW_EXPAND_ALL')}"
                        data-label-collapse="{$l10n->get('PLG_REQUIREMENTS_REVIEW_COLLAPSE_ALL')}">
                    <i class="bi bi-arrows-expand"></i>
                    <span class="adm-req-toggle-label">{$l10n->get('PLG_REQUIREMENTS_REVIEW_EXPAND_ALL')}</span>
                </button>
            </div>
        {/if}

        <div class="table-responsive adm-req-review">
            <table class="table table-condensed table-hover align-middle" style="max-width: 100%;">
                <thead>
                    <tr>
                        <th style="min-width: 12rem;">{$l10n->get('SYS_MEMBER')}</th>
                        <th class="text-end">{$l10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED')}</th>
                        <th class="text-end">{$l10n->get('PLG_REQUIREMENTS_REQUIRED_CREDITS')}</th>
                        <th>{$l10n->get('PLG_REQUIREMENTS_COVERAGE')}</th>
                        {if $showLastFulfilment}
                            <th>{$l10n->get('PLG_REQUIREMENTS_LAST_FULFILMENT')}</th>
                            <th>{$l10n->get('PLG_REQUIREMENTS_NEXT_DUE')}</th>
                        {/if}
                        <th>{$l10n->get('PLG_REQUIREMENTS_STATUS')}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $rows as $row}
                        <tr id="{$row.id}">
                            <td>
                                {$row.name nofilter}
                                {foreach $row.warnings as $warning}
                                    <i class="bi bi-exclamation-triangle-fill text-warning"
                                       data-bs-toggle="tooltip" title="{$warning nofilter}"></i>
                                {/foreach}
                            </td>
                            <td class="text-end">
                                {$row.attained}
                                {if $row.pending}
                                    <div class="text-muted fst-italic small">{$l10n->get('PLG_REQUIREMENTS_PENDING', [$row.pending])}</div>
                                {/if}
                            </td>
                            <td class="text-end">{if $row.required}{$row.required}{else}&ndash;{/if}</td>
                            <td>
                                <div class="adm-req-track">
                                    {if $row.percent is not null}
                                        <div class="adm-req-fill {if $row.status eq 'fulfilled'}ok{elseif $row.status eq 'overdue' or $row.status eq 'never-fulfilled'}over{elseif $row.status eq 'missing'}miss{else}none{/if}"
                                             style="width: {$row.percent}%;"></div>
                                    {/if}
                                </div>
                                {if $row.percent is not null}<span class="small">{$row.percent}%</span>{/if}
                            </td>
                            {if $showLastFulfilment}
                                <td class="small">{if $row.lastFulfilment}{$row.lastFulfilment}{else}&ndash;{/if}</td>
                                <td class="small">{if $row.nextDue}{$row.nextDue}{else}&ndash;{/if}</td>
                            {/if}
                            <td class="small">{$row.statusText}</td>
                            <td class="text-end text-nowrap">
                                <a href="{$row.activitiesUrl}" class="btn btn-sm btn-link p-1"
                                   data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_REVIEW_SHOW_ACTIVITIES')}">
                                    <i class="bi bi-clipboard-check"></i></a>
                                {if $row.contributions|@count gt 0}
                                    <button type="button" class="btn btn-sm btn-link p-1" data-bs-toggle="collapse"
                                            data-bs-target="#{$row.id}_claims" aria-expanded="false"
                                            title="{$l10n->get('PLG_REQUIREMENTS_SHOW_CONTRIBUTIONS')}">
                                        <i class="bi bi-chevron-down"></i></button>
                                {/if}
                            </td>
                        </tr>
                        {if $row.contributions|@count gt 0}
                            <tr class="adm-req-claims">
                                <td colspan="{if $showLastFulfilment}8{else}6{/if}" class="p-0 border-0">
                                    <div class="collapse adm-req-contributions" id="{$row.id}_claims">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="min-width: 14rem;">{$l10n->get('PLG_REQUIREMENTS_ACTIVITY')}</th>
                                                    <th>{$l10n->get('SYS_DATE')}</th>
                                                    <th>{$l10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD')}</th>
                                                    <th class="text-end">{$l10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED')}</th>
                                                    <th>{$l10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED')}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {foreach $row.contributions as $c}
                                                    <tr>
                                                        <td>
                                                            <a href="{$c.url}">{$c.title nofilter}</a>
                                                            {if not $c.counts}
                                                                <span class="badge text-bg-secondary">{$c.statusText}</span>
                                                            {/if}
                                                            {foreach $c.files as $f}
                                                                <a href="{$f.url}" class="badge text-bg-light text-decoration-none"
                                                                   title="{$f.name nofilter}">
                                                                    <i class="bi bi-paperclip"></i> {$f.name nofilter}
                                                                </a>
                                                            {/foreach}
                                                        </td>
                                                        <td class="small">{$c.date}</td>
                                                        <td class="small">{$c.periodReference}</td>
                                                        <td class="text-end">{$c.claimed}</td>
                                                        <td>
                                                            <input type="number" step="0.01" min="0" name="{$c.acceptedId}"
                                                                   id="{$c.acceptedId}" value="{$c.acceptedValue}"
                                                                   class="form-control form-control-sm adm-req-accepted">
                                                        </td>
                                                    </tr>
                                                {/foreach}
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        {/if}
                    {/foreach}
                </tbody>
            </table>
        </div>

        <p class="text-muted small">{$l10n->get('PLG_REQUIREMENTS_REVIEW_ACCEPTED_HELP')}</p>
    {/if}

    <div class="form-alert" style="display: none;">&nbsp;</div>
    {if $hasContributions}
        {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
    {/if}
</form>
