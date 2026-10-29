{if isset($searchUrl)}{include 'requirements.search.tpl'}{/if}

{* The member's own requirements and their own targets for requirements of the organization. *}
<h3 class="admidio-content-subheader">{$l10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENTS')}</h3>
<p class="text-muted">{$l10n->get('PLG_REQUIREMENTS_OWN_REQUIREMENTS_DESC')}</p>
{if $ownRequirements|@count eq 0}
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
                {foreach $ownRequirements as $own}
                    <tr id="{$own.rowId}">
                        <td>
                            <strong>{$own.name nofilter}</strong>
                            {if $own.shortName}<span class="text-muted">({$own.shortName nofilter})</span>{/if}
                            {if $own.target}<span class="badge bg-info">{$l10n->get('PLG_REQUIREMENTS_OWN_TARGET')}</span>{/if}
                            {if not $own.active}<span class="badge bg-secondary">{$l10n->get('PLG_REQUIREMENTS_INACTIVE')}</span>{/if}
                            {if $own.description}<div class="text-muted small">{$own.description nofilter}</div>{/if}
                        </td>
                        <td>{$own.period}</td>
                        <td>{$own.credits}</td>
                        <td>{$own.validity}</td>
                        <td class="text-end text-nowrap">
                            <a class="admidio-icon-link" href="{$own.editUrl}">
                                <i class="bi bi-pencil-square" data-bs-toggle="tooltip" title="{$l10n->get('SYS_EDIT')}"></i></a>
                            <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                               data-message="{$own.deleteMessage}"
                               data-href="callUrlHideElement('{$own.rowId}', '{$own.deleteUrl}', '{$csrfToken}')">
                                <i class="bi bi-trash" data-bs-toggle="tooltip" title="{$l10n->get('SYS_DELETE')}"></i></a>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}

{* The organization's requirements. A member sees only those their roles carry, and cannot change them. *}
{if $isAdmin or $types|@count gt 0}
<h3 class="admidio-content-subheader">{$l10n->get('PLG_REQUIREMENTS_ORGANIZATION_REQUIREMENTS')}</h3>
{if not $isAdmin}<p class="text-muted">{$l10n->get('PLG_REQUIREMENTS_ORGANIZATION_REQUIREMENTS_MEMBER_DESC')}</p>{/if}
{/if}
{if $isAdmin and $types|@count eq 0}
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
                {if $isAdmin}
                <span class="admidio-icon-link">
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
                <p class="text-muted mb-2">
                    {$l10n->get('PLG_REQUIREMENTS_PERIOD_UNIT')}: {$type.period}
                    {if $isAdmin}&nbsp;|&nbsp; {$l10n->get('PLG_REQUIREMENTS_MULTIPLE_ROLES_MODE')}: {$type.multipleRoles}{/if}
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
            </div>
        </div>
    {/foreach}
{/if}
