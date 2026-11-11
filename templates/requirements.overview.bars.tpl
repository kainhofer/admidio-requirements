{* The bars of the fulfilment overview, one row per requirement: for the activity list and for the
   widget of the overview page. Expects $overview as ActivityPresenter::linkOverview() returns it,
   inside an element with the class adm-req-overview; adm-req-compact stacks name and bars. *}
<style>
    .adm-req-overview .adm-req-row { display: flex; align-items: center; gap: 1rem; padding: .35rem 0; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
    .adm-req-overview .adm-req-name { flex: 0 0 12rem; font-weight: 600; }
    .adm-req-overview .adm-req-bars { display: flex; gap: .5rem; flex-wrap: wrap; flex: 1 1 auto; }
    .adm-req-overview .adm-req-bar { flex: 1 1 5rem; min-width: 4.5rem; }
    .adm-req-overview .adm-req-bar-label { font-size: .75rem; font-weight: 700; }
    .adm-req-overview .adm-req-track { position: relative; height: 1.2rem; background: var(--bs-secondary-bg, #e9ecef); border-radius: .2rem; overflow: hidden; }
    .adm-req-overview .adm-req-fill { position: absolute; top: 0; left: 0; bottom: 0; }
    .adm-req-overview .adm-req-fill.ok { background: var(--bs-success, #198754); }
    .adm-req-overview .adm-req-fill.miss { background: var(--bs-warning, #ffc107); }
    .adm-req-overview .adm-req-fill.over { background: var(--bs-danger, #dc3545); }
    .adm-req-overview .adm-req-fill.none { background: var(--bs-secondary, #adb5bd); }
    .adm-req-overview .adm-req-val { font-size: .75rem; }
    .adm-req-overview .adm-req-val-in { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; line-height: 1; white-space: nowrap; color: #212529; text-shadow: 0 0 2px #fff, 0 0 3px #fff; }
    .adm-req-overview .adm-req-bar-link { display: block; text-decoration: none; color: inherit; }
    .adm-req-overview .adm-req-track.has-target { outline: 1px solid var(--bs-secondary-color, #6c757d); }
    .adm-req-overview .adm-req-bar-link:hover .adm-req-track { outline: 2px solid var(--bs-primary, #0d6efd); }
    .adm-req-overview .adm-req-pending { font-size: .7rem; font-style: italic; color: var(--bs-secondary-color, #6c757d); }
    .adm-req-overview .adm-req-row.has-parts { border-bottom: 0; padding-bottom: .1rem; }
    .adm-req-overview .adm-req-subrow { padding: .1rem 0 .35rem; }
    .adm-req-overview .adm-req-subrow.not-last { border-bottom: 0; padding-bottom: .1rem; }
    .adm-req-overview .adm-req-subrow .adm-req-name { padding-left: 1.25rem; font-weight: 400; }
    .adm-req-overview .adm-req-track.adm-req-part-track { height: 1rem; }
    .adm-req-overview .adm-req-part-track .adm-req-val { font-size: .7rem; }
    .adm-req-overview.adm-req-compact .adm-req-row { flex-direction: column; align-items: stretch; gap: .25rem; }
    .adm-req-overview.adm-req-compact .adm-req-name { flex: none; }
</style>
{foreach $overview as $req}
    <div class="adm-req-row{if $req.partNames|@count gt 0} has-parts{/if}">
        <div class="adm-req-name" title="{$req.requirement}">
            {if $req.shortName}{$req.shortName}{else}{$req.requirement}{/if}
            {if $req.own}<i class="bi bi-person-check text-muted" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENT')}"></i>{/if}
        </div>
        <div class="adm-req-bars">
            {if $req.periods|@count eq 0}<div class="adm-req-val">&ndash;</div>{/if}
            {foreach $req.periods as $p}
                <div class="adm-req-bar">
                    {include 'requirements.overview.track.tpl' p=$p label=true name=''}
                    {if $p.pending gt 0}
                        <div class="adm-req-pending">{$l10n->get('PLG_REQUIREMENTS_PENDING', [$p.pending])}</div>
                    {/if}
                </div>
            {/foreach}
        </div>
    </div>
    {foreach $req.partNames as $k => $partName}
        <div class="adm-req-row adm-req-subrow{if not $partName@last} not-last{/if}">
            <div class="adm-req-name" title="{$partName}"><i class="bi bi-arrow-return-right text-muted"></i> {$partName}</div>
            <div class="adm-req-bars">
                {foreach $req.periods as $p}
                    <div class="adm-req-bar">
                        {if $p.parts[$k].period}
                            {include 'requirements.overview.track.tpl' p=$p.parts[$k].period label=false name=''}
                        {else}
                            <div class="adm-req-track adm-req-part-track"><div class="adm-req-val adm-req-val-in">&ndash;</div></div>
                        {/if}
                    </div>
                {/foreach}
            </div>
        </div>
    {/foreach}
{/foreach}
