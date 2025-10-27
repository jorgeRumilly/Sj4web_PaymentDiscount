# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Vue d'ensemble du projet

Module PrestaShop qui applique/retire automatiquement une réduction selon le total du panier et le mode de paiement choisi. Le module supporte deux modes de fonctionnement : optimal (avec override Cart.php) et dégradé (sans override).

**Version actuelle** : 1.1.1
**Compatibilité** : PrestaShop 1.7.6+ et 8.x, PHP 7.4+

**Changelog 1.1.1** :
- Ajout du système de mapping automatique des noms de paiement (`mapPaymentNameToType()`)
- Amélioration de la détection du contexte `validateOrder()` avec backtrace profondeur 40
- Récupération du paramètre `$payment_method` depuis le backtrace de `validateOrder()`
- Système de logging à deux niveaux (debug + logs critiques)
- Correction du positionnement du hook `actionSjPayDisCartGetCartRules` (après parent::getCartRules())
- Correction de bugs dans `getCurrentPaymentModule()` (inversion de logique)
- Correspondance stricte pour la syntaxe granulaire (ex: `payplug:standard` refuse `payplug:applepay`)

## Architecture technique

### Fichiers principaux

- `sj4web_paymentdiscount.php` : Classe principale du module avec toute la logique métier
- `controllers/front/toggle.php` : Contrôleur AJAX pour la synchronisation temps réel des bons de réduction
- `views/js/paymentdiscount.js` : Logique frontend avec détection automatique des variantes de paiement
- `views/js/fallback.js` : Mode dégradé si l'override n'est pas installé
- `views/templates/admin/configure.tpl` : Interface de configuration du backoffice

### Système d'override intelligent

Le module installe automatiquement un override de `Cart.php` selon 3 cas :
1. **Pas d'override existant** : Crée un override complet avec méthodes `getOrderTotal()` et `getCartRules()`
2. **Override existe sans getOrderTotal** : Ajoute la méthode complète
3. **Override existe avec getOrderTotal** : Injecte le hook dans la méthode existante

L'override permet d'intercepter les calculs de totaux et les règles de panier via deux hooks personnalisés :

**Dans `getOrderTotal()`** :
```php
$total = $value;
Hook::exec('actionSjPayDisCartGetOrderTotal', [
    'cart' => $this,
    'total' => &$total,  // Passage par référence !
    'with_taxes' => $withTaxes,
    'type' => $type,
    ...
]);
return $total;  // Retourne le total modifié si besoin
```

**Dans `getCartRules()`** :
```php
$cart_rules = parent::getCartRules($filter, $autoAdd, $useOrderPrices);
Hook::exec('actionCartGetCartRules', [...]);  // Hook PrestaShop natif

// ⚠️ CRITIQUE : Notre hook est appelé ICI, juste avant le return
Hook::exec('actionSjPayDisCartGetCartRules', [
    'object' => &$this,
    'cart' => &$this,
    ...
]);

return $cart_rules;  // Retourne les règles (potentiellement modifiées par le hook)
```

Si l'installation d'override échoue, le module fonctionne en **mode dégradé** avec correction au niveau des hooks de paiement.

### Hooks utilisés

