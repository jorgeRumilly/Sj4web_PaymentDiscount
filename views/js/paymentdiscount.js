// paymentdiscount.js - Version avec variables dynamiques
(function ($) {
    // Variables dynamiques passées depuis PHP
    const ALLOWED = window.sj4web_paymentdiscount_allowed_modules || ['payplug', 'ps_wirepayment'];
    const VOUCHER_CODE = window.sj4web_paymentdiscount_voucher_code || 'PAY5';
    const DEBUG = window.sj4web_paymentdiscount_debug || false;

    // Constantes statiques
    const SELECTOR = 'input[name="payment-option"]';
    const CONFIRM_BTN = '#payment-confirmation button[type="submit"]';

    function debugLog(message, data = null) {
        if (!DEBUG) return; // Sortir immédiatement si debug désactivé

        if (data) {
            console.log('PaymentDiscount DEBUG -', message, data);
        } else {
            console.log('PaymentDiscount DEBUG -', message);
        }
    }

    let lastKey = null, lastEnable = null, inflight = false;
    let preventSubmit = false;

    const getKey = (el) => {
        const key = $(el).data('moduleName') || el.value || el.id || '';
        debugLog('Key détecté:', {key, element: el});
        return key;
    };

    // Is BR allowed
    const isAllowed = (k) => ALLOWED.some(x => (k || '').toLowerCase().indexOf(x.toLowerCase()) !== -1);

    // Fonction pour forcer le rafraîchissement de l'affichage du panier
    function forceCartRefresh() {
        // Méthode 1 : Événement PrestaShop
        if (window.prestashop && typeof prestashop.emit === 'function') {
            prestashop.emit('updateCart', {
                reason: {linkAction: 'payment-discount-update'},
                resp: {}
            });
        }

        // Méthode 2 : Recharger la section checkout si méthode 1 échoue
        setTimeout(() => {
            const checkoutSection = document.querySelector('#checkout-payment-step');
            if (checkoutSection) {
                // Déclencher un événement change sur le formulaire pour forcer PrestaShop à recalculer
                const form = document.querySelector('#js-checkout-summary');
                if (form) {
                    const event = new Event('change', {bubbles: true});
                    form.dispatchEvent(event);
                }
            }
        }, 100);

        // Méthode 3 : Fallback - recharger complètement la page de checkout
        setTimeout(() => {
            if (window.location.href.includes('order')) {
                window.location.reload();
            }
        }, 5000); // Seulement si les autres méthodes échouent après 5 secondes
    }

    // Gestion de l'état de chargement
    function setLoading(on) {
        $('#checkout-payment-step').toggleClass('is-loading', on);
        $(SELECTOR).prop('disabled', on);

        if (on) {
            preventSubmit = true;
            // Bloquer IMMÉDIATEMENT tous les boutons de paiement
            $(CONFIRM_BTN + ', .payment-form button, input[type="submit"]')
                .prop('disabled', true)
                .addClass('payment-discount-blocked');
        } else {
            // Réactiver seulement quand on est sûr
            preventSubmit = false;
            $(CONFIRM_BTN + ', .payment-form button, input[type="submit"]')
                .not('.disabled') // Respecter les autres désactivations
                .prop('disabled', false)
                .removeClass('payment-discount-blocked');
        }
    }

    function sync(key, force = false) {
        // On laisse le serveur décider
        const enable = isAllowed(key); // Juste pour les logs

        debugLog('Sync appelé:', {
            key: key,
            isAllowed: isAllowed(key),
            enable: enable,
            lastKey: lastKey,
            lastEnable: lastEnable,
            force: force,
            configuredModules: ALLOWED,
            configuredVoucherCode: VOUCHER_CODE
        });

        // Éviter les appels redondants
        if (!force && key === lastKey && enable === lastEnable) {
            debugLog('Sync ignoré (redondant)');
            return;
        }
        if (inflight) {
            debugLog('Sync ignoré (en cours)');
            return;
        }

        inflight = true;
        lastKey = key;
        lastEnable = enable;
        setLoading(true);

        $.ajax({
            url: window.sj4web_paymentdiscount_toggle,
            method: 'POST',
            data: JSON.stringify({
                code: VOUCHER_CODE,
                enable,
                payment_module: key
            }),
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            timeout: 10000
        }).done(function (data) {
            debugLog('Réponse AJAX:', data);
            if (data && data.changed) {
                debugLog('Changement appliqué:', data);
                // FORCER LE RAFRAICHISSEMENT DE L'AFFICHAGE
                forceCartRefresh();
            }
        }).fail(function (xhr, status, error) {
            debugLog('Erreur AJAX:', {xhr, status, error});
        }).always(function () {
            inflight = false;
            setLoading(false);
        });
    }

    // Améliorer l'interception
    function interceptSubmit(e) {
        if (preventSubmit || inflight) {
            e.preventDefault();
            e.stopImmediatePropagation();
            e.stopPropagation();
            return false;
        }
        return true;
    }

    // Gestionnaire de changement de paiement
    $(document).on('change', SELECTOR, function () {
        if (this.checked) {
            $('.payment-discount-wait').remove();
            debugLog('Changement de paiement détecté');
            sync(getKey(this));
        }
    });

    // Intercepter les clics sur le bouton de confirmation
    $(document).on('click', CONFIRM_BTN, interceptSubmit);

    // Bloquer AUSSI les soumissions de formulaires
    $(document).on('submit', 'form', function (e) {
        if (preventSubmit || inflight) {
            if ($(this).find(CONFIRM_BTN + ', input[type="submit"]').length) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }
    });

    // Initialisation
    $(function () {
        debugLog('Initialisation du module avec configuration:', {
            allowedModules: ALLOWED,
            voucherCode: VOUCHER_CODE,
            debugMode: DEBUG
        });

        // Synchronisation initiale
        const checkedEl = document.querySelector(`${SELECTOR}:checked`);
        if (checkedEl) {
            debugLog('Paiement déjà sélectionné:', getKey(checkedEl));
            sync(getKey(checkedEl));
        } else {
            debugLog('Aucun paiement sélectionné');
        }

        // Réactiver les contrôles au chargement
        setTimeout(() => {
            preventSubmit = false;
            inflight = false;
            $(SELECTOR).prop('disabled', false);
            if (!$(CONFIRM_BTN).hasClass('disabled')) {
                $(CONFIRM_BTN).prop('disabled', false).removeClass('payment-discount-blocked');
            }
        }, 500);
    });

})(jQuery);