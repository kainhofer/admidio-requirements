{if $types|@count eq 0}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$messageNoData}
    </div>
{else}
    {foreach $types as $type}
        <div class="card admidio-field-group" id="{$type.rowId}">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <strong>{$type.name nofilter}</strong>
                    {if $type.shortName}<span class="text-muted">({$type.shortName nofilter})</span>{/if}
                    {if not $type.active}<span class="badge bg-secondary">{$l10n->get('PLG_REQUIREMENTS_INACTIVE')}</span>{/if}
                </span>
                <span class="admidio-icon-link">
                    <a class="admidio-icon-link" href="{$type.addRuleUrl}">
                        <i class="bi bi-plus-circle-fill" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_ADD_ROLE_RULE')}"></i></a>
                    <a class="admidio-icon-link" href="{$type.editUrl}">
                        <i class="bi bi-pencil-square" data-bs-toggle="tooltip" title="{$l10n->get('SYS_EDIT')}"></i></a>
                    <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                       data-message="{$type.deleteMessage}"
                       data-href="callUrlHideElement('{$type.rowId}', '{$type.deleteUrl}', '{$type.csrfToken}')">
                        <i class="bi bi-trash" data-bs-toggle="tooltip" title="{$l10n->get('SYS_DELETE')}"></i></a>
                </span>
            </div>
            <div class="card-body">
                <p class="text-muted mb-2">
                    {$l10n->get('PLG_REQUIREMENTS_PERIOD_UNIT')}: {$type.period}
                    &nbsp;|&nbsp; {$l10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_MODE')}: {$type.multipleRoles}
                </p>
                {if $type.rules|@count eq 0}
                    <p class="text-muted mb-0">{$l10n->get('PLG_REQUIREMENTS_NO_ROLE_RULES')}</p>
                {else}
                    <div class="table-responsive">
                        <table class="table table-condensed table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{$l10n->get('SYS_ROLE')}</th>
                                    <th>{$l10n->get('PLG_REQUIREMENTS_REQUIRED_CREDITS')}</th>
                                    <th>{$l10n->get('PLG_REQUIREMENTS_PARTIAL_PERIOD_MODE')}</th>
                                    <th>{$l10n->get('PLG_REQUIREMENTS_PRORATION_BASIS')}</th>
                                    <th>{$l10n->get('PLG_REQUIREMENTS_MEMBERSHIP_AT_PERIOD_START')}</th>
                                    <th>{$l10n->get('PLG_REQUIREMENTS_VALIDITY')}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach $type.rules as $rule}
                                    <tr id="{$rule.rowId}">
                                        <td>{$rule.roleName nofilter}</td>
                                        <td>{$rule.credits}</td>
                                        <td>{$rule.mode}</td>
                                        <td>{if $rule.mode eq 'pro_rata'}{$rule.basis}{else}&ndash;{/if}</td>
                                        <td>{if $rule.periodStart}{$l10n->get('SYS_YES')}{else}{$l10n->get('SYS_NO')}{/if}</td>
                                        <td>{$rule.validity}</td>
                                        <td class="text-end">
                                            <a class="admidio-icon-link" href="{$rule.editUrl}">
                                                <i class="bi bi-pencil-square" data-bs-toggle="tooltip" title="{$l10n->get('SYS_EDIT')}"></i></a>
                                            <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                                               data-message="{$rule.deleteAction.dataMessage}"
                                               data-href="{$rule.deleteAction.dataHref}">
                                                <i class="bi bi-trash" data-bs-toggle="tooltip" title="{$l10n->get('SYS_DELETE')}"></i></a>
                                        </td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {/if}
            </div>
        </div>
    {/foreach}
{/if}
