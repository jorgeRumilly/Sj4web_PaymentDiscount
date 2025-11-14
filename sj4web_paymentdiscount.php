<?php

class Sj4web_PaymentDiscount extends Module
{
    private const CUSTOM_HOOK = 'actionSjPayDisCartGetOrderTotal';
    private const CUSTOM_HOOK_CARTRULES = 'actionSjPayDisCartGetCartRules';
    private $logFile;

    // ... constructeur et autres méthodes ...
    public function __construct()
    {
        $this->name = 'sj4web_paymentdiscount';
        $this->author = 'SJ4WEB.FR';
        $this->version = '1.2.0';
        $this->tab = 'pricing_promotion';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('SJ4WEB - Payment Discount Sync', [], 'Modules.Sj4webPaymentdiscount.Admin');
        $this->description = $this->trans('Automatically applies/removes discount based on cart total and payment method.', [], 'Modules.Sj4webPaymentdiscount.Admin');

        // Charger la classe PaymentDiscountRule
        require_once dirname(__FILE__) . '/classes/PaymentDiscountRule.php';
        if ($this->isRegisteredInHook('actionValidateOrder')) {
            $this->unregisterHook('actionValidateOrder');
        }
        if (!$this->isRegisteredInHook('actionSjPayDisCartGetCartRules')) {
            $this->registerHook('actionSjPayDisCartGetCartRules');
        }
        $this->logFile = _PS_ROOT_DIR_ . '/var/logs/payment_discount_module.log';
    }

    // Remplacer la constante par une méthode :
    public function isDebugMode(): bool
    {
        return (bool)Configuration::get($this->name . '_DEBUG_MODE', _PS_MODE_DEV_);
    }

    /**
     * Méthode centralisée pour les logs de debug
     */
    public function debugLog(string $message, int $level = 1, $object = null, int $objectId = null): void
    {
        if (!$this->isDebugMode()) return;

        PrestaShopLogger::addLog(
            "PaymentDiscount DEBUG: " . $message,
            $level,
            null,
            $object ?: 'Module',
            $objectId
        );
    }


    public function install()
    {
        $success = parent::install()
            && $this->registerHook('actionCartSave')
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionCarrierProcess')
            && $this->registerHook(self::CUSTOM_HOOK)
            && $this->registerHook(self::CUSTOM_HOOK_CARTRULES);

        if ($success) {
            // ✅ Installation de la table des règles de paliers
            $success = $success && $this->installDatabase();

            if (!$success) {
                $this->_errors[] = $this->trans('Failed to create database table', [], 'Modules.Sj4webPaymentdiscount.Admin');
                return false;
            }

            // Valeurs de configuration par défaut (pour compatibilité)
            Configuration::updateValue($this->name . '_VOUCHER_CODE', 'PAY5');
            Configuration::updateValue($this->name . '_THRESHOLD', 500.00);
            Configuration::updateValue($this->name . '_ALLOWED_MODULES', "payplug:standard\nps_wirepayment");
            Configuration::updateValue($this->name . '_DEBUG_MODE', false);

            // Installation de l'override
            $overrideResult = $this->installOverrideIntelligent();
            Configuration::updateValue($this->name . '_OVERRIDE_MODE', $overrideResult ? 1 : 0);

            if (!$overrideResult) {
                Configuration::updateValue($this->name . '_DEGRADED_MODE', 1);
                $this->_warnings[] = $this->trans('Cart.php override - Degraded mode enabled', [], 'Modules.Sj4webPaymentdiscount.Admin');
            }

            // ✅ Créer une règle par défaut lors de la première installation
            $this->createDefaultRule();
        }

        return $success;
    }

    /**
     * Installation de la base de données
     *
     * @return bool
     */
    protected function installDatabase()
    {
        $sqlFile = dirname(__FILE__) . '/sql/install.php';

        if (!file_exists($sqlFile)) {
            return false;
        }

        include_once $sqlFile;

        return true;
    }

    /**
     * Créer une règle par défaut lors de la première installation
     *
     * @return bool
     */
    protected function createDefaultRule()
    {
        // Vérifier si des règles existent déjà
        $existingRules = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'sj4web_payment_discount_rule`'
        );

        if ($existingRules > 0) {
            return true; // Des règles existent déjà
        }

