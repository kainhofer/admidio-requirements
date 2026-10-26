<style>
    .adm-req-overview .adm-req-row { display: flex; align-items: center; gap: 1rem; padding: .35rem 0; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
    .adm-req-overview .adm-req-name { flex: 0 0 12rem; font-weight: 600; }
    .adm-req-overview .adm-req-bars { display: flex; gap: .5rem; flex-wrap: wrap; flex: 1 1 auto; }
    .adm-req-overview .adm-req-bar { flex: 1 1 5rem; min-width: 4.5rem; }
    .adm-req-overview .adm-req-bar-label { font-size: .75rem; font-weight: 700; }
    .adm-req-overview .adm-req-track { position: relative; height: 1.1rem; background: var(--bs-secondary-bg, #e9ecef); border-radius: .2rem; overflow: hidden; }
    .adm-req-overview .adm-req-fill { position: absolute; top: 0; left: 0; bottom: 0; }
    .adm-req-overview .adm-req-fill.ok { background: var(--bs-success, #198754); }
    .adm-req-overview .adm-req-fill.miss { background: var(--bs-warning, #ffc107); }
    .adm-req-overview .adm-req-fill.over { background: var(--bs-danger, #dc3545); }
    .adm-req-overview .adm-req-fill.none { background: var(--bs-secondary, #adb5bd); }
    .adm-req-overview .adm-req-val { font-size: .75rem; }
    .adm-req-overview .adm-req-bar-link { display: block; text-decoration: none; color: inherit; }
    .adm-req-overview .adm-req-track.has-target { outline: 1px solid var(--bs-secondary-color, #6c757d); }
    .adm-req-overview .adm-req-bar-link:hover .adm-req-track { outline: 2px solid var(--bs-primary, #0d6efd); }
    .adm-req-overview .card-header .adm-req-collapse-icon { display: inline-block; transition: transform .2s; }
    .adm-req-overview .card-header.collapsed .adm-req-collapse-icon { transform: rotate(-90deg); }
    {* Grouped rows sit one step in from their heading, whichever column happens to come first. *}
    #adm_req_activities_table.adm-req-grouped > tbody > tr:not(.admidio-group-heading) > td:first-child { padding-left: 2rem; }
    .adm-req-overview .adm-req-pending { font-size: .7rem; font-style: italic; color: var(--bs-secondary-color, #6c757d); }
</style>

{if $overview|@count eq 0 and $overviewHint}
    <div class="card admidio-field-group mb-3">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_OVERVIEW_HEADLINE')}</div>
        <div class="card-body"><p class="text-muted mb-0">{$overviewHint}</p></div>
    </div>
{/if}

{if $overview|@count gt 0}
    <div class="card admidio-field-group adm-req-overview mb-3">
        <div class="card-header" id="adm_req_overview_header" role="button" data-bs-toggle="collapse"
             data-bs-target="#adm_req_overview_body" aria-expanded="true" aria-controls="adm_req_overview_body">
            <i class="bi bi-chevron-down adm-req-collapse-icon"></i> {$l10n->get('PLG_REQUIREMENTS_OVERVIEW_HEADLINE')}
        </div>
        <div class="collapse show" id="adm_req_overview_body"><div class="card-body">
            {foreach $overview as $req}
                <div class="adm-req-row">
                    <div class="adm-req-name" title="{$req.requirement}">
                        {if $req.shortName}{$req.shortName}{else}{$req.requirement}{/if}
                        {if $req.own}<i class="bi bi-person-check text-muted" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENT')}"></i>{/if}
                    </div>
                    <div class="adm-req-bars">
                        {if $req.periods|@count eq 0}<div class="adm-req-val">&ndash;</div>{/if}
                        {foreach $req.periods as $p}
                            <div class="adm-req-bar">
                                {if $p.url}<a class="adm-req-bar-link" href="{$p.url}"
                                    title="{$l10n->get('PLG_REQUIREMENTS_SHOW_CONTRIBUTIONS')}">{/if}
                                <div class="adm-req-bar-label">{$p.label}</div>
                                <div class="adm-req-track{if $p.required is not null} has-target{/if}">
                                    {if $p.percent is not null}
                                        <div class="adm-req-fill {if $p.status eq 'fulfilled'}ok{elseif $p.status eq 'overdue' or $p.status eq 'never-fulfilled'}over{elseif $p.status eq 'missing'}miss{else}none{/if}"
                                             style="width: {$p.percent}%;"></div>
                                    {/if}
                                </div>
                                <div class="adm-req-val">
                                    {if $p.required is not null}
                                        {$p.attained} / {$p.required}{if $p.percent is not null} ({$p.percent}%){/if}
                                    {elseif $p.attained gt 0}
                                        {$p.attained}
                                    {else}
                                        &ndash;
                                    {/if}
                                </div>
                                {if $p.pending gt 0}
                                    <div class="adm-req-pending">{$l10n->get('PLG_REQUIREMENTS_PENDING', [$p.pending])}</div>
                                {/if}
                                {if $p.url}</a>{/if}
                            </div>
                        {/foreach}
                    </div>
                </div>
            {/foreach}
            {if $overviewEarlierUrl or $overviewLaterUrl}
                <div class="d-flex justify-content-between mt-2">
                    <div>{if $overviewEarlierUrl}<a class="btn btn-sm btn-secondary" href="{$overviewEarlierUrl}"><i class="bi bi-chevron-left"></i> {$l10n->get('PLG_REQUIREMENTS_OVERVIEW_EARLIER')}</a>{/if}</div>
                    <div>{if $overviewLaterUrl}<a class="btn btn-sm btn-secondary" href="{$overviewLaterUrl}">{$l10n->get('PLG_REQUIREMENTS_OVERVIEW_LATER')} <i class="bi bi-chevron-right"></i></a>{/if}</div>
                </div>
            {/if}
        </div></div>
    </div>
    <script>
        {* Remember whether the overview was folded away; it is open for a viewer who never chose. *}
        (function () {
            var key = "admReqOverviewCollapsed";
            var body = document.getElementById("adm_req_overview_body");
            var header = document.getElementById("adm_req_overview_header");
            try {
                if (window.localStorage.getItem(key) === "1") {
                    body.classList.remove("show");
                    header.classList.add("collapsed");
                    header.setAttribute("aria-expanded", "false");
                }
            } catch (e) { }
            body.addEventListener("hidden.bs.collapse", function () {
                try { window.localStorage.setItem(key, "1"); } catch (e) { }
            });
            body.addEventListener("shown.bs.collapse", function () {
                try { window.localStorage.removeItem(key); } catch (e) { }
            });
        })();
    </script>
{/if}

<div class="card admidio-field-group mb-3">
    <div class="card-body">
        <form id="adm_req_filter" class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg">
                <label class="form-label small mb-1" for="adm_req_requirement">{$l10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE')}</label>
                <select id="adm_req_requirement" class="form-select">
                    <option value="">{$l10n->get('PLG_REQUIREMENTS_ALL_REQUIREMENTS')}</option>
                    {foreach $typeOptions as $t}
                        <option value="{$t.uuid}" {if $t.selected}selected{/if}>{$t.name nofilter}</option>
                    {/foreach}
                </select>
            </div>
            <div class="col-sm-6 col-lg">
                <label class="form-label small mb-1" for="adm_req_provider">{$l10n->get('PLG_REQUIREMENTS_PROVIDER')}</label>
                <select id="adm_req_provider" class="form-select">
                    <option value="">{$l10n->get('PLG_REQUIREMENTS_ALL_PROVIDERS')}</option>
                    {foreach $providerOptions as $p}
                        <option value="{$p.uuid}">{$p.name nofilter}</option>
                    {/foreach}
                </select>
            </div>
            <div class="col-6 col-lg-auto">
                <label class="form-label small mb-1" for="adm_req_from">{$l10n->get('PLG_REQUIREMENTS_DATE_FROM')}</label>
                <input type="date" id="adm_req_from" class="form-control">
            </div>
            <div class="col-6 col-lg-auto">
                <label class="form-label small mb-1" for="adm_req_to">{$l10n->get('PLG_REQUIREMENTS_DATE_UNTIL')}</label>
                <input type="date" id="adm_req_to" class="form-control">
            </div>
            <div class="col-sm-12 col-lg">
                <label class="form-label small mb-1" for="adm_req_query">{$l10n->get('PLG_REQUIREMENTS_SEARCH')}</label>
                <input type="search" id="adm_req_query" value="{$query}" class="form-control" placeholder="{$searchPlaceholder}">
            </div>
        </form>
    </div>
</div>

{if $periodLabel or $userLabel}
    <div class="alert alert-secondary d-flex justify-content-between align-items-center" role="alert">
        <span><i class="bi bi-funnel-fill"></i>
            {if $userLabel}{$l10n->get('PLG_REQUIREMENTS_CONTRIBUTIONS_OF', [$userLabel])} {/if}
            {if $periodLabel}{$l10n->get('PLG_REQUIREMENTS_CONTRIBUTIONS_TO', [$periodLabel])}{/if}</span>
        <a href="{$clearFilterUrl}" class="btn btn-sm btn-secondary">
            {$l10n->get('PLG_REQUIREMENTS_SHOW_ALL')}</a>
    </div>
{/if}

<div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-2">
    <label class="small" for="adm_req_group">{$l10n->get('PLG_REQUIREMENTS_GROUP_BY')}</label>
    <select id="adm_req_group" class="form-select form-select-sm w-auto">
        {foreach $groupOptions as $value => $label}
            <option value="{$value}">{$label}</option>
        {/foreach}
    </select>
    <span id="adm_req_granularity_wrap">
        <select id="adm_req_granularity" class="form-select form-select-sm w-auto" aria-label="{$l10n->get('PLG_REQUIREMENTS_GROUP_BY')}">
            {foreach $granularityOptions as $value => $label}
                <option value="{$value}">{$label}</option>
            {/foreach}
        </select>
    </span>
    <div class="dropdown">
        <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown"
                data-bs-auto-close="outside" aria-expanded="false">
            <i class="bi bi-layout-three-columns"></i> {$l10n->get('PLG_REQUIREMENTS_COLUMNS')}
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            {foreach $columns as $c}
                <li><label class="dropdown-item">
                    <input type="checkbox" class="form-check-input me-2 adm-req-col-toggle" data-column="{$c.key}"> {$c.label}
                </label></li>
            {/foreach}
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" id="adm_req_view_reset" href="javascript:void(0);">
                <i class="bi bi-arrow-counterclockwise"></i> {$l10n->get('PLG_REQUIREMENTS_VIEW_RESET')}</a></li>
        </ul>
    </div>
    {if $filesEnabled}
        <div class="dropdown">
            <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown"
                    aria-expanded="false">
                <i class="bi bi-download"></i> {$l10n->get('PLG_REQUIREMENTS_EXPORT')}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item adm-req-export" href="javascript:void(0);">
                    <i class="bi bi-file-earmark-spreadsheet"></i> {$l10n->get('PLG_REQUIREMENTS_EXPORT_SPREADSHEET')}</a></li>
                <li><a class="dropdown-item adm-req-export" href="javascript:void(0);" data-certificates="1">
                    <i class="bi bi-file-earmark-zip"></i> {$l10n->get('PLG_REQUIREMENTS_EXPORT_WITH_CERTIFICATES')}</a></li>
            </ul>
        </div>
    {else}
        <button type="button" class="btn btn-sm btn-secondary adm-req-export">
            <i class="bi bi-file-earmark-spreadsheet"></i> {$l10n->get('PLG_REQUIREMENTS_EXPORT')}
        </button>
    {/if}
</div>

<table id="adm_req_activities_table" class="table table-condensed table-hover" style="width: 100%;">
    <thead>
        <tr>{foreach $columns as $c}<th>{$c.label}</th>{/foreach}<th>&nbsp;</th></tr>
    </thead>
</table>
