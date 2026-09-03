{if $noRequirements}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$noRequirements}
    </div>
{else}
    <div class="card admidio-field-group mb-3">
        <div class="card-body">
            <form method="get" action="{$filterUrl}" class="row g-2 align-items-end">
                <input type="hidden" name="mode" value="list">
                <div class="col-sm">
                    <label class="form-label small mb-1" for="adm_req_review_requirement">{$l10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE')}</label>
                    <select name="requirement" id="adm_req_review_requirement" class="form-select" onchange="this.form.submit()">
                        {foreach $typeOptions as $t}
                            <option value="{$t.uuid}" {if $t.selected}selected{/if}>{$t.name nofilter}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="col-sm-auto">
                    <label class="form-label small mb-1" for="adm_req_review_period">{$periodLabel}</label>
                    {if $periodKind eq 'year'}
                        <input type="number" step="1" min="1900" max="{$maxYear}" name="period" id="adm_req_review_period"
                               value="{$periodValue}" class="form-control">
                    {elseif $periodKind eq 'quarter'}
                        <div class="input-group">
                            <input type="number" step="1" min="1900" max="{$maxYear}" name="period" id="adm_req_review_period"
                                   value="{$periodValue}" class="form-control">
                            <select name="periodq" class="form-select">
                                {foreach [1,2,3,4] as $q}
                                    <option value="{$q}" {if $quarterValue eq $q}selected{/if}>Q{$q}</option>
                                {/foreach}
                            </select>
                        </div>
                    {elseif $periodKind eq 'month'}
                        <input type="month" name="period" id="adm_req_review_period" value="{$periodValue}" class="form-control">
                    {elseif $periodKind eq 'week'}
                        <input type="week" name="period" id="adm_req_review_period" value="{$periodValue}" class="form-control">
                    {else}
                        <input type="date" name="period" id="adm_req_review_period" value="{$periodValue}" class="form-control">
                    {/if}
                </div>
                <div class="col-sm-auto">
                    <label class="form-label small mb-1" for="adm_req_review_status">{$l10n->get('PLG_REQUIREMENTS_STATUS')}</label>
                    <select name="status" id="adm_req_review_status" class="form-select">
                        <option value="">{$l10n->get('SYS_ALL')}</option>
                        {foreach $statusEntries as $s}
                            <option value="{$s.value}" {if $s.selected}selected{/if}>{$s.name}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-secondary"><i class="bi bi-funnel"></i> {$l10n->get('SYS_FILTER')}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="h5 mb-0">{$coverageHeadline nofilter}</h2>
        <div class="text-muted small">{$summary}</div>
    </div>
{/if}
