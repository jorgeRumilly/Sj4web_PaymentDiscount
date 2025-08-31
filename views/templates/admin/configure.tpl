{* views/templates/admin/configure.tpl - Version avec i18n *}

{* Statut du module *}
<div class="panel">
    <h3><i class="icon icon-cogs"></i> {l s='Module Status' d='Modules.Sj4webPaymentdiscount.Admin'}</h3>

    {if $override_mode}
        <div class="alert alert-success">
            <strong>{l s='Optimal mode' d='Modules.Sj4webPaymentdiscount.Admin'}</strong> - {l s='Cart.php override installed and working' d='Modules.Sj4webPaymentdiscount.Admin'}
        </div>
    {elseif $degraded_mode}
        <div class="alert alert-warning">
            <strong>{l s='Degraded mode' d='Modules.Sj4webPaymentdiscount.Admin'}</strong> - {l s='Cart.php override not installed' d='Modules.Sj4webPaymentdiscount.Admin'}
        </div>
        <p class="help-block">
            {l s='The module works but with reduced precision on total calculations.' d='Modules.Sj4webPaymentdiscount.Admin'}
        </p>
    {/if}
</div>

{* Formulaire de configuration *}
<form id="configuration_form" class="defaultForm form-horizontal" method="post">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon icon-cog"></i> {l s='Payment Discount Configuration' d='Modules.Sj4webPaymentdiscount.Admin'}
        </div>

        <div class="form-wrapper">

            {* Code du bon de réduction *}
            <div class="form-group">
                <label class="control-label col-lg-3 required">
                    {l s='Discount voucher code' d='Modules.Sj4webPaymentdiscount.Admin'}
                </label>
                <div class="col-lg-4">
                    <input type="text"
                           name="VOUCHER_CODE"
                           value="{$voucher_code|escape:'html':'UTF-8'}"
                           class="form-control"
                           required="required">
                    <p class="help-block">
                        {l s='Code of the existing discount voucher in PrestaShop' d='Modules.Sj4webPaymentdiscount.Admin'}
                    </p>
                </div>
            </div>

            {* Seuil minimum *}
            <div class="form-group">
                <label class="control-label col-lg-3 required">
                    {l s='Minimum threshold' d='Modules.Sj4webPaymentdiscount.Admin'}
                </label>
                <div class="col-lg-4">
                    <div class="input-group">
                        <input type="number"
                               step="0.01"
                               name="THRESHOLD"
                               value="{$threshold|escape:'html':'UTF-8'}"
                               class="form-control"
                               required="required">
                        <span class="input-group-addon">€</span>
                    </div>
                    <p class="help-block">
                        {l s='Minimum cart amount to apply the discount' d='Modules.Sj4webPaymentdiscount.Admin'}
                    </p>
                </div>
            </div>

            {* Modules de paiement autorisés *}
            <div class="form-group">
                <label class="control-label col-lg-3 required">
                    {l s='Allowed payment modules' d='Modules.Sj4webPaymentdiscount.Admin'}
                </label>
                <div class="col-lg-8">
                    <textarea name="ALLOWED_MODULES"
                              rows="8"
                              class="form-control"
                              placeholder="payplug:standard&#10;ps_wirepayment&#10;stripe_official&#10;paypal">{$allowed_modules|escape:'html':'UTF-8'}</textarea>
                    <p class="help-block">
                        <strong>{l s='One module per line. Use module:subtype for detailed control.' d='Modules.Sj4webPaymentdiscount.Admin'}</strong><br>
                        <strong>{l s='PayPlug examples:' d='Modules.Sj4webPaymentdiscount.Admin'}</strong><br>
                        • <code>payplug:standard</code> - {l s='Credit card only' d='Modules.Sj4webPaymentdiscount.Admin'}<br>
                        • <code>payplug:applepay</code> - {l s='Apple Pay only' d='Modules.Sj4webPaymentdiscount.Admin'}<br>
                        • <code>payplug:oney_x3_without_fees</code> - {l s='Oney 3x payment' d='Modules.Sj4webPaymentdiscount.Admin'}<br>
                        • <code>payplug:oney_x4_without_fees</code> - {l s='Oney 4x payment' d='Modules.Sj4webPaymentdiscount.Admin'}<br>
                        <br>
                        <strong>{l s='Other modules:' d='Modules.Sj4webPaymentdiscount.Admin'}</strong><br>
                        • <code>ps_wirepayment</code>, <code>paypal</code>, <code>stripe_official</code>
                    </p>
                </div>
            </div>

            {* Mode debug *}
            <div class="form-group">
                <label class="control-label col-lg-3">
                    {l s='Debug mode' d='Modules.Sj4webPaymentdiscount.Admin'}
                </label>
                <div class="col-lg-4">
                    <select name="DEBUG_MODE" class="form-control">
                        <option value="0" {if !$debug_mode}selected="selected"{/if}>
                            {l s='Disabled' d='Modules.Sj4webPaymentdiscount.Admin'}
                        </option>
                        <option value="1" {if $debug_mode}selected="selected"{/if}>
                            {l s='Enabled' d='Modules.Sj4webPaymentdiscount.Admin'}
                        </option>
                    </select>
                    <p class="help-block">
                        {l s='Enable detailed logs for debugging' d='Modules.Sj4webPaymentdiscount.Admin'}
                    </p>
                </div>
            </div>

            {* Boutons de validation *}
            <div class="panel-footer">
                <button type="submit" name="submit{$module_name|escape:'html':'UTF-8'}" class="btn btn-primary">
                    <i class="process-icon-save"></i> {l s='Save' d='Modules.Sj4webPaymentdiscount.Admin'}
                </button>
                <button type="button" class="btn btn-default" onclick="window.location.reload();">
                    <i class="process-icon-cancel"></i> {l s='Cancel' d='Modules.Sj4webPaymentdiscount.Admin'}
                </button>
            </div>
        </div>
    </div>
