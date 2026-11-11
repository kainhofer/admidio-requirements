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
        {include 'requirements.rules.type.tpl' type=$type nested=false}
    {/foreach}
{/if}

{if $isAdmin}
<script>
    (function () {
        var csrf = "{$csrfToken}";
        document.querySelectorAll(".adm-req-inline").forEach(function (input) {
            input.dataset.saved = input.value;
            input.addEventListener("change", function () {
                var body = new URLSearchParams({ adm_csrf_token: csrf, field: input.dataset.field, value: input.value });
                input.classList.remove("is-invalid", "is-valid");
                fetch(input.dataset.url, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (result.status === "success") {
                            input.dataset.saved = input.value;
                            input.classList.add("is-valid");
                            setTimeout(function () { input.classList.remove("is-valid"); }, 1500);
                        } else {
                            input.classList.add("is-invalid");
                            input.title = result.message;
                            input.value = input.dataset.saved;
                            {* The message is HTML from the server's purifier; show it as text. *}
                            var tmp = document.createElement("div"); tmp.innerHTML = result.message;
                            alert(tmp.textContent);
                        }
                    })
                    .catch(function () { input.classList.add("is-invalid"); input.value = input.dataset.saved; });
            });
        });
    })();
</script>
{/if}
