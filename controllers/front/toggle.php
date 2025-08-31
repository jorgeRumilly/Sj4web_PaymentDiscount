<?php

class Sj4web_PaymentDiscountToggleModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function __construct()
    {
        $this->module = Module::getInstanceByName('sj4web_paymentdiscount');
        parent::__construct();
    }


    public function init()
    {
        parent::init();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'Method not allowed']));
        }

        try {
            $translator = Context::getContext()->getTranslator();
            $input = Tools::file_get_contents('php://input');
            $p = json_decode($input, true) ?: [];

            $enable = !empty($p['enable']);
            $code = (string)($p['code'] ?? $this->module->getVoucherCode());
            $paymentModule = (string)($p['payment_module'] ?? '');

            $cart = $this->context->cart;
            if (!$cart || !$cart->id) {
                return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'No cart']));
            }

            $idRule = (int)CartRule::getIdByCode($code);
            if (!$idRule) {
                return $this->ajaxDie(json_encode(['ok' => false, 'msg' => 'Rule not found']));
            }

            $totalProductsTtc = (float)$cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
            $before = $this->hasRule((int)$cart->id, $idRule);

            // Sauvegarder le choix de paiement en cookie pour l'override
            if (!empty($paymentModule)) {
                $this->context->cookie->payment_module = $paymentModule;
                $this->context->cookie->write();
            }

            // LOGIQUE CORRIGÉE : Vérification côté serveur
            $isPaymentAllowed = $this->isPaymentAllowed($paymentModule);
            $shouldHaveVoucher = $isPaymentAllowed && ($totalProductsTtc >= $this->module->getThreshold());

            // Log pour debug avec traduction
            $message_log = $translator->trans('Toggle - Payment: %payment%, Allowed: %allowed%, Total: %total%, Threshold: %threshold%, Should have: %should%, Has: %has%', [
                '%payment%' => $paymentModule,
                '%allowed%' => $isPaymentAllowed ? 'YES' : 'NO',
                '%total%' => sprintf('%.2f', $totalProductsTtc),
                '%threshold%' => sprintf('%.2f', $this->module->getThreshold()),
                '%should%' => $shouldHaveVoucher ? 'YES' : 'NO',
                '%has%' => $before ? 'YES' : 'NO'
            ], 'Modules.Sj4webPaymentdiscount.Admin');

            // Log pour debug
            $this->module->debugLog($message_log, 1, 'Cart', $cart->id);

            // Appliquer la logique correcte
            $changed = false;
            $after = $before;

            if ($shouldHaveVoucher && !$before) {
                // Ajouter le bon
                if ($cart->addCartRule($idRule)) {
                    $changed = true;
                    $after = true;
                    $this->module->debugLog(
                        $translator->trans('Discount added (authorized payment: %payment%)', ['%payment%' => $paymentModule], 'Modules.Sj4webPaymentdiscount.Admin'),
                        1, 'Cart', $cart->id
                    );
                }
            } elseif (!$shouldHaveVoucher && $before) {
                // Retirer le bon
                if ($cart->removeCartRule($idRule)) {
                    $changed = true;
                    $after = false;
                    $this->module->debugLog(
                        $translator->trans('Discount removed (unauthorized payment: %payment% or threshold not reached)', ['%payment%' => $paymentModule], 'Modules.Sj4webPaymentdiscount.Admin'),
                        1, 'Cart', $cart->id
                    );
                }
            }

            // Vérification finale
            $finalCheck = $this->hasRule((int)$cart->id, $idRule);

            return $this->ajaxDie(json_encode([
                'ok' => true,
                'changed' => $changed,
                'before' => $before,
                'after' => $finalCheck, // Utiliser la vérification réelle
                'debug' => [
                    'payment_module' => $paymentModule,
                    'is_payment_allowed' => $isPaymentAllowed,
                    'total_products' => $totalProductsTtc,
                    'threshold' => $this->module->getThreshold(),
                    'should_have_voucher' => $shouldHaveVoucher,
                    'final_has_voucher' => $finalCheck
                ]
            ]));

        } catch (Exception $e) {
            $this->module->debugLog(
                $translator->trans('Toggle Error: %error%', ['%error%' => $e->getMessage()], 'Modules.Sj4webPaymentdiscount.Admin'),
                3,'Module'
            );

            return $this->ajaxDie(json_encode([
                'ok' => false,
                'msg' => 'Internal error',
                'debug' => _PS_MODE_DEV_ ? $e->getMessage() : null
            ]));
        }
    }

    private function hasRule(int $idCart, int $idRule): bool
    {
        $rows = Db::getInstance()->executeS(
            'SELECT 1 FROM ' . _DB_PREFIX_ . 'cart_cart_rule
             WHERE id_cart=' . (int)$idCart . ' AND id_cart_rule=' . (int)$idRule . ' LIMIT 1'
        );
        return !empty($rows);
    }

    /**
     * Vérifier si le mode de paiement est autorisé
     * Supporte maintenant la syntaxe module:subtype
     */
    private function isPaymentAllowed(string $paymentModule): bool
    {
        if (empty($paymentModule)) return false;

        $paymentLower = strtolower($paymentModule);
        $allowedModules = $this->module->getAllowedModules();

        foreach ($allowedModules as $allowed) {
            $allowedLower = strtolower($allowed);
            
            // Correspondance exacte (nouveau format avec :)
            if ($paymentLower === $allowedLower) {
                return true;
            }
            
            // Compatibilité ancienne : recherche partielle
            if (strpos($paymentLower, $allowedLower) !== false || strpos($allowedLower, $paymentLower) !== false) {
                return true;
            }
        }

        return false;
    }
}