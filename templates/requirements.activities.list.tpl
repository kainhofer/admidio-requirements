<style>
    .adm-req-overview .adm-req-row { display: flex; align-items: center; gap: 1rem; padding: .35rem 0; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
    .adm-req-overview .adm-req-name { flex: 0 0 12rem; font-weight: 600; }
    .adm-req-overview .adm-req-bars { display: flex; gap: .5rem; flex-wrap: wrap; flex: 1 1 auto; }
    .adm-req-overview .adm-req-bar { flex: 1 1 5rem; min-width: 4.5rem; }
    .adm-req-overview .adm-req-bar-label { font-size: .75rem; color: var(--bs-secondary-color, #6c757d); }
    .adm-req-overview .adm-req-track { position: relative; height: 1.1rem; background: var(--bs-secondary-bg, #e9ecef); border-radius: .2rem; overflow: hidden; }
    .adm-req-overview .adm-req-fill { position: absolute; top: 0; left: 0; bottom: 0; }
    .adm-req-overview .adm-req-fill.ok { background: var(--bs-success, #198754); }
    .adm-req-overview .adm-req-fill.miss { background: var(--bs-warning, #ffc107); }
    .adm-req-overview .adm-req-fill.none { background: var(--bs-secondary, #adb5bd); }
    .adm-req-overview .adm-req-val { font-size: .75rem; }
</style>

{if $overview|@count eq 0 and $overviewHint}
    <div class="card admidio-field-group mb-3">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_OVERVIEW_HEADLINE')}</div>
        <div class="card-body"><p class="text-muted mb-0">{$overviewHint}</p></div>
    </div>
{/if}

{if $overview|@count gt 0}
    <div class="card admidio-field-group adm-req-overview mb-3">
        <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_OVERVIEW_HEADLINE')}</div>
        <div class="card-body">
            {foreach $overview as $req}
                <div class="adm-req-row">
                    <div class="adm-req-name" title="{$req.requirement}">
                        {if $req.shortName}{$req.shortName}{else}{$req.requirement}{/if}
                    </div>
                    <div class="adm-req-bars">
                        {foreach $req.periods as $p}
                            <div class="adm-req-bar">
                                <div class="adm-req-bar-label">{$p.label}</div>
                                <div class="adm-req-track">
                                    {if $p.percent is not null}
                                        <div class="adm-req-fill {if $p.status eq 'fulfilled'}ok{elseif $p.status eq 'missing'}miss{else}none{/if}"
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
                            </div>
                        {/foreach}
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
{/if}

<div class="card admidio-field-group mb-3">
    <div class="card-body">
        <form method="get" action="{$searchUrl}" class="row g-2 align-items-end">
            <input type="hidden" name="mode" value="list">
            <div class="col-sm">
                <label class="form-label small mb-1">{$l10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE')}</label>
                <select name="requirement" class="form-select" onchange="this.form.submit()">
                    {foreach $typeOptions as $t}
                        <option value="{$t.uuid}" {if $t.selected}selected{/if}>{$t.name nofilter}</option>
                    {/foreach}
                </select>
            </div>
            <div class="col-sm">
                <label class="form-label small mb-1">{$l10n->get('PLG_REQUIREMENTS_SEARCH')}</label>
                <input type="text" name="query" value="{$query}" class="form-control" placeholder="{$searchPlaceholder}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-secondary"><i class="bi bi-search"></i> {$l10n->get('PLG_REQUIREMENTS_SEARCH')}</button>
            </div>
        </form>
    </div>
</div>

{if $rows|@count eq 0}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$messageNoData}
    </div>
{else}
    <div class="table-responsive">
        <table class="table table-condensed table-hover" style="max-width: 100%;">
            <thead>
                <tr>{foreach $headers as $header}<th>{$header}</th>{/foreach}</tr>
            </thead>
            <tbody>
                {foreach $rows as $row}
                    <tr id="{$row.id}">
                        {foreach $row.data as $cell}
                            <td style="word-break: break-word;">{$cell nofilter}</td>
                        {/foreach}
                        <td class="text-end text-nowrap">
                            {$row.statusIcon nofilter}
                            {include 'sys-template-parts/list.functions.tpl' data=$row}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}
