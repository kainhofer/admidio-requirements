<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}

    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_TITLE')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqa_title']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['rqa_provider_id']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqa_url']}
            {include 'sys-template-parts/form.multiline.tpl' data=$elements['rqa_location']}
            {include 'sys-template-parts/form.multiline.tpl' data=$elements['rqa_description']}
        </div>
    </div>

    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_PERIOD')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.checkbox.tpl' data=$elements['rqa_full_day']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqa_begin']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqa_end']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqa_attendance']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['rqa_certificate_date']}
        </div>
    </div>

    {if $creditFields|@count gt 0}
        <div class="card admidio-field-group">
            <div class="card-header">{$l10n->get('PLG_REQUIREMENTS_ACTIVITY_CREDITS')}</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                        <thead>
                            <tr>
                                <th style="min-width: 10rem;">{$l10n->get('PLG_REQUIREMENTS_REQUIREMENT_TYPE')}</th>
                                <th style="min-width: 9rem;">{$l10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD')}</th>
                                <th style="min-width: 7rem;">{$l10n->get('PLG_REQUIREMENTS_CREDITS_CLAIMED')}</th>
                                {if $showReview}<th style="min-width: 7rem;">{$l10n->get('PLG_REQUIREMENTS_CREDITS_ACCEPTED')}</th>{/if}
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $creditFields as $cf}
                                <tr id="adm_req_credit_{$cf.typeId}" {if not $cf.shown}hidden{/if}>
                                    <td class="fw-bold">{$cf.name nofilter}</td>
                                    <td>
                                        {if $cf.kind eq 'year'}
                                            <input type="number" step="1" min="1900" max="{$cf.maxYear}" name="{$cf.periodId}" id="{$cf.periodId}"
                                                   value="{$cf.periodValue}" data-kind="year"
                                                   class="form-control form-control-sm adm-req-period"
                                                   placeholder="{$l10n->get('PLG_REQUIREMENTS_PERIOD_YEAR')}">
                                        {elseif $cf.kind eq 'quarter'}
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="1" min="1900" max="{$cf.maxYear}" name="{$cf.periodId}" id="{$cf.periodId}"
                                                       value="{$cf.periodValue}" data-kind="quarter"
                                                       class="form-control adm-req-period"
                                                       placeholder="{$l10n->get('PLG_REQUIREMENTS_PERIOD_YEAR')}">
                                                <select name="{$cf.quarterId}" id="{$cf.quarterId}" class="form-select adm-req-period-quarter">
                                                    {foreach [1,2,3,4] as $q}
                                                        <option value="{$q}" {if $cf.quarterValue eq $q}selected{/if}>Q{$q}</option>
                                                    {/foreach}
                                                </select>
                                            </div>
                                        {elseif $cf.kind eq 'month'}
                                            <input type="month" name="{$cf.periodId}" id="{$cf.periodId}" value="{$cf.periodValue}"
                                                   data-kind="month" class="form-control form-control-sm adm-req-period">
                                        {elseif $cf.kind eq 'week'}
                                            <input type="week" name="{$cf.periodId}" id="{$cf.periodId}" value="{$cf.periodValue}"
                                                   data-kind="week" class="form-control form-control-sm adm-req-period">
                                        {else}
                                            <input type="date" name="{$cf.periodId}" id="{$cf.periodId}" value="{$cf.periodValue}"
                                                   data-kind="date" class="form-control form-control-sm adm-req-period">
                                        {/if}
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="{$cf.claimedId}" id="{$cf.claimedId}"
                                               value="{$cf.claimedValue}" class="form-control form-control-sm">
                                    </td>
                                    {if $showReview}
                                        <td>
                                            {if $cf.acceptedId}
                                                <input type="number" step="0.01" min="0" name="{$cf.acceptedId}" id="{$cf.acceptedId}"
                                                       value="{$cf.acceptedValue}" class="form-control form-control-sm">
                                            {/if}
                                        </td>
                                    {/if}
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>

                {if $optionalCreditTypes|@count gt 0}
                    <div class="row g-2 align-items-end" id="adm_req_add_credit_wrap">
                        <div class="col-sm-8">
                            <label for="adm_req_add_credit" class="form-label small mb-1">{$l10n->get('PLG_REQUIREMENTS_ADD_CREDIT_TYPE')}</label>
                            <select id="adm_req_add_credit" class="form-select form-select-sm">
                                <option value="">&ndash;</option>
                                {foreach $optionalCreditTypes as $optId => $optName}
                                    <option value="{$optId}">{$optName nofilter}</option>
                                {/foreach}
                            </select>
                        </div>
                    </div>
                {/if}

                <p class="text-muted small mb-0 mt-2">{$l10n->get('PLG_REQUIREMENTS_CREDITED_PERIOD_HELP')}</p>
            </div>
        </div>
    {/if}

    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_COMMENT')}{if $showReview} &amp; {$l10n->get('PLG_REQUIREMENTS_REVIEW_STATUS')}{/if}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.multiline.tpl' data=$elements['rqa_comments']}
            {if $showReview}
                {include 'sys-template-parts/form.select.tpl' data=$elements['rqa_status']}
                {include 'sys-template-parts/form.select.tpl' data=$elements['rqa_review_status']}
                {include 'sys-template-parts/form.multiline.tpl' data=$elements['rqa_review_comments']}
            {/if}
        </div>
    </div>

    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
    {include file="sys-template-parts/system.info-create-edit.tpl"}
</form>
