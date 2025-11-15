<?php
/**
 * Installation SQL - Table des règles de réduction
 *
 * @author SJ4WEB.FR
 * @version 1.2.0
 */

$sql = [];

// Table des règles de paliers de réduction
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'sj4web_payment_discount_rule` (
    `id_rule` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `voucher_code` VARCHAR(32) NOT NULL,
    `threshold` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `allowed_modules` TEXT NOT NULL,
    `position` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_rule`),
    KEY `active` (`active`),
    KEY `threshold` (`threshold`),
    KEY `id_shop` (`id_shop`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) == false) {
        return false;
    }
}
