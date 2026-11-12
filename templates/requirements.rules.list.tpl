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
                            {if $own.parentName}<div class="text-muted small"><i class="bi bi-arrow-return-right"></i> {$l10n->get('PLG_REQUIREMENTS_PARENT_REQUIREMENT')}: {$own.parentName nofilter}</div>{/if}
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
<style>
    .adm-req-inline { cursor: text; border-bottom: 1px dotted transparent; }
    .adm-req-inline:hover { border-bottom-color: var(--bs-secondary-color, #6c757d); background: var(--bs-secondary-bg, #e9ecef); }
    .adm-req-inline .form-control { display: inline-block; }
</style>
<script>
    (function () {
        var csrf = "{$csrfToken}";

        function messageText(html) {
            {* The message is HTML from the server's purifier; show it as text. *}
            var tmp = document.createElement("div");
            tmp.innerHTML = html;
            return tmp.textContent;
        }

        function edit(span) {
            if (span.querySelector("input")) { return; }
            var shown = span.textContent;
            var input = document.createElement("input");
            input.type = span.dataset.type;
            input.className = "form-control form-control-sm";
            input.style.width = span.dataset.type === "date" ? "9.5rem" : "6rem";
            if (span.dataset.type === "number") { input.min = "0"; input.step = "0.5"; }
            input.value = span.dataset.value;
            span.textContent = "";
            span.appendChild(input);
            input.focus();

            var finished = false;
            function finish(save) {
                if (finished) { return; }
                finished = true;
                var value = input.value;
                span.textContent = shown;
                if (!save || value === span.dataset.value) { return; }

                span.classList.add("text-muted");
                fetch(span.dataset.url, {
                    method: "POST",
                    body: new URLSearchParams({ adm_csrf_token: csrf, field: span.dataset.field, value: value })
                })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        span.classList.remove("text-muted");
                        if (result.status === "success") {
                            span.dataset.value = value;
                            span.textContent = result.display;
                        } else {
                            alert(messageText(result.message));
                        }
                    })
                    .catch(function () { span.classList.remove("text-muted"); });
            }
            input.addEventListener("keydown", function (e) {
                if (e.key === "Enter") { e.preventDefault(); finish(true); }
                else if (e.key === "Escape") { e.preventDefault(); finish(false); }
            });
            input.addEventListener("blur", function () { finish(true); });
        }

        document.addEventListener("dblclick", function (e) {
            var span = e.target.closest(".adm-req-inline");
            if (span) { edit(span); }
        });
    })();
</script>
{/if}
