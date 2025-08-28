// views/js/fallback.js - Mode dégradé
(function($) {
    'use strict';

    if (!window.sj4web_paymentdiscount_fallback_mode) return;

    const ALLOWED = ['payplug', 'ps_wirepayment'];
    const VOUCHER_VALUE = parseFloat(window.sj4web_paymentdiscount_voucher_value || 0);
    const SELECTOR = 'input[name="payment-option"]';

    let originalTotals = new Map();

    function isPaymentAllowed(paymentKey) {
        return ALLOWED.some(allowed =>
            paymentKey.toLowerCase().indexOf(allowed.toLowerCase()) !== -1
        );
    }

    function correctDisplayedTotals() {
        const selectedPayment = document.querySelector(`${SELECTOR}:checked`);
        if (!selectedPayment) return;

        const paymentKey = selectedPayment.dataset.moduleName ||
            selectedPayment.value ||
            selectedPayment.id || '';
        const allowed = isPaymentAllowed(paymentKey);

        // Sélecteurs pour les totaux à corriger
        const totalSelectors = [
            '.cart-total .value',
            '.total-value',
            '[data-total]',
            '.js-cart-line-product-total',
            '#cart-subtotal-products .value',
            '.cart-summary-line .value'
        ];

        totalSelectors.forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach(correctElement);
        });

        function correctElement(el) {
            // Sauvegarder le total original la première fois
            if (!originalTotals.has(el)) {
                const text = el.textContent || el.innerText || '';
                const value = parseFloat(text.replace(/[^\d.,]/g, '').replace(',', '.'));
                if (!isNaN(value)) {
                    originalTotals.set(el, value);
                }
            }

            const originalValue = originalTotals.get(el);
            if (originalValue === undefined) return;

            let correctedValue = originalValue;

            // Si paiement non autorisé et voucher actif, annuler l'effet du voucher
            if (!allowed && VOUCHER_VALUE > 0) {
                correctedValue = originalValue + VOUCHER_VALUE;
            }

            // Mettre à jour l'affichage si la valeur a changé
            if (Math.abs(correctedValue - originalValue) > 0.01) {
                const formatted = new Intl.NumberFormat('fr-FR', {
                    style: 'currency',
                    currency: 'EUR'
                }).format(correctedValue);

                el.textContent = formatted;
                el.style.color = '#e74c3c'; // Rouge pour indiquer la correction
                el.title = 'Total corrigé (mode dégradé)';
            } else {
                el.style.color = '';
                el.title = '';
            }
        }
    }

    // Écouter les changements de paiement
    $(document).on('change', SELECTOR, function() {
        // Petit délai pour laisser PrestaShop mettre à jour
        setTimeout(correctDisplayedTotals, 100);
    });

    // Correction initiale
    $(function() {
        setTimeout(correctDisplayedTotals, 500);
    });

    // Re-corriger après les mises à jour AJAX de PrestaShop
    if (window.prestashop?.on) {
        prestashop.on('updateCart', function() {
            setTimeout(correctDisplayedTotals, 200);
        });
    }

})(jQuery);