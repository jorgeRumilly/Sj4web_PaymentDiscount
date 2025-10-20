<?php

class Sj4web_PaymentDiscountToggleModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    private $logFile;

    public function __construct()
    {
        $this->module = Module::getInstanceByName('sj4web_paymentdiscount');
        parent::__construct();
        // Fichier de log dédié
        $this->logFile = _PS_ROOT_DIR_ . '/var/logs/payment_discount_debug.log';
    }

    /**
     * Logger dans un fichier dédié
     */
    private function fileLog($message, $data = null)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";

        if ($data !== null) {
            $logMessage .= "\n" . print_r($data, true);
        }

        $logMessage .= "\n" . str_repeat('-', 80) . "\n";

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);

        // Log aussi dans error_log PHP
        error_log("PaymentDiscount: $message");
    }

    public function init()
    {
        parent::init();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $this->fileLog("=== INIT TOGGLE CONTROLLER ===");
    }

    public function postProcess()
    {
        $this->fileLog("=== START POST PROCESS ===");

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->fileLog("ERROR: Method not POST", $_SERVER['REQUEST_METHOD']);
            http_response_code(405);
            return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'Method not allowed']));
        }

        try {
            $translator = Context::getContext()->getTranslator();
            $input = Tools::file_get_contents('php://input');
            $p = json_decode($input, true) ?: [];

            $this->fileLog("Input reçu", $p);

            $enable = !empty($p['enable']);
            $code = (string)($p['code'] ?? $this->module->getVoucherCode());
            $paymentModule = (string)($p['payment_module'] ?? '');

            $this->fileLog("Variables extraites", [
                'enable' => $enable,
                'code' => $code,
                'paymentModule' => $paymentModule
            ]);

            $cart = $this->context->cart;
            if (!$cart || !$cart->id) {
                $this->fileLog("ERROR: No cart found");
                return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'No cart']));
            }
            $this->fileLog("Cart trouvé", ['cart_id' => $cart->id]);
            $idRule = (int)CartRule::getIdByCode($code);
            $this->fileLog("Recherche voucher", [
                'code' => $code,
                'idRule' => $idRule
            ]);

            if (!$idRule) {
                $this->fileLog("ERROR: Rule not found");
                return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'Rule not found']));
            }

            $totalProductsTtc = (float)$cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
            $before = $this->hasRule((int)$cart->id, $idRule);

            $this->fileLog("État du panier", [
                'total_products' => $totalProductsTtc,
                'has_voucher_before' => $before ? 'YES' : 'NO'
            ]);

            // Sauvegarder le choix de paiement en cookie
            if (!empty($paymentModule)) {
                $this->context->cookie->payment_module = $paymentModule;
                $this->context->cookie->write();
                $this->fileLog("Cookie payment_module sauvegardé", $paymentModule);
            }

            // Vérification côté serveur
            $allowedModules = $this->module->getAllowedModules();
            $this->fileLog("Modules autorisés configurés", $allowedModules);

            $isPaymentAllowed = $this->isPaymentAllowed($paymentModule);
            $this->fileLog("Test isPaymentAllowed", [
                'payment_module' => $paymentModule,
                'is_allowed' => $isPaymentAllowed ? 'YES' : 'NO'
            ]);

            $threshold = $this->module->getThreshold();
            $shouldHaveVoucher = $isPaymentAllowed && ($totalProductsTtc >= $threshold);

            $this->fileLog("Logique de décision", [
                'threshold' => $threshold,
                'is_payment_allowed' => $isPaymentAllowed ? 'YES' : 'NO',
                'total >= threshold' => ($totalProductsTtc >= $threshold) ? 'YES' : 'NO',
                'should_have_voucher' => $shouldHaveVoucher ? 'YES' : 'NO'
            ]);

            // Log pour debug avec traduction
            $message_log = $translator->trans('Toggle - Payment: %payment%, Allowed: %allowed%, Total: %total%, Threshold: %threshold%, Should have: %should%, Has: %has%', [
                '%payment%' => $paymentModule,
                '%allowed%' => $isPaymentAllowed ? 'YES' : 'NO',
                '%total%' => sprintf('%.2f', $totalProductsTtc),
                '%threshold%' => sprintf('%.2f', $threshold),
                '%should%' => $shouldHaveVoucher ? 'YES' : 'NO',
                '%has%' => $before ? 'YES' : 'NO'
            ], 'Modules.Sj4webPaymentdiscount.Admin');

            // Log pour debug
            $this->module->debugLog($message_log, 1, 'Cart', $cart->id);

            // Appliquer la logique
            $changed = false;
            $after = $before;

            if ($shouldHaveVoucher && !$before) {
                $this->fileLog("ACTION: Ajout du voucher");
                // On ajout le BON de Réduction au panier
                if ($cart->addCartRule($idRule)) {
                    $changed = true;
                    $after = true;
                    $this->fileLog("SUCCESS: Voucher ajouté");
                    $this->module->debugLog(
                        $translator->trans('Discount added (authorized payment: %payment%)', ['%payment%' => $paymentModule], 'Modules.Sj4webPaymentdiscount.Admin'),
                        1, 'Cart', $cart->id
                    );
                } else {
                    $this->fileLog("ERROR: Échec ajout voucher");
                }
            } elseif (!$shouldHaveVoucher && $before) {
                $this->fileLog("ACTION: Suppression du voucher");
                // Retirer le bon
                if ($cart->removeCartRule($idRule)) {
                    $changed = true;
                    $after = false;
                    $this->fileLog("SUCCESS: Voucher supprimé");
                    $this->module->debugLog(
                        $translator->trans('Discount removed (unauthorized payment: %payment% or threshold not reached)', ['%payment%' => $paymentModule], 'Modules.Sj4webPaymentdiscount.Admin'),
                        1, 'Cart', $cart->id
                    );
                } else {
                    $this->fileLog("ERROR: Échec suppression voucher");
                }
            } else {
                $this->fileLog("NO ACTION: Aucun changement nécessaire");
            }

            // Vérification finale
            $finalCheck = $this->hasRule((int)$cart->id, $idRule);

            $this->fileLog("État final", [
                'changed' => $changed ? 'YES' : 'NO',
                'before' => $before ? 'YES' : 'NO',
                'after' => $after ? 'YES' : 'NO',
                'final_check' => $finalCheck ? 'YES' : 'NO'
            ]);

            $response = [
                'ok' => true,
                'changed' => $changed,
                'before' => $before,
                'after' => $finalCheck,
                'debug' => [
                    'payment_module' => $paymentModule,
                    'is_payment_allowed' => $isPaymentAllowed,
                    'total_products' => $totalProductsTtc,
                    'threshold' => $threshold,
                    'should_have_voucher' => $shouldHaveVoucher,
                    'final_has_voucher' => $finalCheck,
                    'allowed_modules' => $allowedModules
                ]
            ];

            $this->fileLog("Réponse envoyée", $response);
            $this->fileLog("=== END POST PROCESS (SUCCESS) ===\n\n");

            $this->ajaxRender(json_encode($response));
            exit;

        } catch (Exception $e) {
            $this->fileLog("EXCEPTION CAUGHT", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->module->debugLog(
                $translator->trans('Toggle Error: %error%', ['%error%' => $e->getMessage()], 'Modules.Sj4webPaymentdiscount.Admin'),
                3,'Module'
            );

            $this->fileLog("=== END POST PROCESS (ERROR) ===\n\n");

            $this->ajaxRender(json_encode([
                'ok' => false,
                'msg' => 'Internal error',
                'debug' => _PS_MODE_DEV_ ? $e->getMessage() : null
            ]));
            exit;
        }
    }

    private function hasRule(int $idCart, int $idRule): bool
    {
        $rows = Db::getInstance()->executeS(
            'SELECT 1 FROM ' . _DB_PREFIX_ . 'cart_cart_rule
             WHERE id_cart=' . (int)$idCart . ' AND id_cart_rule=' . (int)$idRule . ' LIMIT 1'
        );
        $result = !empty($rows);

        $this->fileLog("hasRule check", [
            'id_cart' => $idCart,
            'id_rule' => $idRule,
            'result' => $result ? 'YES' : 'NO'
        ]);

        return $result;
    }

    /**
     * Vérifier si le mode de paiement est autorisé
     * Supporte maintenant la syntaxe module:subtype
     */
    private function isPaymentAllowed(string $paymentModule): bool
    {
        if (empty($paymentModule)) {
            $this->fileLog("isPaymentAllowed: empty payment module");
            return false;
        }

        $paymentLower = strtolower($paymentModule);
        $allowedModules = $this->module->getAllowedModules();

        $this->fileLog("isPaymentAllowed - Début test", [
            'payment_module' => $paymentModule,
            'payment_lower' => $paymentLower,
            'allowed_modules' => $allowedModules
        ]);

        foreach ($allowedModules as $allowed) {
            $allowedLower = strtolower($allowed);
            
            // Correspondance exacte (nouveau format avec :)
            if ($paymentLower === $allowedLower) {
                $this->fileLog("isPaymentAllowed: MATCH exacte", [
                    'payment' => $paymentLower,
                    'allowed' => $allowedLower
                ]);
                return true;
            }
            
            // Compatibilité ancienne : recherche partielle
            // Test 2 : Recherche partielle
            $test1 = strpos($paymentLower, $allowedLower) !== false;
            $test2 = strpos($allowedLower, $paymentLower) !== false;

            $this->fileLog("isPaymentAllowed: Test partiel", [
                'payment' => $paymentLower,
                'allowed' => $allowedLower,
                'payment contient allowed' => $test1 ? 'YES' : 'NO',
                'allowed contient payment' => $test2 ? 'YES' : 'NO'
            ]);


            if ($test1 || $test2) {
                $this->fileLog("isPaymentAllowed: MATCH partielle");
                return true;
            }
        }

        $this->fileLog("isPaymentAllowed: NO MATCH - Paiement refusé");
        return false;
    }
}