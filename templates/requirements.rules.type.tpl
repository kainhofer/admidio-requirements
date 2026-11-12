    <div class="card admidio-field-group{if $nested} mb-2{/if}" id="{$type.rowId}">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <strong>{$type.name nofilter}</strong>
                {if $type.shortName}<span class="text-muted">({$type.shortName nofilter})</span>{/if}
                {if not $type.active}<span class="badge bg-secondary">{$l10n->get('PLG_REQUIREMENTS_INACTIVE')}</span>{/if}
            </span>
            {if $isAdmin}
            <span class="text-nowrap">
                <a class="admidio-icon-link" href="{$type.addRuleUrl}">
                    <i class="bi bi-plus-circle-fill" data-bs-toggle="tooltip" title="{$l10n->get('PLG_REQUIREMENTS_ADD_ROLE_RULE')}"></i></a>
                <a class="admidio-icon-link" href="{$type.editUrl}">
                    <i class="bi bi-pencil-square" data-bs-toggle="tooltip" title="{$l10n->get('SYS_EDIT')}"></i></a>
                <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                   data-message="{$type.deleteMessage}"
                   data-href="callUrlHideElement('{$type.rowId}', '{$type.deleteUrl}', '{$csrfToken}')">
                    <i class="bi bi-trash" data-bs-toggle="tooltip" title="{$l10n->get('SYS_DELETE')}"></i></a>
            </span>
            {/if}
        </div>
        <div class="card-body">
            {if $type.description}<p class="mb-2">{$type.description nofilter}</p>{/if}
            {if $isAdmin}
            <p class="text-muted mb-2">
                {$l10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_MODE')}: {$type.multipleRoles}
            </p>
            {/if}
            {if $type.rules|@count eq 0}
                <p class="text-muted mb-0">{$l10n->get('PLG_REQUIREMENTS_NO_ROLE_RULES')}</p>
            {else}
                <div class="table-responsive">
                    <table class="table table-condensed table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{$l10n->get('SYS_ROLE')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_PERIOD_UNIT')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_REQUIRED_CREDITS')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_VALIDITY')}</th>
                                <th>{$l10n->get('PLG_REQUIREMENTS_DETAILS')}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $type.rules as $rule}
                                <tr id="{$rule.rowId}">
                                    <td>{$rule.roleName nofilter}</td>
                                    <td>{$type.period}</td>
                                    {if $isAdmin}
                                    {* Edited in place: a double-click turns the value into a field, Enter or leaving the
                                       field saves, Escape drops the change. *}
                                    <td><span class="adm-req-inline" data-url="{$rule.inlineUrl}" data-field="rrr_required_credits"
                                              data-type="number" data-value="{$rule.credits}"
                                              title="{$l10n->get('PLG_REQUIREMENTS_INLINE_EDIT_HINT')}">{$rule.creditsText}</span></td>
                                    <td class="text-nowrap">
                                        <span class="adm-req-inline" data-url="{$rule.inlineUrl}" data-field="rrr_valid_from"
                                              data-type="date" data-value="{$rule.validFrom}"
                                              title="{$l10n->get('PLG_REQUIREMENTS_INLINE_EDIT_HINT')}">{$rule.validFromText}</span>
                                        &ndash;
                                        <span class="adm-req-inline" data-url="{$rule.inlineUrl}" data-field="rrr_valid_until"
                                              data-type="date" data-value="{$rule.validUntil}"
                                              title="{$l10n->get('PLG_REQUIREMENTS_INLINE_EDIT_HINT')}">{$rule.validUntilText}</span>
                                    </td>
                                    {else}
                                    <td>{$rule.credits}</td>
                                    <td>{$rule.validity}</td>
                                    {/if}
                                    <td class="text-muted small">{$rule.details nofilter}</td>
                                    <td class="text-end">
                                        {if $isAdmin}
                                        <a class="admidio-icon-link" href="{$rule.editUrl}">
                                            <i class="bi bi-pencil-square" data-bs-toggle="tooltip" title="{$l10n->get('SYS_EDIT')}"></i></a>
                                        <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                                           data-message="{$rule.deleteAction.dataMessage}"
                                           data-href="{$rule.deleteAction.dataHref}">
                                            <i class="bi bi-trash" data-bs-toggle="tooltip" title="{$l10n->get('SYS_DELETE')}"></i></a>
                                        {/if}
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {/if}
            {if $type.parts|@count gt 0}
                <div class="mt-3 ms-4">
                    <div class="text-muted small mb-1"><i class="bi bi-arrow-return-right"></i> {$l10n->get('PLG_REQUIREMENTS_SUB_REQUIREMENTS')}</div>
                    {foreach $type.parts as $part}
                        {include 'requirements.rules.type.tpl' type=$part nested=true}
                    {/foreach}
                </div>
            {/if}
        </div>
    </div>