        // Créer une règle par défaut
        $idShop = Shop::isFeatureActive() ? (int) Context::getContext()->shop->id : null;
        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->insert('sj4web_payment_discount_rule', [
            'id_shop' => $idShop,
            'name' => 'Réduction 5% à partir de 500€',
            'voucher_code' => 'PAY5',
            'threshold' => 500.00,
            'allowed_modules' => "payplug:standard\nps_wirepayment",
            'position' => 1,
            'active' => 1,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    /**
     * Installation intelligente selon les 3 cas
     */
    protected function installOverrideIntelligent(): bool
    {
        $overridePath = _PS_OVERRIDE_DIR_ . 'classes/Cart.php';

        try {
            // CAS 1 : Pas d'override existant
            if (!file_exists($overridePath)) {
                return $this->createCompleteOverride($overridePath);
            }

            $content = file_get_contents($overridePath);

            // Vérifier si notre hook est déjà là
            if (strpos($content, self::CUSTOM_HOOK) !== false) {
                return true; // Déjà installé
            }

            // Analyser la structure
            if (!preg_match('/class\s+Cart\s+extends\s+CartCore/i', $content)) {
                $this->_warnings[] = $this->trans('Existing Cart.php override is non-standard', [], 'Modules.Sj4webPaymentdiscount.Admin');
                return false;
            }

            // CAS 2 : Override existe mais pas la méthode getOrderTotal
            if (!$this->hasGetOrderTotalMethod($content)) {
                return $this->addCompleteMethod($overridePath, $content);
            }

            // CAS 3 : Override existe ET méthode getOrderTotal présente
            return $this->addHookToExistingMethod($overridePath, $content);

        } catch (Exception $e) {
            $this->debugLog(
                "PaymentDiscount Override Error: " . $e->getMessage(),
                3, 'Module'
            );
            return false;
        }
    }

    /**
     * CAS 1 : Créer un override complet
     */
    protected function createCompleteOverride(string $overridePath): bool
    {
        $overrideContent = '<?php
/**
 * Override Cart - SJ4WEB PaymentDiscount
 */
class Cart extends CartCore
{
    public function getOrderTotal($with_taxes = true, $type = Cart::BOTH, $products = null, $id_carrier = null, $use_cache = true)
    {
        $total = parent::getOrderTotal($with_taxes, $type, $products, $id_carrier, $use_cache);

        // Hook SJ4WEB PaymentDiscount
        Hook::exec(\'' . self::CUSTOM_HOOK . '\', [
            \'cart\' => $this,
            \'total\' => &$total,
            \'with_taxes\' => $with_taxes,
            \'type\' => $type,
            \'products\' => $products,
            \'id_carrier\' => $id_carrier
        ]);

        return $total;
    }

    public function getCartRules($filter = CartRule::FILTER_ACTION_ALL, $refresh = false)
    {
        // Hook SJ4WEB PaymentDiscount AVANT le parent
        Hook::exec(\'' . self::CUSTOM_HOOK_CARTRULES . '\', [
            \'cart\' => $this
        ]);

        return parent::getCartRules($filter, $refresh);
    }
}';

        if (!is_dir(_PS_OVERRIDE_DIR_ . 'classes/')) {
            mkdir(_PS_OVERRIDE_DIR_ . 'classes/', 0755, true);
        }

        return file_put_contents($overridePath, $overrideContent) !== false;
    }

    /**
     * CAS 2 : Ajouter la méthode complète à un override existant
     */
    protected function addCompleteMethod(string $overridePath, string $content): bool
    {
        $methodCode = '
    public function getOrderTotal($with_taxes = true, $type = Cart::BOTH, $products = null, $id_carrier = null, $use_cache = true)
    {
        $total = parent::getOrderTotal($with_taxes, $type, $products, $id_carrier, $use_cache);
        
        // Hook SJ4WEB PaymentDiscount
        Hook::exec(\'' . self::CUSTOM_HOOK . '\', [
            \'cart\' => $this,
            \'total\' => &$total,
            \'with_taxes\' => $withTaxes,
            \'type\' => $type,
            \'products\' => $products,
            \'id_carrier\' => $id_carrier
        ]);
        
        return $total;
    }';

        // Chercher la fin de la classe (avant le dernier '}')
        $pattern = '/^(.+)(\s*\}\s*)$/s';
        if (preg_match($pattern, $content, $matches)) {
            $newContent = $matches[1] . $methodCode . PHP_EOL . $matches[2];
            return file_put_contents($overridePath, $newContent) !== false;
        }