- `actionCartSave` : Synchronisation lors de modifications du panier
- `displayHeader` : Injection JavaScript et synchronisation hors checkout
- `actionCarrierProcess` : Synchronisation lors de changement de transporteur
- `actionSjPayDisCartGetOrderTotal` : Hook personnalisé pour recalculs précis (appelé par l'override)
- `actionSjPayDisCartGetCartRules` : Hook personnalisé appelé avant le return de Cart::getCartRules() pendant validateOrder()
- `displayPaymentTop` : Fallback pour mode dégradé

### Détection des variantes de paiement (v1.1.0)

Le module supporte la syntaxe granulaire `module:subtype` pour un contrôle précis des modes de paiement autorisés.

**JavaScript** : Détection automatique multi-niveau
1. Analyse des formulaires cachés (`method`, `payplugOney_type`, etc.)
2. Déduction par analyse du label de paiement via mapping de mots-clés
3. Fallback automatique vers le type standard

**Serveur** : Le module utilise un système de mapping et de correspondance en deux étapes :

1. **Mapping automatique** via `mapPaymentNameToType()` :
   - Convertit les noms de paiement affichés vers les types techniques
   - Sources : paramètre `$payment_method` de `validateOrder()`, cookie, paramètres URL
   - Exemples de mapping :
     - `"Payplug"` → `"payplug:standard"`
     - `"Apple Pay"` → `"payplug:applepay"`
     - `"Oney 3x sans frais"` → `"payplug:oney_x3_without_fees"`
     - `"Oney 4x sans frais"` → `"payplug:oney_x4_without_fees"`
     - `"Bancontact"` → `"payplug:bancontact"`

2. **Vérification** via `isPaymentAllowed()` :
   - Correspondance exacte prioritaire (ex: `"payplug:standard"` = `"payplug:standard"`)
   - Correspondance partielle contrôlée : si config simple (sans `:`) accepte les variantes (ex: config `"payplug"` accepte `"payplug:standard"`)
   - **MAIS** : si config granulaire (avec `:`) refuse les autres variantes (ex: config `"payplug:standard"` refuse `"payplug:applepay"`)

### Flux de détection pendant validateOrder()

Le hook `actionSjPayDisCartGetCartRules` récupère le nom du paiement via **backtrace** :
1. Détecte `validateOrder()` dans la stack (profondeur 40 niveaux)
2. Récupère le paramètre `$payment_method` (4ème argument de validateOrder)
3. Mappe le nom vers le type configuré
4. Vérifie si autorisé
5. Retire le BR si paiement non autorisé

## Configuration

### Paramètres configurables (via interface admin)

- `VOUCHER_CODE` : Code du bon de réduction PrestaShop (défaut : `PAY5`)
- `THRESHOLD` : Seuil minimum du panier en € (défaut : `500.00`)
- `ALLOWED_MODULES` : Modules de paiement autorisés, un par ligne (défaut : `payplug` et `ps_wirepayment`)
- `DEBUG_MODE` : Mode debug pour logs détaillés (défaut : désactivé)
- `OVERRIDE_MODE` : Indicateur d'installation de l'override (géré automatiquement)
- `DEGRADED_MODE` : Indicateur de mode dégradé (géré automatiquement)

### Format des modules autorisés

**Format simple (v1.0.0 - compatibilité)**
```
payplug
ps_wirepayment
paypal
```

**Format granulaire (v1.1.0 - recommandé)**
```
payplug:standard
payplug:applepay
payplug:oney_x3_without_fees
ps_wirepayment
```

## Développement

### Logs et debug

**Activer le mode debug** : Via l'interface de configuration du module

**Fichiers de logs** :
- PrestaShop : Interface > Paramètres avancés > Logs (filtre "Module" ou "Cart")
- Fichier dédié : `/var/logs/payment_discount_module.log` (généré par le module principal)
- Fichier contrôleur : `/var/logs/payment_discount_debug.log` (généré par toggle.php)

**Système de logging à deux niveaux** :
- `fileLog($message, $data)` : Logs de debug (seulement si mode debug activé) - utilisé pour le diagnostic détaillé
- `fileLogAlways($message, $data)` : Logs critiques (toujours affichés) - erreurs, warnings, actions importantes
- `debugLog($message, $level, $object, $objectId)` : Logs PrestaShop natifs (seulement si debug activé)

**Logs critiques toujours affichés** (même sans mode debug) :
- Suppression d'un CartRule car paiement non autorisé
- Échec de suppression d'un CartRule
- Annulation d'une réduction dans le total
- Module de paiement non détecté dans validateOrder

### Variables JavaScript dynamiques

Les variables JS sont injectées dynamiquement via `Media::addJsDef()` dans `hookDisplayHeader()` :
- `sj4web_paymentdiscount_toggle` : URL du contrôleur AJAX
- `sj4web_paymentdiscount_debug` : État du mode debug
- `sj4web_paymentdiscount_allowed_modules` : Liste des modules autorisés
- `sj4web_paymentdiscount_voucher_code` : Code du bon de réduction

### Méthodes principales à connaître

**Dans `sj4web_paymentdiscount.php`** :
- `installOverrideIntelligent()` : Installation intelligente de l'override selon 3 cas
- `hookActionSjPayDisCartGetOrderTotal()` : Recalcul des totaux selon le contexte de paiement (appelé par l'override de Cart::getOrderTotal())
- `hookActionSjPayDisCartGetCartRules()` : Retire le BR si paiement non autorisé pendant validateOrder() - utilise le backtrace pour détecter validateOrder() et récupérer le paramètre $payment_method
- `mapPaymentNameToType()` : Convertit les noms de paiement en types configurés ("Payplug" → "payplug:standard", "Oney 3x" → "payplug:oney_x3_without_fees", etc.)
- `syncVoucher()` : Synchronisation du bon selon seuil et contexte
- `isPaymentAllowed()` : Vérification si un module de paiement est autorisé (correspondance exacte prioritaire + correspondance partielle contrôlée)
- `getCurrentPaymentModule()` : Détection du module de paiement avec priorités multiples (cookie > module > payment-option > fc)
- `isInPaymentContext()` : Détection si on est dans un contexte de paiement valide
- `isSystemModule()` : Filtre les modules système (ps_shoppingcart, blockcart, etc.)

**Dans `toggle.php`** :
- `postProcess()` : Traitement AJAX pour ajout/retrait du bon avec logs détaillés
- `isPaymentAllowed()` : Validation côté serveur des modules autorisés

**Dans `paymentdiscount.js`** :
- `getKey()` : Détection du module de paiement avec support des variantes
- `getPayplugVariant()` : Détection automatique des variantes PayPlug
- `deducePaymentVariantFromLabel()` : Mapping mots-clés vers variantes
- `sync()` : Appel AJAX de synchronisation avec protection contre les doublons

### Extensibilité

Les méthodes sont `protected` pour permettre l'override de la classe module si nécessaire.

Pour ajouter un nouveau système de détection de variantes (ex: Stripe) :
1. Créer une fonction `getStripeVariant()` similaire à `getPayplugVariant()`
2. L'appeler dans `getKey()` selon le `baseKey`
3. Étendre le mapping de mots-clés dans `deducePaymentVariantFromLabel()`

### Prévention des erreurs de timing

Le module bloque temporairement les boutons de paiement pendant la synchronisation AJAX pour éviter l'envoi de montants incorrects aux plateformes de paiement (problème courant avec PayPal).

Variables globales JS :
- `inflight` : Indique qu'une requête AJAX est en cours
- `preventSubmit` : Bloque les soumissions de formulaire

### Internationalisation

Traductions dans `translations/{locale}/ModulesSj4webPaymentdiscountAdmin.{locale}.xlf`
Domaine : `Modules.Sj4webPaymentdiscount.Admin`

Locales supportées : `fr-FR`, `en-US`

## Résolution de problèmes courants

**Le bon ne s'applique pas** :
- Vérifier que le bon existe dans PrestaShop avec le bon code
- Contrôler la syntaxe des modules autorisés (logs debug)
- Vérifier le seuil minimum

**Mode dégradé activé** :
- Vérifier les permissions d'écriture sur `/override/classes/`
- Consulter les warnings lors de l'installation

**Erreurs de timing avec paiements rapides** :
- Vérifier que `paymentdiscount.js` est bien chargé
- Consulter la console JS pour les erreurs

**Le bon n'est pas retiré pour un paiement non autorisé** :
- Vérifier que le hook `actionSjPayDisCartGetCartRules` est bien enregistré
- Consulter les logs dans `/var/logs/payment_discount_module.log`
- S'assurer que le cookie `payment_module` est bien défini

**Différence entre montant PayPlug et montant commande** :
- **Symptôme** : PayPlug reçoit 1029.80€ mais la commande est créée à 1084€ (ou vice-versa)
- **Cause** : Le mapping n'est pas appliqué avant `isPaymentAllowed()` dans un des hooks
- **Solution** : Vérifier que `mapPaymentNameToType()` est appelé dans `hookActionSjPayDisCartGetOrderTotal` ET `hookActionSjPayDisCartGetCartRules`
- **Vérification** : Dans les logs debug, chercher "Module de paiement mappé" pour vérifier le mapping

**Oney/Apple Pay accepté alors que seul PayPlug standard est configuré** :
- **Cause** : Correspondance partielle trop permissive dans `isPaymentAllowed()`
- **Solution** : S'assurer que la config utilise la syntaxe granulaire `payplug:standard` (avec le `:`)
- La syntaxe `payplug` seule accepte toutes les variantes (compatibilité v1.0.0)

## Notes importantes

### Fonctionnement du système de mapping

- **Le mapping est ESSENTIEL** : Toujours mapper le nom du module avant `isPaymentAllowed()`
- `hookActionSjPayDisCartGetCartRules` récupère `$payment_method` via backtrace de `validateOrder()`
- `hookActionSjPayDisCartGetOrderTotal` récupère le module via `getCurrentPaymentModule()` puis le mappe
- Sans mapping, `"payplug"` ne matcherait pas `"payplug:standard"` → BR annulé à tort !

### Positionnement des hooks dans Cart.php

- `actionSjPayDisCartGetCartRules` : appelé **APRÈS** `parent::getCartRules()` et **AVANT** le `return`
- `actionSjPayDisCartGetOrderTotal` : appelé **APRÈS** le calcul du total et **AVANT** le `return`
- Cette position est critique pour intercepter et modifier les valeurs avant qu'elles soient retournées

### Sécurité et cohérence

- Le module utilise un cookie `payment_module` pour stocker le choix de paiement (synchronisation JS/serveur)
- Les calculs de totaux sont interceptés uniquement en contexte de paiement pour éviter les faux positifs
- Le hook `actionSjPayDisCartGetCartRules` ne s'active QUE pendant `validateOrder()` (vérification via backtrace profondeur 40)
- La détection de variantes PayPlug est extensible et future-proof (v1.1.0)
- **Correspondance stricte** : Si config `payplug:standard`, seul `payplug:standard` est accepté (pas `payplug:applepay` ni `payplug:oney`)
