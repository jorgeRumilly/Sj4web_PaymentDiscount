<?php
/**
 * Controller AJAX pour la synchronisation des bons de réduction
 * Version 1.2.0 - Support des paliers multiples
 *
 * @author SJ4WEB.FR
 */

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

//        $this->fileLog("=== INIT TOGGLE CONTROLLER ===");
    }

    public function postProcess()
    {
//        $this->fileLog("=== START POST PROCESS (v1.2.0) ===");

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->fileLog("ERROR: Method not POST", $_SERVER['REQUEST_METHOD']);
            http_response_code(405);
            return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'Method not allowed']));
        }

        try {
            $translator = Context::getContext()->getTranslator();
            $input = Tools::file_get_contents('php://input');
            $p = json_decode($input, true) ?: [];

//            $this->fileLog("Input reçu", $p);

            $paymentModule = (string) ($p['payment_module'] ?? '');

//            $this->fileLog("Variables extraites", [
//                'paymentModule' => $paymentModule
//            ]);

            $cart = $this->context->cart;
            if (!$cart || !$cart->id) {
                $this->fileLog("ERROR: No cart found");
                return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'No cart']));
            }

//            $this->fileLog("Cart trouvé", ['cart_id' => $cart->id]);

            // ✅ Sauvegarder le choix de paiement en cookie
            if (!empty($paymentModule)) {
                $this->context->cookie->payment_module = $paymentModule;
                $this->context->cookie->write();
//                $this->fileLog("Cookie payment_module sauvegardé", $paymentModule);
            }

            $totalProductsTtc = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);

            // ✅ Récupérer l'état AVANT synchronisation
            $before = $this->getCurrentCartRuleCodes($cart);

//            $this->fileLog("État AVANT synchronisation", [
//                'total_products' => $totalProductsTtc,
//                'vouchers_before' => $before
//            ]);

            // ✅ Appeler la méthode syncVoucher() du module
            // Elle gère automatiquement les paliers multiples
            $this->module->callSyncVoucher();

            // ✅ Récupérer l'état APRÈS synchronisation
            $after = $this->getCurrentCartRuleCodes($cart);

//            $this->fileLog("État APRÈS synchronisation", [
//                'vouchers_after' => $after
//            ]);

            // Déterminer si un changement a eu lieu
            $changed = ($before !== $after);

            // ✅ Trouver la meilleure règle éligible actuelle
            $bestRule = null;
            if ($paymentModule) {
                $bestRule = PaymentDiscountRule::getBestEligibleRule($totalProductsTtc, $paymentModule);
            }

            $finalCheck = $this->getCurrentCartRuleCodes($cart);

            $response = [
                'ok' => true,
                'changed' => $changed,
                'before' => $before,
                'after' => $after,
                'best_rule' => $bestRule ? [
                    'name' => $bestRule['name'],
                    'code' => $bestRule['voucher_code'],
                    'threshold' => (float) $bestRule['threshold']
                ] : null,
                'debug' => [
                    'payment_module' => $paymentModule,
                    'total_products' => $totalProductsTtc,
                    'final_cart_rules' => $finalCheck,
                ]
            ];

//            $this->fileLog("Réponse envoyée", $response);
//            $this->fileLog("=== END POST PROCESS (SUCCESS) ===\n\n");

            $this->ajaxRender(json_encode($response));
            exit;

        } catch (Exception $e) {
            $this->fileLog("EXCEPTION CAUGHT", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->module->debugLog(
                $translator->trans('Toggle Error: %error%', ['%error%' => $e->getMessage()], 'Modules.Sj4webPaymentdiscount.Admin'),
                3,
                'Module'
            );

//            $this->fileLog("=== END POST PROCESS (ERROR) ===\n\n");

            $this->ajaxRender(json_encode([
                'ok' => false,
                'msg' => 'Internal error',
                'debug' => _PS_MODE_DEV_ ? $e->getMessage() : null
            ]));
            exit;
        }
    }

    /**
     * Récupérer les codes des CartRules actuellement dans le panier
     *
     * @param Cart $cart
     * @return array
     */
    private function getCurrentCartRuleCodes($cart)
    {
        $cartRules = $cart->getCartRules(CartRule::FILTER_ACTION_ALL, false);
        return array_column($cartRules, 'code');
    }
}
