<?php
/**
 * Script de migration vers la version 1.4.0
 * Ajout du seuil de déclenchement (trigger_threshold) et du message intermédiaire (message_between)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0($module)
{
    $tableName = _DB_PREFIX_ . 'sj4web_payment_discount_rule';

    // Vérifier si les colonnes existent déjà
    $columns = Db::getInstance()->executeS("SHOW COLUMNS FROM `{$tableName}`");
    $columnNames = array_column($columns, 'Field');

    $sql = [];

    // Ajouter trigger_threshold si elle n'existe pas
    if (!in_array('trigger_threshold', $columnNames)) {
        $sql[] = "ALTER TABLE `{$tableName}`
                  ADD `trigger_threshold` DECIMAL(20,6) NULL DEFAULT NULL
                  COMMENT 'Seuil de déclenchement pour affichage message (optionnel, par défaut = threshold)'
                  AFTER `threshold`";
    }

    // Ajouter message_between si elle n'existe pas
    if (!in_array('message_between', $columnNames)) {
        $sql[] = "ALTER TABLE `{$tableName}`
                  ADD `message_between` TEXT NULL
                  COMMENT 'Message intermédiaire entre palier actuel et suivant (optionnel, avec tokens)'
                  AFTER `message_after`";
    }

    // Exécuter les requêtes
    foreach ($sql as $query) {
        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'Module sj4web_paymentdiscount: Erreur lors de l\'upgrade 1.4.0 - ' . Db::getInstance()->getMsgError(),
                3,
                null,
                'Module',
                $module->id
            );
            return false;
        }
    }

    if (!empty($sql)) {
        PrestaShopLogger::addLog(
            'Module sj4web_paymentdiscount: Migration vers v1.4.0 effectuée - Colonnes trigger_threshold et message_between ajoutées',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
