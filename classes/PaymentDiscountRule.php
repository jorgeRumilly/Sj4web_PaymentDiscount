<?php
/**
 * Payment Discount Rule - ObjectModel
 * Gère les règles de paliers de réduction selon le montant et le mode de paiement
 *
 * @author SJ4WEB.FR
 * @version 1.4.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymentDiscountRule extends ObjectModel
{
    /** @var int ID de la règle */
    public $id_rule;

    /** @var int|null ID de la boutique (multistore) */
    public $id_shop;

    /** @var string Nom de la règle (ex: "Réduction 5% virement") */
    public $name;

    /** @var string Code du bon de réduction PrestaShop */
    public $voucher_code;

    /** @var float Seuil minimum du panier en € */
    public $threshold;

    /** @var float Seuil de déclenchement pour affichage message (optionnel, par défaut = threshold) */
    public $trigger_threshold;

    /** @var string Modules de paiement autorisés (JSON ou lignes séparées) */
    public $allowed_modules;

    /** @var string Message avant atteinte du palier (optionnel) */
    public $message_before;

    /** @var string Message après atteinte du palier (optionnel) */
    public $message_after;

    /** @var string Message intermédiaire (entre palier actuel et suivant, optionnel) */
    public $message_between;

    /** @var int Position/ordre d'affichage */
    public $position;

    /** @var bool Actif ou non */
    public $active;

    /** @var string Date d'ajout */
    public $date_add;

    /** @var string Date de mise à jour */
    public $date_upd;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'sj4web_payment_discount_rule',
        'primary' => 'id_rule',
        'multilang' => false,
        'fields' => [
            'id_shop' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => false,
            ],
            'name' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 255,
            ],
            'voucher_code' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isDiscountName',
                'required' => true,
                'size' => 32,
            ],
            'threshold' => [
                'type' => self::TYPE_FLOAT,
                'validate' => 'isPrice',
                'required' => true,
            ],
            'trigger_threshold' => [
                'type' => self::TYPE_FLOAT,
                'validate' => 'isPrice',
                'required' => false,
            ],
            'allowed_modules' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
            ],
            'message_before' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isCleanHtml',
                'required' => false,
                'size' => 500,
            ],
            'message_after' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isCleanHtml',
                'required' => false,
                'size' => 500,
            ],
            'message_between' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isCleanHtml',
                'required' => false,
                'size' => 500,
            ],
            'position' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedInt',
                'required' => false,
            ],
            'active' => [
                'type' => self::TYPE_BOOL,
                'validate' => 'isBool',
                'required' => false,
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
                'required' => false,
            ],
            'date_upd' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
                'required' => false,
            ],
        ],
    ];

    /**
     * Récupérer toutes les règles actives triées par threshold décroissant
     *
     * @param int|null $idShop ID de la boutique (multistore)
     * @param bool $onlyActive Si false, inclure les règles inactives (pour l'admin)
     * @return array Liste des règles
     */
    public static function getActiveRules($idShop = null, $onlyActive = true)
    {
        if ($idShop === null) {
            $idShop = (int) Context::getContext()->shop->id;
        }

        $sql = 'SELECT *
                FROM `' . _DB_PREFIX_ . self::$definition['table'] . '`
                WHERE 1';

        // Filtrer par statut actif si demandé
        if ($onlyActive) {
            $sql .= ' AND `active` = 1';
        }

        if (Shop::isFeatureActive() && $idShop) {
            $sql .= ' AND (`id_shop` IS NULL OR `id_shop` = ' . (int) $idShop . ')';
        }

        $sql .= ' ORDER BY `threshold` DESC, `position` ASC';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Récupérer les règles éligibles pour un montant et un mode de paiement donnés
     *
     * @param float $cartTotal Montant du panier TTC
     * @param string $paymentModule Module de paiement (ex: "payplug:standard")
     * @param int|null $idShop ID de la boutique
     * @return array Liste des règles éligibles, triées par threshold décroissant
     */
    public static function getEligibleRules($cartTotal, $paymentModule, $idShop = null)
    {
        $allRules = self::getActiveRules($idShop);
        $eligibleRules = [];

        foreach ($allRules as $rule) {
            // Vérifier le seuil
            if ($cartTotal < (float) $rule['threshold']) {
                continue;
            }

            // Vérifier le module de paiement
            if (!self::isPaymentAllowedForRule($paymentModule, $rule['allowed_modules'])) {
                continue;
            }

            $eligibleRules[] = $rule;
        }

        return $eligibleRules;
    }

    /**
     * Trouver la meilleure règle éligible (seuil le plus élevé)
     *
     * @param float $cartTotal Montant du panier TTC
     * @param string $paymentModule Module de paiement
     * @param int|null $idShop ID de la boutique
     * @return array|false Meilleure règle ou false si aucune
     */
    public static function getBestEligibleRule($cartTotal, $paymentModule, $idShop = null)
    {
        $eligibleRules = self::getEligibleRules($cartTotal, $paymentModule, $idShop);

        if (empty($eligibleRules)) {
            return false;
        }

        // La première règle est la meilleure (déjà triée par threshold DESC)
        return $eligibleRules[0];
    }

    /**
     * Vérifier si un module de paiement est autorisé pour une règle
     *
     * @param string $paymentModule Module à vérifier (ex: "payplug:standard")
     * @param string $allowedModulesStr Modules autorisés (lignes séparées ou JSON)
     * @return bool
     */
    protected static function isPaymentAllowedForRule($paymentModule, $allowedModulesStr)
    {
        if (empty($paymentModule) || empty($allowedModulesStr)) {
            return false;
        }

        $paymentLower = strtolower($paymentModule);

        // Parser les modules autorisés
        $allowedModules = array_filter(
            array_map('trim', explode("\n", $allowedModulesStr))
        );
        $allowedModules = array_map('strtolower', $allowedModules);

        // Vérification 1 : Correspondance exacte
        if (in_array($paymentLower, $allowedModules)) {
            return true;
        }

        // Vérification 2 : Correspondance partielle (compatibilité ancienne syntaxe)
        // Si module configuré est simple (sans :) et que le paiement détecté commence par celui-ci
        foreach ($allowedModules as $allowed) {
            if (strpos($allowed, ':') === false && strpos($paymentLower, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Récupérer les modules autorisés sous forme de tableau
     *
     * @return array
     */
    public function getAllowedModulesArray()
    {
        return array_filter(
            array_map('trim', explode("\n", $this->allowed_modules))
        );
    }

    /**
     * Définir les modules autorisés depuis un tableau
     *
     * @param array $modules
     */
    public function setAllowedModulesFromArray(array $modules)
    {
        $this->allowed_modules = implode("\n", array_filter($modules));
    }

    /**
     * Récupérer la prochaine position disponible
     *
     * @param int|null $idShop
     * @return int
     */
    public static function getNextPosition($idShop = null)
    {
        if ($idShop === null) {
            $idShop = (int) Context::getContext()->shop->id;
        }

        $sql = 'SELECT MAX(`position`) FROM `' . _DB_PREFIX_ . self::$definition['table'] . '`';

        if (Shop::isFeatureActive() && $idShop) {
            $sql .= ' WHERE `id_shop` = ' . (int) $idShop;
        }

        $position = (int) Db::getInstance()->getValue($sql);

        return $position + 1;
    }

    /**
     * Supprimer une règle et réorganiser les positions
     *
     * @return bool
     */
    public function delete()
    {
        $result = parent::delete();

        if ($result) {
            // Réorganiser les positions
            self::cleanPositions($this->id_shop);
        }

        return $result;
    }

    /**
     * Nettoyer et réorganiser les positions
     *
     * @param int|null $idShop
     * @return bool
     */
    public static function cleanPositions($idShop = null)
    {
        $rules = self::getActiveRules($idShop);
        $position = 1;

        foreach ($rules as $rule) {
            Db::getInstance()->update(
                self::$definition['table'],
                ['position' => $position],
                '`id_rule` = ' . (int) $rule['id_rule']
            );
            ++$position;
        }

        return true;
    }
}
