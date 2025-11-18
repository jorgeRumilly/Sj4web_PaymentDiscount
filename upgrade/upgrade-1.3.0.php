<?php
/**
 * Script de migration vers la version 1.3.0
 *
 * Ajout des colonnes message_before et message_after pour personnaliser
 * les messages d'affichage par palier
 *
 * @author SJ4WEB.FR
 * @version 1.3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fonction exécutée lors de l'upgrade vers la version 1.3.0
 *
 * @param Module $module Instance du module
 * @return bool True si succès, false sinon
 */
function upgrade_module_1_3_0($module)
{
    $tableName = _DB_PREFIX_ . 'sj4web_payment_discount_rule';

    // Vérifier si les colonnes existent déjà
    $columns = Db::getInstance()->executeS("SHOW COLUMNS FROM `{$tableName}`");
    $columnNames = array_column($columns, 'Field');

    $sql = [];

    // Ajouter message_before si elle n'existe pas
    if (!in_array('message_before', $columnNames)) {
        $sql[] = "ALTER TABLE `{$tableName}`
                  ADD `message_before` TEXT NULL
                  COMMENT 'Message avant atteinte du palier (optionnel, avec tokens)'
                  AFTER `allowed_modules`";
    }

    // Ajouter message_after si elle n'existe pas
    if (!in_array('message_after', $columnNames)) {
        $sql[] = "ALTER TABLE `{$tableName}`
                  ADD `message_after` TEXT NULL
                  COMMENT 'Message après atteinte du palier (optionnel, avec tokens)'
                  AFTER `message_before`";
    }

    // Exécuter les requêtes
    foreach ($sql as $query) {
        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'Module sj4web_paymentdiscount: Erreur lors de l\'upgrade 1.3.0 - ' . Db::getInstance()->getMsgError(),
                3, // Severity: error
                null,
                'Module',
                $module->id
            );
            return false;
        }
    }

    // Log de migration réussie
    if (!empty($sql)) {
        PrestaShopLogger::addLog(
            'Module sj4web_paymentdiscount: Migration vers v1.3.0 effectuée - Colonnes message_before et message_after ajoutées',
            1, // Severity: informative
            null,
            'Module',
            $module->id
        );
    } else {
        PrestaShopLogger::addLog(
            'Module sj4web_paymentdiscount: Migration vers v1.3.0 - Colonnes déjà présentes, aucune modification nécessaire',
            1, // Severity: informative
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
