{* The rows of the review page. Each row is saved on its own (requirements.review.row.tpl). *}
<style>
    .adm-req-review .adm-req-credit-cell { position: relative; min-width: 9rem; padding: .15rem .4rem; border-radius: .2rem; background: var(--bs-secondary-bg, #e9ecef); overflow: hidden; }
    .adm-req-review .adm-req-credit-cell .adm-req-fill { position: absolute; top: 0; left: 0; bottom: 0; opacity: .35; }
    .adm-req-review .adm-req-credit-cell .adm-req-fill-pending { position: absolute; top: 0; bottom: 0; opacity: .35;
        background: repeating-linear-gradient(45deg, var(--bs-secondary, #6c757d) 0 4px, transparent 4px 8px); }
    .adm-req-review .adm-req-status-fulfilled .adm-req-fill { background: var(--bs-success, #198754); }
    .adm-req-review .adm-req-status-missing .adm-req-fill,
    .adm-req-review .adm-req-status-in-progress .adm-req-fill { background: var(--bs-warning, #ffc107); }
    .adm-req-review .adm-req-status-overdue .adm-req-fill,
    .adm-req-review .adm-req-status-never-fulfilled .adm-req-fill { background: var(--bs-danger, #dc3545); }
    .adm-req-review .adm-req-credit-cell .adm-req-fill { background: var(--bs-secondary, #adb5bd); }
    .adm-req-review .adm-req-credit-text { position: relative; }
    .adm-req-review .adm-req-claims { background: var(--bs-tertiary-bg, #f8f9fa); }
    .adm-req-review tbody.adm-req-dirty > tr:first-child > td:first-child { box-shadow: inset 3px 0 0 var(--bs-primary, #0d6efd); }
</style>

{if $rows|@count eq 0}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$messageNoData}
    </div>
{else}
    <div class="mb-2">
        <button type="button" class="btn btn-sm btn-secondary" id="adm_req_toggle_all" data-open="0"
                data-label-expand="{$l10n->get('PLG_REQUIREMENTS_REVIEW_EXPAND_ALL')}"
                data-label-collapse="{$l10n->get('PLG_REQUIREMENTS_REVIEW_COLLAPSE_ALL')}">
            <i class="bi bi-arrows-expand"></i>
            <span class="adm-req-toggle-label">{$l10n->get('PLG_REQUIREMENTS_REVIEW_EXPAND_ALL')}</span>
        </button>
    </div>

    <div class="table-responsive adm-req-review">
        <table class="table table-condensed align-middle" style="max-width: 100%;">
            <thead>
                <tr>
                    <th style="min-width: 12rem;">{$l10n->get('SYS_MEMBER')}</th>
                    <th class="text-end">{$l10n->get('PLG_REQUIREMENTS_CREDITS')}</th>
                    <th class="text-end">{$l10n->get('PLG_REQUIREMENTS_REQUIRED')}</th>
                    {if $reviewsEnabled}<th>{$l10n->get('PLG_REQUIREMENTS_REVIEW')}</th>{/if}
                    <th></th>
                </tr>
            </thead>
            {foreach $rows as $row}
                {include 'requirements.review.row.tpl' row=$row}
            {/foreach}
        </table>
    </div>

    <p class="text-muted small">{$l10n->get('PLG_REQUIREMENTS_REVIEW_ACCEPTED_HELP')}</p>
{/if}

<div class="modal fade" id="adm_req_finish_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">{$l10n->get('PLG_REQUIREMENTS_REVIEW_FINISH')}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$l10n->get('SYS_CLOSE')}"></button>
            </div>
            <div class="modal-body">
                <p>{$l10n->get('PLG_REQUIREMENTS_REVIEW_FINISH_DESC')}</p>
                <label for="adm_req_finish_comment" class="form-label">{$l10n->get('PLG_REQUIREMENTS_REVIEW_RESULT_COMMENT')}</label>
                <textarea id="adm_req_finish_comment" class="form-control" rows="3"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$l10n->get('SYS_CANCEL')}</button>
                <button type="button" class="btn btn-primary" id="adm_req_finish_confirm"><i class="bi bi-flag-fill"></i> {$l10n->get('PLG_REQUIREMENTS_REVIEW_FINISH')}</button>
            </div>
        </div>
    </div>
</div>
