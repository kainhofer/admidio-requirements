<p class="text-muted">{$l10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENTS_DESC')}</p>
{if $types|@count eq 0}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$l10n->get('PLG_REQUIREMENTS_OWN_NONE')}
    </div>
{else}
    <div class="table-responsive">
        <table class="table table-condensed table-hover">
            <thead>
                <tr>
                    <th>{$l10n->get('SYS_NAME')}</th>
                    <th>{$l10n->get('PLG_REQUIREMENTS_PERIOD_UNIT')}</th>
                    <th>{$l10n->get('PLG_REQUIREMENTS_REQUIRED_CREDITS')}</th>
                    <th>{$l10n->get('PLG_REQUIREMENTS_VALIDITY')}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach $types as $type}
                    <tr id="{$type.rowId}">
                        <td>
                            <strong>{$type.name nofilter}</strong>
                            {if $type.shortName}<span class="text-muted">({$type.shortName nofilter})</span>{/if}
                            {if not $type.active}<span class="badge bg-secondary">{$l10n->get('PLG_REQUIREMENTS_INACTIVE')}</span>{/if}
                            {if $type.description}<div class="text-muted small">{$type.description nofilter}</div>{/if}
                        </td>
                        <td>{$type.period}</td>
                        <td>{$type.credits}</td>
                        <td>{$type.validity}</td>
                        <td class="text-end text-nowrap">
                            <a class="admidio-icon-link" href="{$type.editUrl}">
                                <i class="bi bi-pencil-square" data-bs-toggle="tooltip" title="{$l10n->get('SYS_EDIT')}"></i></a>
                            <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                               data-message="{$type.deleteMessage}"
                               data-href="callUrlHideElement('{$type.rowId}', '{$type.deleteUrl}', '{$csrfToken}')">
                                <i class="bi bi-trash" data-bs-toggle="tooltip" title="{$l10n->get('SYS_DELETE')}"></i></a>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}