</form>

{* Section d'aide *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon icon-question"></i> {l s='Help & Documentation' d='Modules.Sj4webPaymentdiscount.Admin'}
    </div>
    <div class="panel-body">
        <div class="row">
            <div class="col-md-6">
                <h4>{l s='Discount voucher configuration' d='Modules.Sj4webPaymentdiscount.Admin'}</h4>
                <p>{l s='The discount voucher must be created in:' d='Modules.Sj4webPaymentdiscount.Admin'}</p>
                <p><strong>{l s='Promotion > Cart Rules' d='Modules.Sj4webPaymentdiscount.Admin'}</strong></p>
                <ul>
                    <li>{l s='Code' d='Modules.Sj4webPaymentdiscount.Admin'}: {l s='Same as configured above' d='Modules.Sj4webPaymentdiscount.Admin'}</li>
                    <li>{l s='Type' d='Modules.Sj4webPaymentdiscount.Admin'}: {l s='Amount or percentage according to your choice' d='Modules.Sj4webPaymentdiscount.Admin'}</li>
                    <li>{l s='Status' d='Modules.Sj4webPaymentdiscount.Admin'}: {l s='Active' d='Modules.Sj4webPaymentdiscount.Admin'}</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h4>{l s='Payment modules' d='Modules.Sj4webPaymentdiscount.Admin'}</h4>
                <p>{l s='Technical names of installed modules:' d='Modules.Sj4webPaymentdiscount.Admin'}</p>
                <ul>
                    {foreach from=$payment_modules item=module}
                        <li><code>{$module.name|escape:'html':'UTF-8'}</code> - {$module.display_name|escape:'html':'UTF-8'}</li>
                    {/foreach}
                </ul>
            </div>
        </div>
        
        <hr>
        
        <div class="row">
            <div class="col-md-6">
                <h4>{l s='Configuration granulaire PayPlug' d='Modules.Sj4webPaymentdiscount.Admin'}</h4>
                <p>{l s='PayPlug supports multiple payment methods. You can configure each one specifically:' d='Modules.Sj4webPaymentdiscount.Admin'}</p>
                <ul>
                    <li><strong>{l s='Standard credit card' d='Modules.Sj4webPaymentdiscount.Admin'}</strong></li>
                    <li><strong>{l s='Mobile payments (Apple Pay, Google Pay)' d='Modules.Sj4webPaymentdiscount.Admin'}</strong></li>
                    <li><strong>{l s='Oney installment payments (3x, 4x)' d='Modules.Sj4webPaymentdiscount.Admin'}</strong></li>
                </ul>
                
                <h5>{l s='Detection examples' d='Modules.Sj4webPaymentdiscount.Admin'}</h5>
                <p>{l s='The module automatically detects PayPlug variants:' d='Modules.Sj4webPaymentdiscount.Admin'}</p>
                <ul>
                    <li><code>{l s='Forms with method=standard → payplug:standard' d='Modules.Sj4webPaymentdiscount.Admin'}</code></li>
                    <li><code>{l s='Labels with "Apple Pay" → payplug:applepay' d='Modules.Sj4webPaymentdiscount.Admin'}</code></li>
                    <li><code>{l s='Labels with "3x sans frais" → payplug:oney_x3_without_fees' d='Modules.Sj4webPaymentdiscount.Admin'}</code></li>
                </ul>
            </div>
            <div class="col-md-6">
                <h4>{l s='Configuration examples' d='Modules.Sj4webPaymentdiscount.Admin'}</h4>
                
                <h5>{l s='Allow only credit card and wire transfer:' d='Modules.Sj4webPaymentdiscount.Admin'}</h5>
                <pre><code>payplug:standard
ps_wirepayment</code></pre>
                
                <h5>{l s='Allow all PayPlug methods:' d='Modules.Sj4webPaymentdiscount.Admin'}</h5>
                <pre><code>payplug:standard
payplug:applepay
payplug:oney_x3_without_fees
payplug:oney_x4_without_fees
ps_wirepayment</code></pre>
                
                <h5>{l s='Exclude Oney payments only:' d='Modules.Sj4webPaymentdiscount.Admin'}</h5>
                <pre><code>payplug:standard
payplug:applepay
ps_wirepayment
paypal</code></pre>
            </div>
        </div>
        
        <hr>
        
        <div class="row">
            <div class="col-md-12">
                <h4>{l s='Troubleshooting' d='Modules.Sj4webPaymentdiscount.Admin'}</h4>
                <div class="col-md-4">
                    <p><strong>{l s='If automatic detection doesn\'t work:' d='Modules.Sj4webPaymentdiscount.Admin'}</strong></p>
                    <ul>
                        <li>{l s='Enable debug mode to see detection logs' d='Modules.Sj4webPaymentdiscount.Admin'}</li>
                        <li>{l s='Check browser console for JavaScript errors' d='Modules.Sj4webPaymentdiscount.Admin'}</li>
                        <li>{l s='Check logs in PrestaShop > Advanced Parameters > Logs' d='Modules.Sj4webPaymentdiscount.Admin'}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

{* JavaScript pour améliorer l'UX *}
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Validation côté client
        const form = document.getElementById('configuration_form');
        const voucherCode = document.querySelector('input[name="VOUCHER_CODE"]');
        const threshold = document.querySelector('input[name="THRESHOLD"]');

        form.addEventListener('submit', function(e) {
            if (!voucherCode.value.trim()) {
                alert('{l s='The discount voucher code is required' d='Modules.Sj4webPaymentdiscount.Admin' js=1}');
                e.preventDefault();
                voucherCode.focus();
                return false;
            }

            if (parseFloat(threshold.value) <= 0) {
                alert('{l s='The minimum threshold must be greater than 0' d='Modules.Sj4webPaymentdiscount.Admin' js=1}');
                e.preventDefault();
                threshold.focus();
                return false;
            }
        });

        // Prévisualisation temps réel
        voucherCode.addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    });
</script>

{* CSS personnalisé *}
<style>
    .form-group .help-block {
        margin-top: 5px;
        font-size: 12px;
    }

    .panel-body ul {
        margin-bottom: 0;
    }

    .alert {
        margin-bottom: 15px;
    }

    #configuration_form .required:after {
        content: " *";
        color: #E74C3C;
    }
</style>