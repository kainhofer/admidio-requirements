<div class="card admidio-field-group mb-3">
    <div class="card-body">
        <form method="get" action="{$searchUrl}" class="row g-2">
            <input type="hidden" name="mode" value="list">
            <div class="col">
                <input type="text" name="query" value="{$query}" class="form-control"
                       placeholder="{$searchPlaceholder}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-secondary">
                    <i class="bi bi-search"></i> {$l10n->get('PLG_REQUIREMENTS_SEARCH')}
                </button>
            </div>
            {if $query neq ''}
                <div class="col-auto">
                    <a href="{$searchUrl}?mode=list" class="btn btn-link">{$l10n->get('PLG_REQUIREMENTS_SHOW_ALL')}</a>
                </div>
            {/if}
        </form>
    </div>
</div>
