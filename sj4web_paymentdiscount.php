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
        $this->version = '1.2.1';
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
//                $validationResult = $cartRule->checkValidity($this->context, false, false, false, false);
                $validationResult = $cartRule->checkValidity($this->context);

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
     * Interface d'administration v1.2.0 - Gestion des paliers multiples
     */
    public function getContent()
    {
        $output = '';

        // Traiter les actions CRUD sur les paliers
        if (Tools::isSubmit('saveRule')) {
            $output .= $this->processSaveRule();
        } elseif (Tools::isSubmit('deleteRule')) {
            $output .= $this->processDeleteRule();
        } elseif (Tools::isSubmit('statusRule')) {
            $output .= $this->processToggleStatus();
        } elseif (Tools::isSubmit('updateRules')) {
            $output .= $this->processUpdateRules();
        }

        // Afficher le formulaire d'édition/création si nécessaire
        if (Tools::isSubmit('addRule') || Tools::isSubmit('updatepayment_discount_rule') || Tools::getValue('id_rule')) {
            $output .= $this->renderRuleForm();
        } else {
            // Afficher la liste des paliers
            $output .= $this->renderRulesList();

            // Afficher les paramètres généraux (mode dégradé, debug, etc.)
            $output .= $this->renderGeneralSettings();
        }

        return $output;
    }

    /**
     * Afficher la liste des paliers (HelperList)
     */
    protected function renderRulesList()
    {
        $fields_list = [
            'id_rule' => [
                'title' => $this->trans('ID', [], 'Admin.Global'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'name' => [
                'title' => $this->trans('Name', [], 'Admin.Global'),
                'type' => 'text'
            ],
            'voucher_code' => [
                'title' => $this->trans('Voucher Code', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                'type' => 'text'
            ],
            'threshold' => [
                'title' => $this->trans('Threshold', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                'type' => 'price',
                'align' => 'right',
//                'suffix' => ' €'
            ],
            'allowed_modules' => [
                'title' => $this->trans('Payment Methods', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                'callback' => 'displayAllowedModules',
                'callback_object' => $this,
                'orderby' => false,
                'search' => false
            ],
            'position' => [
                'title' => $this->trans('Position', [], 'Admin.Global'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'active' => [
                'title' => $this->trans('Status', [], 'Admin.Global'),
                'active' => 'status',
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-sm',
                'orderby' => false
            ]
        ];

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->identifier = 'id_rule';
        $helper->actions = ['edit', 'delete'];
        $helper->show_toolbar = true;
        $helper->toolbar_btn['new'] = [
            'href' => AdminController::$currentIndex .
                '&configure=' . $this->name .
                '&addRule&token=' . Tools::getAdminTokenLite('AdminModules'),
            'desc' => $this->trans('Add new tier', [], 'Modules.Sj4webPaymentdiscount.Admin')
        ];
        $helper->title = $this->trans('Payment Discount Tiers', [], 'Modules.Sj4webPaymentdiscount.Admin');
        $helper->table = 'payment_discount_rule';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Récupérer les règles
        $rules = PaymentDiscountRule::getActiveRules(null, false); // false = inclure les inactives

        // Formater les données pour le HelperList
        $rules_list = [];
        foreach ($rules as $rule) {
            $rules_list[] = [
                'id_rule' => $rule['id_rule'],
                'name' => $rule['name'],
                'voucher_code' => $rule['voucher_code'],
                'threshold' => $rule['threshold'],
                'allowed_modules' => $rule['allowed_modules'],
                'position' => $rule['position'],
                'active' => $rule['active']
            ];
        }

        return $helper->generateList($rules_list, $fields_list);
    }

    /**
     * Callback pour afficher les modules de paiement autorisés
     */
    public function displayAllowedModules($modules, $form)
    {

        if (empty($modules)) {
            return '<span class="badge badge-warning">' . $this->trans('None', [], 'Admin.Global') . '</span>';
        }

        if (is_array($modules)) {
            $modules_array = array_filter(array_map('trim', $modules));
        } else {
            $modules_array = array_filter(array_map('trim', explode("\n", (string)$modules)));
        }
        $badges = [];
        foreach ($modules_array as $module) {
            $badges[] = '<span class="badge badge-info">' . Tools::safeOutput($module) . '</span>';
        }

        return implode(' ', $badges);
    }

    /**
     * Afficher le formulaire de création/édition de palier (HelperForm)
     */
    protected function renderRuleForm()
    {
        $id_rule = (int)Tools::getValue('id_rule');
        $rule = null;

        if ($id_rule) {
            $rule = new PaymentDiscountRule($id_rule);
        }

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $id_rule ?
                        $this->trans('Edit Tier', [], 'Modules.Sj4webPaymentdiscount.Admin') :
                        $this->trans('Add New Tier', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Tier Name', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                        'name' => 'name',
                        'required' => true,
                        'desc' => $this->trans('E.g., "5% discount - Tier €500"', [], 'Modules.Sj4webPaymentdiscount.Admin')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Voucher Code', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                        'name' => 'voucher_code',
                        'required' => true,
                        'class' => 'uppercase',
                        'desc' => $this->trans('The cart rule code in PrestaShop (e.g., PAY5, PAY7)', [], 'Modules.Sj4webPaymentdiscount.Admin')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Minimum Threshold (€)', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                        'name' => 'threshold',
                        'required' => true,
                        'class' => 'fixed-width-md',
                        'desc' => $this->trans('Minimum cart amount to apply this tier', [], 'Modules.Sj4webPaymentdiscount.Admin')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Allowed Payment Methods', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                        'name' => 'allowed_modules',
                        'rows' => 5,
                        'cols' => 60,
                        'desc' => $this->trans('One payment method per line. E.g., payplug:standard, ps_wirepayment, payplug:applepay', [], 'Modules.Sj4webPaymentdiscount.Admin') .
                            '<br>' . $this->trans('Installed payment modules:', [], 'Modules.Sj4webPaymentdiscount.Admin') . ' ' .
                            implode(', ', array_map(function($m) { return '<strong>' . $m['name'] . '</strong>'; }, $this->getInstalledPaymentModules()))
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Position', [], 'Admin.Global'),
                        'name' => 'position',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans('Display position in the list', [], 'Admin.Global')
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Active', [], 'Admin.Global'),
                        'name' => 'active',
                        'required' => false,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Admin.Global')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Admin.Global')
                            ]
                        ]
                    ],
                    [
                        'type' => 'hidden',
                        'name' => 'id_rule'
                    ]
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'name' => 'saveRule'
                ],
                'buttons' => [
                    [
                        'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                        'title' => $this->trans('Cancel', [], 'Admin.Actions'),
                        'icon' => 'process-icon-cancel'
                    ]
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = 'payment_discount_rule';
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = 'id_rule';
        $helper->submit_action = 'saveRule';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        if ($rule && Validate::isLoadedObject($rule)) {
            $helper->fields_value['id_rule'] = $rule->id;
            $helper->fields_value['name'] = $rule->name;
            $helper->fields_value['voucher_code'] = $rule->voucher_code;
            $helper->fields_value['threshold'] = $rule->threshold;
            $helper->fields_value['allowed_modules'] = $rule->allowed_modules;
            $helper->fields_value['position'] = $rule->position;
            $helper->fields_value['active'] = $rule->active;
        } else {
            $helper->fields_value['id_rule'] = '';
            $helper->fields_value['name'] = '';
            $helper->fields_value['voucher_code'] = '';
            $helper->fields_value['threshold'] = '500.00';
            $helper->fields_value['allowed_modules'] = "payplug:standard\nps_wirepayment";
            $helper->fields_value['position'] = 1;
            $helper->fields_value['active'] = 1;
        }

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Afficher les paramètres généraux (mode debug, etc.)
     */
    protected function renderGeneralSettings()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('General Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Debug Mode', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                        'name' => 'debug_mode',
                        'is_bool' => true,
                        'desc' => $this->trans('Enable detailed logging (var/logs/payment_discount_module.log)', [], 'Modules.Sj4webPaymentdiscount.Admin'),
                        'values' => [
                            [
                                'id' => 'debug_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Admin.Global')
                            ],
                            [
                                'id' => 'debug_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Admin.Global')
                            ]
                        ]
                    ]
                ],
                'submit' => [
                    'title' => $this->trans('Save Settings', [], 'Admin.Actions'),
                    'name' => 'updateRules'
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = 'payment_discount_settings';
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = 'settings';
        $helper->submit_action = 'updateRules';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value['debug_mode'] = Configuration::get($this->name . '_DEBUG_MODE', false);

        // Afficher le statut du module
        $status_html = '<div class="panel">
            <div class="panel-heading"><i class="icon-info-circle"></i> ' . $this->trans('Module Status', [], 'Modules.Sj4webPaymentdiscount.Admin') . '</div>
            <div class="panel-body">
                <ul>
                    <li><strong>' . $this->trans('Version:', [], 'Admin.Global') . '</strong> ' . $this->version . '</li>
                    <li><strong>' . $this->trans('Override Mode:', [], 'Modules.Sj4webPaymentdiscount.Admin') . '</strong> ' .
                        (Configuration::get($this->name . '_OVERRIDE_MODE') ?
                            '<span class="badge badge-success">' . $this->trans('Active', [], 'Admin.Global') . '</span>' :
                            '<span class="badge badge-danger">' . $this->trans('Inactive', [], 'Admin.Global') . '</span>') .
                    '</li>
                    <li><strong>' . $this->trans('Degraded Mode:', [], 'Modules.Sj4webPaymentdiscount.Admin') . '</strong> ' .
                        (Configuration::get($this->name . '_DEGRADED_MODE') ?
                            '<span class="badge badge-warning">' . $this->trans('Yes', [], 'Admin.Global') . '</span>' :
                            '<span class="badge badge-success">' . $this->trans('No', [], 'Admin.Global') . '</span>') .
                    '</li>
                </ul>
            </div>
        </div>';

        return $status_html . $helper->generateForm([$fields_form]);
    }

    /**
     * Traiter la sauvegarde d'un palier
     */
    protected function processSaveRule()
    {
        $id_rule = (int)Tools::getValue('id_rule');
        $rule = new PaymentDiscountRule($id_rule);

        $rule->name = Tools::getValue('name');
        $rule->voucher_code = Tools::getValue('voucher_code');
        $rule->threshold = (float)Tools::getValue('threshold');
        $rule->allowed_modules = Tools::getValue('allowed_modules');
        $rule->position = (int)Tools::getValue('position', 1);
        $rule->active = (int)Tools::getValue('active', 1);
        $rule->id_shop = Shop::isFeatureActive() ? (int)$this->context->shop->id : null;

        // Vérifier que le voucher existe dans PrestaShop
        if (!CartRule::getIdByCode($rule->voucher_code)) {
            return $this->displayWarning(
                $this->trans('Warning: The voucher code "%code%" does not exist in PrestaShop. Please create it first.',
                    ['%code%' => $rule->voucher_code],
                    'Modules.Sj4webPaymentdiscount.Admin')
            );
        }

        if ($rule->save()) {
            return $this->displayConfirmation($this->trans('Tier saved successfully', [], 'Modules.Sj4webPaymentdiscount.Admin'));
        } else {
            return $this->displayError($this->trans('Error while saving the tier', [], 'Modules.Sj4webPaymentdiscount.Admin'));
        }
    }

    /**
     * Traiter la suppression d'un palier
     */
    protected function processDeleteRule()
    {
        $id_rule = (int)Tools::getValue('id_rule');
        $rule = new PaymentDiscountRule($id_rule);

        if (Validate::isLoadedObject($rule)) {
            if ($rule->delete()) {
                return $this->displayConfirmation($this->trans('Tier deleted successfully', [], 'Modules.Sj4webPaymentdiscount.Admin'));
            }
        }

        return $this->displayError($this->trans('Error while deleting the tier', [], 'Modules.Sj4webPaymentdiscount.Admin'));
    }

    /**
     * Traiter le changement de statut (actif/inactif)
     */
    protected function processToggleStatus()
    {
        $id_rule = (int)Tools::getValue('id_rule');
        $rule = new PaymentDiscountRule($id_rule);

        if (Validate::isLoadedObject($rule)) {
            $rule->active = !$rule->active;
            if ($rule->save()) {
                return $this->displayConfirmation(
                    $this->trans('Status updated successfully', [], 'Modules.Sj4webPaymentdiscount.Admin')
                );
            }
        }

        return $this->displayError($this->trans('Error while updating status', [], 'Modules.Sj4webPaymentdiscount.Admin'));
    }

    /**
     * Traiter la mise à jour des paramètres généraux
     */
    protected function processUpdateRules()
    {
        $debugMode = (bool)Tools::getValue('debug_mode');
        Configuration::updateValue($this->name . '_DEBUG_MODE', $debugMode);

        return $this->displayConfirmation($this->trans('Settings updated successfully', [], 'Modules.Sj4webPaymentdiscount.Admin'));
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