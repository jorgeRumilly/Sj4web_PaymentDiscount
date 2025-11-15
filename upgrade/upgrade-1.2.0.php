<?php
/**
 * Upgrade SQL 1.2.0 - Migration vers le système de paliers multiples
 *
 * Migre l'ancienne configuration (1 BR fixe) vers la nouvelle table
 *
 * @author SJ4WEB.FR
 * @version 1.2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration de la configuration actuelle vers la table des règles
 *
 * @param Sj4web_PaymentDiscount $module
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    // 1. Créer la table si elle n'existe pas
    $sqlFile = dirname(__FILE__) . '/../sql/install.php';
    if (file_exists($sqlFile)) {
        include_once $sqlFile;
    }

    // 2. Vérifier si une migration est nécessaire
    $existingRules = Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'sj4web_payment_discount_rule`'
    );

    // Si des règles existent déjà, ne pas migrer
    if ($existingRules > 0) {
        return true;
    }

    // 3. Récupérer l'ancienne configuration
    $oldVoucherCode = Configuration::get($module->name . '_VOUCHER_CODE');
    $oldThreshold = Configuration::get($module->name . '_THRESHOLD');
    $oldAllowedModules = Configuration::get($module->name . '_ALLOWED_MODULES');

    // Si pas de configuration, ne rien faire
    if (empty($oldVoucherCode) || empty($oldThreshold)) {
        return true;
    }

    // 4. Créer une règle avec l'ancienne configuration
    $idShop = Shop::isFeatureActive() ? (int) Context::getContext()->shop->id : null;

    $now = date('Y-m-d H:i:s');

    $result = Db::getInstance()->insert('sj4web_payment_discount_rule', [
        'id_shop' => $idShop,
        'name' => 'Règle migrée (v1.1.x)',
        'voucher_code' => pSQL($oldVoucherCode),
        'threshold' => (float) $oldThreshold,
        'allowed_modules' => pSQL($oldAllowedModules),
        'position' => 1,
        'active' => 1,
        'date_add' => $now,
        'date_upd' => $now,
    ]);

    if (!$result) {
        return false;
    }

    // 5. Marquer la migration comme effectuée
    Configuration::updateValue($module->name . '_MIGRATED_TO_1_2_0', 1);

    return true;
}