        return false;
    }

    /**
     * CAS 3 : Ajouter le hook à une méthode getOrderTotal existante
     */
    protected function addHookToExistingMethod(string $overridePath, string $content): bool
    {
        // Chercher le dernier "return $total;" avant une accolade fermante
        $pattern = '/^(.*getOrderTotal.*?\{.*?)(return\s+\$total\s*;\s*)(\s*\}\s*)(.*)$/s';

        if (preg_match($pattern, $content, $matches)) {
            $beforeReturn = $matches[1];
            $returnStatement = $matches[2];
            $afterReturn = $matches[3];
            $rest = $matches[4];

            $hookCode = '
        // Hook SJ4WEB PaymentDiscount
        Hook::exec(\'' . self::CUSTOM_HOOK . '\', [
            \'cart\' => $this,
            \'total\' => &$total,
            \'with_taxes\' => $withTaxes,
            \'type\' => $type,
            \'products\' => $products ?? null,
            \'id_carrier\' => $id_carrier ?? null
        ]);
        
        ';

            $newContent = $beforeReturn . $hookCode . $returnStatement . $afterReturn . $rest;
            return file_put_contents($overridePath, $newContent) !== false;
        }

        // Méthode alternative : chercher plus largement
        return $this->addHookToMethodAlternative($overridePath, $content);
    }

    /**
     * Méthode alternative pour CAS 3 (recherche plus large)
     */
    protected function addHookToMethodAlternative(string $overridePath, string $content): bool
    {
        // Rechercher toutes les occurrences de "return $total"
        $lines = explode(PHP_EOL, $content);
        $modifiedLines = [];
        $inGetOrderTotal = false;
        $braceCount = 0;
        $hookAdded = false;

        foreach ($lines as $line) {
            // Détecter le début de la méthode getOrderTotal
            if (preg_match('/function\s+getOrderTotal\s*\(/', $line)) {
                $inGetOrderTotal = true;
                $braceCount = 0;
            }

            if ($inGetOrderTotal) {
                // Compter les accolades pour savoir où on est
                $braceCount += substr_count($line, '{') - substr_count($line, '}');

                // Si on trouve "return $total" et qu'on est dans la méthode
                if (preg_match('/^\s*return\s+\$total\s*;\s*$/', $line) && $braceCount === 0) {
                    // Ajouter notre hook avant le return
                    $modifiedLines[] = '        // Hook SJ4WEB PaymentDiscount';
                    $modifiedLines[] = '        Hook::exec(\'' . self::CUSTOM_HOOK . '\', [';
                    $modifiedLines[] = '            \'cart\' => $this,';
                    $modifiedLines[] = '            \'total\' => &$total,';
                    $modifiedLines[] = '            \'with_taxes\' => $withTaxes,';
                    $modifiedLines[] = '            \'type\' => $type,';
                    $modifiedLines[] = '            \'products\' => $products ?? null,';
                    $modifiedLines[] = '            \'id_carrier\' => $id_carrier ?? null';
                    $modifiedLines[] = '        ]);';
                    $modifiedLines[] = '';
                    $hookAdded = true;
                }

                // Fin de la méthode
                if ($braceCount < 0) {
                    $inGetOrderTotal = false;
                }
            }

            $modifiedLines[] = $line;
        }

        if ($hookAdded) {
            return file_put_contents($overridePath, implode(PHP_EOL, $modifiedLines)) !== false;
        }

        // Dernier recours : instructions manuelles
        $this->_warnings[] = $this->trans('Unable to automatically add the hook. Manual instructions required.', [], 'Modules.Sj4webPaymentdiscount.Admin');
        return false;
    }

    /**
     * Vérifier si la méthode getOrderTotal existe dans l'override
     */
    protected function hasGetOrderTotalMethod(string $content): bool
    {
        return preg_match('/function\s+getOrderTotal\s*\(/', $content) === 1;
    }

    /**
     * Nettoyer notre modification (pour uninstall)
     */
    protected function cleanOverride(): bool
    {
        $overridePath = _PS_OVERRIDE_DIR_ . 'classes/Cart.php';

        if (!file_exists($overridePath)) {
            return true;
        }

        $content = file_get_contents($overridePath);

        // Supprimer notre hook avec différents patterns possibles
        $patterns = [
            // Pattern complet avec commentaires
            '/\s*\/\/ Hook SJ4WEB PaymentDiscount.*?Hook::exec\([^}]*\}[^;]*;\s*/s',
            // Pattern simple
            '/\s*Hook::exec\(\s*\'' . preg_quote(self::CUSTOM_HOOK) . '\'[^}]*\}[^;]*;\s*/s',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        // Si l'override ne contient plus que notre méthode, le supprimer complètement
        $isOnlyOurMethod = preg_match(
            '/^\s*<\?php[^{]*class Cart extends CartCore[^{]*\{\s*\/\*\*[^}]*\*\/\s*public function getOrderTotal[^{]*\{\s*\$total = parent::getOrderTotal[^;]*;\s*return \$total;\s*\}\s*\}\s*$/s',
            $content
        );

        if ($isOnlyOurMethod) {
            unlink($overridePath);
        } else {
            file_put_contents($overridePath, $content);
        }

        return true;
    }

    public function uninstall()
    {
        if (Configuration::get($this->name . '_OVERRIDE_MODE')) {
            $this->cleanOverride();
        }

        return Configuration::deleteByName($this->name . '_OVERRIDE_MODE')
            && Configuration::deleteByName($this->name . '_DEGRADED_MODE')
            && Configuration::deleteByName($this->name . '_VOUCHER_CODE')
            && Configuration::deleteByName($this->name . '_THRESHOLD')
            && Configuration::deleteByName($this->name . '_ALLOWED_MODULES')
            && Configuration::deleteByName($this->name . '_DEBUG_MODE')
            && parent::uninstall();
    }

    // ===== HOOKS =====

    public function hookActionCartSave($params)
    {
        $this->syncVoucher();
    }

    public function hookDisplayHeader()
    {
        $ctrl = $this->context->controller;

        if ($ctrl instanceof OrderController) {
            // JavaScript existant
            Media::addJsDef([
                'sj4web_paymentdiscount_toggle' => $this->context->link->getModuleLink($this->name, 'toggle', [], true),
                'sj4web_paymentdiscount_debug' => $this->isDebugMode(), // Utiliser la méthode
                'sj4web_paymentdiscount_allowed_modules' => $this->getAllowedModules(),
                'sj4web_paymentdiscount_voucher_code' => $this->getVoucherCode(),
            ]);
            
            $this->context->controller->registerJavascript(
                'sj4web-paymentdiscount',
                'modules/' . $this->name . '/views/js/paymentdiscount.js',
                ['position' => 'bottom', 'priority' => 50]
            );

            // Mode dégradé : ajouter JS de correction d'affichage
            if (Configuration::get($this->name . '_DEGRADED_MODE')) {
                $this->context->controller->registerJavascript(
                    'sj4web-paymentdiscount-fallback',
                    'modules/' . $this->name . '/views/js/fallback.js',
                    ['position' => 'bottom', 'priority' => 70]
                );

                Media::addJsDef([
                    'sj4web_paymentdiscount_fallback_mode' => true,
                    'sj4web_paymentdiscount_voucher_value' => $this->getVoucherValue()
                ]);
            }

            return;
        }

        // Resync hors checkout
        if (!($ctrl instanceof ModuleFrontController) &&
            !Tools::getIsset('ajax') &&
            !($ctrl instanceof AdminController)) {
            $this->syncVoucher();
        }
    }


//    /**
//     * On refait une vérification avant d'envoyer les données de la comamande au système de paiement
//     * @param $params
//     * @return void
//     */
//    public function hookActionValidateOrder($params)
//    {
//        $cart = $params['cart'];
//        if (!$cart || !$cart->id) return;
//
//        $paymentModule = $params['order']->payment ?? '';
//
//        $idRule = (int)CartRule::getIdByCode($this->getVoucherCode());
//        if (!$idRule) return;
//
//        $hasRule = $this->cartHasRule((int)$cart->id, $idRule);
//        $isAllowedPayment = $this->isPaymentAllowed($paymentModule);
//
//        if ($hasRule && !$isAllowedPayment) {
//            $cart->removeCartRule($idRule);
//            $this->debugLog(
//                $this->trans('Discount removed at validation, payment: %s', ['%s' => $paymentModule], 'Modules.Sj4webPaymentdiscount.Admin'),
//                1, 'Cart', $cart->id
//            );
//        }
//    }

    public function hookActionCarrierProcess($params)
    {
        $this->syncVoucher();
    }

    /**
     * Hook personnalisé appelé AVANT le return de Cart::getCartRules()
     * Permet de retirer le BR si le paiement n'est pas autorisé
     * ⚠️ NE S'ACTIVE QUE pendant validateOrder()
     */
    public function hookActionSjPayDisCartGetCartRules($params)
    {
        $cart = $params['cart'];

        $this->fileLog("=== HOOK actionSjPayDisCartGetCartRules ===", ['cart_id' => $cart->id]);

        // ⚡ IMPORTANT : Vérifier qu'on est bien dans un contexte de validation de commande
        // getCartRules() est appelé partout (affichage panier, checkout, etc.)
        // On ne doit agir QUE pendant validateOrder()

        // Méthode 1 : Vérifier la stack trace avec plus de profondeur (avec arguments)
        $backtrace = debug_backtrace(0, 40); // 0 = inclure les arguments
        $calledFromValidateOrder = false;
        $paymentMethodFromValidateOrder = null;

        // Logging du backtrace pour debug
        $backtraceInfo = [];
        foreach ($backtrace as $i => $trace) {
            $backtraceInfo[] = sprintf("#%d %s::%s",
                $i,
                $trace['class'] ?? 'N/A',
                $trace['function'] ?? 'N/A'
            );

            if (isset($trace['function']) && $trace['function'] === 'validateOrder') {
                $calledFromValidateOrder = true;

                // Récupérer le paramètre $payment_method (4ème paramètre de validateOrder)
                // Signature : validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown', ...)
                if (isset($trace['args'][3])) {
                    $paymentMethodFromValidateOrder = $trace['args'][3];
                }

                $this->fileLog("✅ Appelé depuis validateOrder", [
                    'class' => $trace['class'] ?? 'N/A',
                    'position_in_stack' => $i,
                    'payment_method_param' => $paymentMethodFromValidateOrder
                ]);
                break;
            }
        }

        if (!$calledFromValidateOrder) {
            $this->fileLog("SKIP: Pas appelé depuis validateOrder (contexte normal)", [
                'backtrace' => implode(" -> ", array_slice($backtraceInfo, 0, 10))
            ]);
            return;
        }

        // Récupérer l'ID de notre règle
        $idRule = (int)CartRule::getIdByCode($this->getVoucherCode());
        if (!$idRule) {
            $this->fileLog("SKIP: Voucher not found");
            return;
        }

        // Vérifier si le BR est dans le panier
        $hasRule = $this->cartHasRule((int)$cart->id, $idRule);
        if (!$hasRule) {
            $this->fileLog("SKIP: CartRule not in cart");
            return;
        }

        $this->fileLog("CartRule present in cart", ['id_rule' => $idRule]);

        // Récupérer le module de paiement : priorité au paramètre de validateOrder
        $paymentModule = null;

        if ($paymentMethodFromValidateOrder && $paymentMethodFromValidateOrder !== 'Unknown') {
            // Mapper le nom de paiement vers le type configuré
            $paymentModule = $this->mapPaymentNameToType($paymentMethodFromValidateOrder);
            $this->fileLog("Module de paiement détecté depuis validateOrder", [
                'payment_method_name' => $paymentMethodFromValidateOrder,
                'mapped_to' => $paymentModule
            ]);
        }

        // Fallback sur le cookie si disponible
        if (!$paymentModule) {
            $paymentModule = $this->context->cookie->payment_module ?? null;
            $this->fileLog("Module de paiement depuis cookie (fallback)", [
                'payment_module' => $paymentModule
            ]);
        }

        if (!$paymentModule) {
            $this->fileLogAlways("WARNING: Aucun module de paiement détecté dans validateOrder");
            return;
        }

        // Vérifier si le paiement est autorisé
        $isAllowedPayment = $this->isPaymentAllowed($paymentModule);

        $this->fileLog("Test autorisation", [
            'payment_module' => $paymentModule,
            'is_allowed' => $isAllowedPayment ? 'YES' : 'NO',
            'allowed_modules' => $this->getAllowedModules()
        ]);

        // Si le paiement n'est PAS autorisé, on retire le BR du panier
        if (!$isAllowedPayment) {
            if ($cart->removeCartRule($idRule)) {
                $this->fileLogAlways("✅ CartRule removed (unauthorized payment: $paymentModule)", [
                    'cart_id' => $cart->id,
                    'payment_module' => $paymentModule
                ]);

                $this->debugLog(
                    $this->trans('CartRule removed before getCartRules() return (unauthorized payment: %payment%)',
                        ['%payment%' => $paymentModule],
                        'Modules.Sj4webPaymentdiscount.Admin'),
                    1, 'Cart', $cart->id
                );
            } else {
                $this->fileLogAlways("ERROR: Failed to remove CartRule", [
                    'cart_id' => $cart->id,
                    'payment_module' => $paymentModule
                ]);
            }
        }

    }

    /**
     * Fallback hook si l'override n'est pas installé
     */
    public function hookDisplayPaymentTop($params)
    {
        if (Configuration::get($this->name . '_DEGRADED_MODE')) {
            $this->correctCartTotalFallback();
        }
    }

    /**
     * Notre hook personnalisé (appelé par l'override)
     * Ne s'active QUE dans le bon contexte
     */
    public function hookActionSjPayDisCartGetOrderTotal($params)
    {
        $cart = $params['cart'];
        $total = &$params['total'];
        $withTaxes = $params['with_taxes'];
        $type = $params['type'];

        $this->fileLog("=== HOOK actionSjPayDisCartGetOrderTotal ===", [
            'cart_id' => $cart->id,
            'total' => $total,
            'type' => $type
        ]);

        // Ne traiter que les calculs de total final
        if ($type !== Cart::BOTH && $type !== Cart::BOTH_WITHOUT_SHIPPING) {
            $this->fileLog("SKIP: Type not handled");
            return;
        }

        // Vérifier le contexte de paiement
        $inPaymentContext = $this->isInPaymentContext();

        if (!$inPaymentContext) {
            $this->fileLog("SKIP: Not in payment context");
            return;
        }

        $paymentModuleRaw = $this->getCurrentPaymentModule();

        if (!$paymentModuleRaw) {
            $this->fileLog("SKIP: No payment module detected");
            return;
        }

        // Ignorer les modules "techniques" qui ne sont pas des vrais paiements
        if ($this->isSystemModule($paymentModuleRaw)) {
            $this->fileLog("SKIP: System module", ['module' => $paymentModuleRaw]);
            return;
        }

        // Mapper le nom du module : "payplug" → "payplug:standard", etc.
        $paymentModule = $this->mapPaymentNameToType($paymentModuleRaw);

        $this->fileLog("Payment module mapped", [
            'raw' => $paymentModuleRaw,
            'mapped' => $paymentModule
        ]);

        // Vérifier que le voucher existe
        $idRule = (int)CartRule::getIdByCode($this->getVoucherCode());
        if (!$idRule) {
            $this->fileLog("SKIP: Voucher not found");
            return;
        }

        $hasRule = $this->cartHasRule((int)$cart->id, $idRule);
        $isAllowedPayment = $this->isPaymentAllowed($paymentModule);

        $this->fileLog("Payment analysis", [
            'payment_module' => $paymentModule,
            'has_voucher' => $hasRule,
            'is_allowed' => $isAllowedPayment
        ]);

        // Si le bon est présent mais le paiement non autorisé, annuler la réduction dans le total
        if ($hasRule && !$isAllowedPayment) {
            $cartRule = new CartRule($idRule);
            $discountValue = $cartRule->getContextualValue(
                $withTaxes,
                $this->context,
                Cart::ONLY_PRODUCTS,
                null,
                false,
                $cart
            );

            $total += $discountValue; // Annuler la réduction

            $this->fileLogAlways("⚠️ Discount cancelled in total (unauthorized payment)", [
                'cart_id' => $cart->id,
                'payment_module' => $paymentModule,
                'discount_value' => $discountValue,
                'new_total' => $total
            ]);

            $this->debugLog(
                $this->trans('Total recalculated via hook (payment %payment% not allowed) - discount cancelled: %amount%',
                    ['%payment%' => $paymentModule, '%amount%' => sprintf('%.2f', $discountValue)],
                    'Modules.Sj4webPaymentdiscount.Admin'),
                1, 'Cart', $cart->id
            );
        }
    }

    /**
     * Logger dans un fichier dédié (seulement si mode debug activé)
     */
    private function fileLog($message, $data = null)
    {
        if (!$this->isDebugMode()) return;

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";

        if ($data !== null) {
            $logMessage .= "\n" . print_r($data, true);
        }

        $logMessage .= "\n" . str_repeat('-', 80) . "\n";

        @file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Logger critique (toujours écrit, même sans mode debug)
     */
    private function fileLogAlways($message, $data = null)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";

        if ($data !== null) {
            $logMessage .= "\n" . print_r($data, true);
        }

        $logMessage .= "\n" . str_repeat('-', 80) . "\n";

        @file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        error_log("PaymentDiscount MODULE: $message");
    }
    /**
     * Mode dégradé : correction au niveau paiement
     */
    protected function correctCartTotalFallback(): void
    {
        $cart = $this->context->cart;
        if (!$cart || !$cart->id) return;

        $paymentModule = $this->getCurrentPaymentModule();
        if (!$paymentModule) return;

        $idRule = (int)CartRule::getIdByCode($this->getVoucherCode());
        if (!$idRule) return;

        $hasRule = $this->cartHasRule((int)$cart->id, $idRule);
        $isAllowedPayment = $this->isPaymentAllowed($paymentModule);

        if ($hasRule && !$isAllowedPayment) {
            $cart->removeCartRule($idRule);
            $this->debugLog(
                $this->trans('Discount removed (degraded mode, payment: %s)', ['%s' => $paymentModule], 'Modules.Sj4webPaymentdiscount.Admin'),
                1, 'Cart', $cart->id
            );
        }
    }

    // ===== MÉTHODES UTILITAIRES (identiques) =====

    /**
     * Vérifier si on est dans un contexte de paiement
     */
    protected function isInPaymentContext(): bool
    {
        $controller = $this->context->controller;

        // Contexte 1 : Page de commande
        if ($controller instanceof OrderController) {
            return true;
        }

        // Contexte 2 : Contrôleur de module de paiement
        if ($controller instanceof ModuleFrontController) {
            $moduleName = get_class($controller);
            // Vérifier si c'est un module de paiement connu
            foreach ($this->getAllowedModules() as $allowedModule) {
                if (stripos($moduleName, $allowedModule) !== false) {
                    return true;
                }
            }
        }

        // Contexte 3 : Requête AJAX de validation de commande
        if (Tools::getValue('ajax') &&
            (Tools::getValue('action') === 'submitOrder' ||
                Tools::getValue('submitOrder') ||
                Tools::getValue('confirmOrder'))) {
            return true;
        }

        // Contexte 4 : Page de validation spécifique
        if (Tools::getValue('step') === 'payment' ||
            Tools::getValue('payment-option') ||
            Tools::getValue('module')) {
            return true;
        }

        return false;
    }

    /**
     * Détecter les modules système à ignorer
     */
    protected function isSystemModule(string $moduleName): bool
    {
        $systemModules = [
            'ps_shoppingcart',  // Module panier
            'ps_checkout',      // Module checkout
            'blockcart',        // Bloc panier
            'ps_cartquality',   // Qualité panier
            'ps_crossselling',  // Ventes croisées
        ];

        $moduleNameLower = strtolower($moduleName);

        foreach ($systemModules as $systemModule) {
            if (strpos($moduleNameLower, strtolower($systemModule)) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function getCurrentPaymentModule(): ?string
    {
        // Priorité 0 : Cookie (seulement si on est dans le bon contexte)
        if ($this->isInPaymentContext() && isset($this->context->cookie->payment_module)) {
            $cookieModule = $this->context->cookie->payment_module;
            if (!$this->isSystemModule($cookieModule)) {
                $this->fileLog("Module detected via cookie: $cookieModule");
                return $cookieModule;
            }
        }

        // Priorité 1 : Paramètre direct 'module' (validation PayPal/autres)
        if ($module = Tools::getValue('module')) {
            if (!$this->isSystemModule($module)) {
                $this->fileLog("Module detected via Tools::getValue('module'): $module");
                return $module;
            }
        }

        // Priorité 2 : Payment option (sélection checkout)
        if ($paymentOption = Tools::getValue('payment-option')) {
            if (!$this->isSystemModule($paymentOption)) {
                $this->fileLog("Module detected via payment-option: $paymentOption");
                return $paymentOption;
            }
        }

        // Priorité 3 : Paramètre fc (fallback pour certains modules)
        if ($fc = Tools::getValue('fc')) {
            if (!$this->isSystemModule($fc)) {
                $this->fileLog("Module detected via fc: $fc");
                return $fc;
            }
        }

        return null;
    }

    /**
     * Synchronisation des bons de réduction selon les paliers multiples
     * Version 1.2.0 - Support des paliers multiples
     */
    protected function syncVoucher(): void
    {
        $cart = $this->context->cart;
        if (!$cart || !$cart->id) {
            return;
        }

        $totalProductsTtc = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);

        // Récupérer le module de paiement actuel (peut être null hors contexte paiement)
        $paymentModule = $this->getCurrentPaymentModule();

        // Si on a un module de paiement, le mapper
        if ($paymentModule) {
            $paymentModule = $this->mapPaymentNameToType($paymentModule);
        }

        $this->fileLog("syncVoucher: Start", [
            'cart_id' => $cart->id,
            'cart_total' => $totalProductsTtc,
            'payment_module' => $paymentModule
        ]);

        // ✅ Récupérer toutes les règles actives
        $allRules = PaymentDiscountRule::getActiveRules();

        if (empty($allRules)) {
            $this->fileLog("syncVoucher: No active rules found");
            return;
        }

        // ✅ Trouver la meilleure règle éligible
        $bestRule = null;

        if ($paymentModule) {
            // On a un module de paiement → chercher la meilleure règle éligible
            $bestRule = PaymentDiscountRule::getBestEligibleRule($totalProductsTtc, $paymentModule);
        } else {
            // Pas de module de paiement (hors contexte paiement)
            // On cherche la meilleure règle basée uniquement sur le seuil
            foreach ($allRules as $rule) {
                if ($totalProductsTtc >= (float) $rule['threshold']) {
                    $bestRule = $rule;
                    break; // Déjà trié par threshold DESC
                }
            }
        }

        // ✅ Récupérer tous les BR actuellement dans le panier
        $currentCartRules = $cart->getCartRules(CartRule::FILTER_ACTION_ALL, false);
        $currentVoucherCodes = array_column($currentCartRules, 'code');

        // ✅ Récupérer tous les codes de voucher gérés par notre module
        $moduleManagedCodes = array_column($allRules, 'voucher_code');

        $this->fileLog("syncVoucher: Analysis", [
            'best_rule' => $bestRule ? $bestRule['name'] : 'NONE',
            'current_vouchers' => $currentVoucherCodes,
            'module_managed_codes' => $moduleManagedCodes
        ]);

        // ✅ Cas 1 : Une règle est éligible
        if ($bestRule) {
            $bestVoucherCode = $bestRule['voucher_code'];
            $idBestRule = (int) CartRule::getIdByCode($bestVoucherCode);

            if (!$idBestRule) {
                $this->fileLogAlways("syncVoucher: ERROR - Voucher code not found in PrestaShop", [
                    'voucher_code' => $bestVoucherCode
                ]);
                return;
            }

            // Retirer tous les BR gérés par notre module SAUF le meilleur
            foreach ($allRules as $rule) {
                if ($rule['voucher_code'] === $bestVoucherCode) {
                    continue; // Garder le meilleur
                }

                $idRuleToRemove = (int) CartRule::getIdByCode($rule['voucher_code']);
                if ($idRuleToRemove && $this->cartHasRule((int) $cart->id, $idRuleToRemove)) {
                    $cart->removeCartRule($idRuleToRemove);
                    $this->fileLog("syncVoucher: Removed inferior rule", [
                        'removed_code' => $rule['voucher_code'],
                        'removed_threshold' => $rule['threshold']
                    ]);
                }
            }

            // Ajouter le meilleur BR s'il n'est pas déjà présent
            if (!$this->cartHasRule((int) $cart->id, $idBestRule)) {
                // ✅ VALIDATION DU BR AVANT AJOUT (priorité, compatibilité, etc.)
                $cartRule = new CartRule($idBestRule, $this->context->language->id);
                $validationResult = $cartRule->checkValidity($this->context, false, false, false, false);

                if ($validationResult === true) {
                    $cart->addCartRule($idBestRule);
                    $this->fileLog("syncVoucher: BR ajouté après validation", [
                        'cart_id' => $cart->id,
                        'voucher_code' => $bestVoucherCode,
                        'rule_name' => $bestRule['name'],
                        'threshold' => $bestRule['threshold'],
                        'cart_rule_priority' => $cartRule->priority
                    ]);
                } else {
                    // Le BR n'est pas valide (conflit de priorité, incompatibilité, etc.)
                    $this->fileLogAlways("syncVoucher: BR non ajouté - validation échouée", [
                        'cart_id' => $cart->id,
                        'voucher_code' => $bestVoucherCode,
                        'validation_error' => is_string($validationResult) ? $validationResult : 'Unknown error',
                        'cart_rule_priority' => $cartRule->priority,
                        'existing_cart_rules' => array_map(function ($cr) {
                            return ['id' => $cr['id_cart_rule'], 'name' => $cr['name'], 'priority' => $cr['priority']];
                        }, $currentCartRules)
                    ]);

                    $this->debugLog(
                        $this->trans('syncVoucher: Discount validation failed: %error%',
                            ['%error%' => is_string($validationResult) ? $validationResult : 'Unknown'],
                            'Modules.Sj4webPaymentdiscount.Admin'),
                        2,
                        'Cart',
                        $cart->id
                    );
                }
            }
        } else {
            // ✅ Cas 2 : Aucune règle n'est éligible → retirer tous les BR gérés par notre module
            foreach ($allRules as $rule) {
                $idRuleToRemove = (int) CartRule::getIdByCode($rule['voucher_code']);
                if ($idRuleToRemove && $this->cartHasRule((int) $cart->id, $idRuleToRemove)) {
                    $cart->removeCartRule($idRuleToRemove);
                    $this->fileLog("syncVoucher: Removed rule (no longer eligible)", [
                        'removed_code' => $rule['voucher_code'],
                        'threshold' => $rule['threshold']
                    ]);
                }
            }
        }

        $this->fileLog("syncVoucher: End");
    }

    /**
     * Wrapper public pour appeler syncVoucher() depuis les contrôleurs
     *
     * @return void
     */
    public function callSyncVoucher()
    {
        $this->syncVoucher();
    }

    /**
     * Mapper un nom de moyen de paiement vers un type configuré
     * Ex: "Payplug" → "payplug:standard"
     *     "Apple Pay" → "payplug:applepay"
     *     "Paiement en 3x sans frais oney" → "payplug:oney_x3_without_fees"
     */
    protected function mapPaymentNameToType(string $paymentName): string
    {
        $paymentNameLower = strtolower($paymentName);

        // Mapping PayPlug
        if (stripos($paymentName, 'apple pay') !== false) {
            return 'payplug:applepay';
        }

        if (stripos($paymentName, 'bancontact') !== false) {
            return 'payplug:bancontact';
        }

        if (stripos($paymentName, 'oney') !== false || stripos($paymentName, 'fois') !== false) {
            // Détecter 3x ou 4x
            if (stripos($paymentName, '3') !== false || stripos($paymentName, 'three') !== false) {
                return 'payplug:oney_x3_without_fees';
            }
            if (stripos($paymentName, '4') !== false || stripos($paymentName, 'four') !== false) {
                return 'payplug:oney_x4_without_fees';
            }
            // Par défaut, retourner oney_x3
            return 'payplug:oney_x3_without_fees';
        }

        // Si c'est "Payplug" sans sous-type, on retourne "payplug:standard"
        if (stripos($paymentName, 'payplug') !== false || $paymentNameLower === 'payplug') {
            return 'payplug:standard';
        }

        // Mapping autres modules
        if (stripos($paymentName, 'virement') !== false || stripos($paymentName, 'wire') !== false) {
            return 'ps_wirepayment';
        }

        if (stripos($paymentName, 'paypal') !== false) {
            return 'paypal';
        }

        // Par défaut, retourner le nom en minuscule
        return strtolower($paymentName);
    }

    protected function isPaymentAllowed(string $paymentModule): bool
    {
        if (empty($paymentModule)) return false;

        $paymentLower = strtolower($paymentModule);
        $allowedModules = array_map('strtolower', $this->getAllowedModules());

        // Vérification 1 : Correspondance exacte
        if (in_array($paymentLower, $allowedModules)) {
            return true;
        }

        // Vérification 2 : Correspondance partielle (pour compatibilité ancienne syntaxe)
        // Si on configure "payplug" et qu'on détecte "payplug:standard", on accepte
        // MAIS PAS l'inverse (si on configure "payplug:standard", on n'accepte PAS "payplug" seul)
        foreach ($allowedModules as $allowed) {
            // Si le module configuré est simple (sans :) et que le paiement détecté commence par celui-ci
            if (strpos($allowed, ':') === false && strpos($paymentLower, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function cartHasRule(int $idCart, int $idRule): bool
    {
        $rows = Db::getInstance()->executeS(
            'SELECT 1 FROM ' . _DB_PREFIX_ . 'cart_cart_rule
             WHERE id_cart=' . (int)$idCart . ' AND id_cart_rule=' . (int)$idRule . ' LIMIT 1'
        );
        return !empty($rows);
    }

    protected function getVoucherValue(): float
    {
        $idRule = (int)CartRule::getIdByCode($this->getVoucherCode());
        if (!$idRule) return 0;

        $cartRule = new CartRule($idRule);
        return (float)$cartRule->getContextualValue(
            true, $this->context, Cart::ONLY_PRODUCTS, null, false, $this->context->cart
        );
    }

    /**
     * Diagnostic pour l'admin
     * Dans formulaire de configuration avancée
     */
    public function getContent()
    {
        $output = '';

        // Traiter la soumission du formulaire
        if (Tools::isSubmit('submit' . $this->name)) {
            if ($this->postProcess()) {
                $output .= $this->displayConfirmation($this->trans('Configuration saved successfully', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            } else {
                $output .= $this->displayError($this->trans('Error during save', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            }
        }

        // Assigner les variables au template
        $this->context->smarty->assign([
            // Statut du module
            'override_mode' => Configuration::get($this->name . '_OVERRIDE_MODE'),
            'degraded_mode' => Configuration::get($this->name . '_DEGRADED_MODE'),

            // Valeurs de configuration
            'voucher_code' => Configuration::get($this->name . '_VOUCHER_CODE', 'PAY5'),
            'threshold' => Configuration::get($this->name . '_THRESHOLD', '500.00'),
            'allowed_modules' => Configuration::get($this->name . '_ALLOWED_MODULES', "payplug\nps_wirepayment"),
            'debug_mode' => Configuration::get($this->name . '_DEBUG_MODE', false),

            // Informations du module
            'module_name' => $this->name,
            'module_display_name' => $this->displayName,
            'module_version' => $this->version,

            // Modules de paiement installés (pour l'aide)
            'payment_modules' => $this->getInstalledPaymentModules(),

            // URLs et tokens
            'current_url' => $this->context->link->getAdminLink('AdminModules', false)
                . '&configure=' . $this->name
                . '&tab_module=' . $this->tab
                . '&module_name=' . $this->name,
            'token' => Tools::getAdminTokenLite('AdminModules')
        ]);

        // Générer le contenu via le template
        $output .= $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output;
    }

    /**
     * Traitement de la soumission du formulaire
     */
    protected function postProcess(): bool
    {
        try {
            // Validation des données
            $voucherCode = trim(Tools::getValue('VOUCHER_CODE'));
            $threshold = (float)Tools::getValue('THRESHOLD');
            $allowedModules = trim(Tools::getValue('ALLOWED_MODULES'));
            $debugMode = (bool)Tools::getValue('DEBUG_MODE');

            // Validations
            if (empty($voucherCode)) {
                throw new Exception($this->trans('The discount voucher code is required', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            }

            if ($threshold <= 0) {
                throw new Exception($this->trans('The minimum threshold must be greater than 0', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            }

            if (empty($allowedModules)) {
                throw new Exception($this->trans('At least one payment module must be specified', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            }

            // Vérifier que le bon de réduction existe
            if (!CartRule::getIdByCode($voucherCode)) {
                $this->_warnings[] = $this->trans('Warning: Discount voucher "%code%" does not exist in PrestaShop', ['%code%' => $voucherCode], 'Modules.Sj4webPaymentdiscount.Admin');
            }

            // Traiter la liste des modules
            $modulesList = array_filter(array_map('trim', explode("\n", $allowedModules)));
            if (empty($modulesList)) {
                throw new Exception($this->trans('Invalid format for payment modules', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            }

            // Sauvegarder la configuration
            Configuration::updateValue($this->name . '_VOUCHER_CODE', $voucherCode);
            Configuration::updateValue($this->name . '_THRESHOLD', $threshold);
            Configuration::updateValue($this->name . '_ALLOWED_MODULES', implode("\n", $modulesList));
            Configuration::updateValue($this->name . '_DEBUG_MODE', $debugMode);

            // Log pour debug
            $this->debugLog(
                $this->trans('Configuration updated - Code: %code%, Threshold: %threshold%, Modules: %modules%, Debug: %debug%', [
                    '%code%' => $voucherCode,
                    '%threshold%' => $threshold,
                    '%modules%' => implode(', ', $modulesList),
                    '%debug%' => ($debugMode ? $this->trans('Yes', [], 'Modules.Sj4webPaymentdiscount.Admin') : $this->trans('No', [], 'Modules.Sj4webPaymentdiscount.Admin'))
                ], 'Modules.Sj4webPaymentdiscount.Admin'),
                1, 'Module'
            );

            return true;

        } catch (Exception $e) {
            $this->_errors[] = $e->getMessage();
            return false;
        }
    }

    /**
     * Récupérer la liste des modules de paiement installés
     * (pour la section d'aide)
     */
    protected function getInstalledPaymentModules(): array
    {
        $modules = [];

        try {
            $sql = 'SELECT m.name, m.display_name 
                FROM ' . _DB_PREFIX_ . 'module m
                WHERE m.active = 1 
                AND EXISTS (
                    SELECT 1 FROM ' . _DB_PREFIX_ . 'hook_module hm 
                    INNER JOIN ' . _DB_PREFIX_ . 'hook h ON hm.id_hook = h.id_hook 
                    WHERE hm.id_module = m.id_module 
                    AND h.name IN ("displayPayment", "paymentOptions", "displayPaymentEU")
                )
                ORDER BY m.name';

            $results = Db::getInstance()->executeS($sql);

            if ($results) {
                foreach ($results as $row) {
                    $modules[] = [
                        'name' => $row['name'],
                        'display_name' => $row['display_name'] ?: $row['name']
                    ];
                }
            }
        } catch (Exception $e) {
            // En cas d'erreur, retourner une liste par défaut
            $modules = [
                ['name' => 'payplug', 'display_name' => 'PayPlug'],
                ['name' => 'ps_wirepayment', 'display_name' => $this->trans('Bank wire', [], 'Modules.Sj4webPaymentdiscount.Admin')],
                ['name' => 'paypal', 'display_name' => 'PayPal'],
                ['name' => 'stripe', 'display_name' => 'Stripe']
            ];
        }

        return $modules;
    }

    /**
     * Remplacer les constantes par des getters configurables
     */
    public function getVoucherCode(): string
    {
        return Configuration::get($this->name . '_VOUCHER_CODE', 'PAY5');
    }

    public function getThreshold(): float
    {
        return (float)Configuration::get($this->name . '_THRESHOLD', 500.00);
    }

    public function getAllowedModules(): array
    {
        $modules = Configuration::get($this->name . '_ALLOWED_MODULES', "payplug\nps_wirepayment");
        return array_filter(array_map('trim', explode("\n", $modules)));
    }

}