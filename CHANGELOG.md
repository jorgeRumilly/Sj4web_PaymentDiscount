# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2025-08-31

### Ajouté

#### Configuration granulaire des modules de paiement
- **Système de sous-types** : Support de la syntaxe `module:subtype` pour un contrôle précis
- **Détection automatique des variantes PayPlug** :
  - PayPlug CB standard (`payplug:standard`)
  - PayPlug Apple Pay (`payplug:applepay`)
  - PayPlug Oney 3x (`payplug:oney_x3_without_fees`)
  - PayPlug Oney 4x (`payplug:oney_x4_without_fees`)
- **Détection intelligente multi-niveau** :
  1. Analyse des formulaires cachés (`method`, `payplugOney_type`, etc.)
  2. Déduction par analyse du label de paiement
  3. Fallback automatique vers le type standard
- **Extensibilité future** : Architecture prête pour nouveaux types PayPlug et autres modules

#### Interface d'administration améliorée
- Documentation intégrée avec exemples concrets pour PayPlug
- Zone de configuration élargie (8 lignes) pour meilleure lisibilité
- Instructions détaillées pour syntaxe `module:subtype`
- Exemples pratiques : CB, Apple Pay, Oney 3x/4x

#### JavaScript évolutif et future-proof
- **Détection automatique** : Plus de code en dur pour les types de paiement
- **Mapping configurable** des mots-clés vers variantes de paiement
- **Construction dynamique** des identifiants selon les données formulaire
- **Logs debug enrichis** avec détails de détection pour chaque variante

#### Compatibilité rétroactive
- Support de l'ancienne syntaxe (ex: `payplug` seul)
- Migration transparente vers le nouveau format
- Correspondance exacte prioritaire, puis recherche partielle

### Modifié

#### Logique de détection des paiements
- Fonction `getKey()` complètement refactorisée
- Nouvelle fonction `getPayplugVariant()` dédiée
- Fonction `deducePaymentVariantFromLabel()` extensible
- Logique serveur `isPaymentAllowed()` adaptée au nouveau format

#### Documentation
- Interface d'aide enrichie avec exemples PayPlug
- Placeholder mis à jour avec nouveaux formats
- Messages d'aide détaillés pour chaque section

### Technique

#### Nouveaux mappings de détection
```javascript
// Exemples automatiquement détectés :
'apple pay' → 'applepay'
'3x sans frais' → 'oney_x3_without_fees' 
'4x sans frais' → 'oney_x4_without_fees'
'google pay' → 'googlepay' (prêt pour l'avenir)
```

#### Structure évolutive
- Fonction `getPayplugVariant()` séparée et extensible
- Support prêt pour `getStripeVariant()`, `getPaypalVariant()` si besoin
- Mapping des mots-clés facilement extensible pour nouveaux types

### Configuration exemple mise à jour
```
payplug:standard
payplug:applepay
ps_wirepayment
stripe_official
paypal
```

### Notes de version
Cette version 1.1.0 apporte une **flexibilité maximale** pour la gestion des variantes de modules de paiement. L'architecture est maintenant **100% future-proof** et s'adapte automatiquement aux nouveaux types PayPlug sans modification de code.

La détection multi-niveau (formulaire → label → fallback) garantit une robustesse maximale face aux évolutions des modules de paiement tiers.

## [1.0.0] - 2025-08-28

### Ajouté

#### Fonctionnalités principales
- Application automatique d'une réduction selon le total du panier et le mode de paiement
- Configuration flexible via interface d'administration
- Support de multiples modules de paiement (PayPlug, virement bancaire, PayPal, etc.)
- Seuil minimum configurable pour l'application de la réduction
- Code de bon de réduction personnalisable

#### Installation intelligente
- Détection et installation automatique d'override Cart.php selon 3 cas :
    - Création complète si aucun override existant
    - Ajout de méthode à un override existant sans `getOrderTotal`
    - Injection de hook dans une méthode `getOrderTotal` existante
- Mode dégradé automatique si installation d'override impossible
- Nettoyage automatique lors de la désinstallation

#### Interface d'administration
- Formulaire de configuration avec template Smarty séparé
- Validation côté client et serveur
- Affichage du statut du module (optimal/dégradé)
- Liste des modules de paiement installés pour aide à la configuration
- Messages d'erreur et de confirmation contextués

#### Internationalisation
- Support complet multi-langues avec fichiers .xlf
- Domaines de traduction PrestaShop standards (`Modules.Sj4webPaymentdiscount.Admin`)
- Fichiers de traduction pour français et anglais
- Templates utilisent la syntaxe `{l s='...' d='...'}`
- Messages JavaScript traduits via variables PHP

#### Prévention des erreurs de timing
- Blocage automatique des boutons de paiement pendant synchronisation AJAX
- Protection contre les soumissions rapides (cas PayPal)
- Gestion de queue pour éviter les requêtes simultanées
- Validation finale avant envoi aux plateformes de paiement

#### Système de debug
- Mode debug configurable via interface
- Logs détaillés dans PrestaShop avec niveaux de gravité
- Traçage des détections de modules de paiement
- Logs de calculs et modifications de panier
- Debug JavaScript centralisé et conditionnel

#### Contexte de paiement intelligent
- Détection précise du contexte de paiement pour éviter les faux positifs
- Filtrage des modules système (ps_shoppingcart, blockcart, etc.)
- Hook personnalisé `actionSjPayDisCartGetOrderTotal` pour recalculs précis
- Priorités multiples pour détection du module de paiement (paramètre, cookie, etc.)

#### Architecture extensible
- Méthodes `protected` au lieu de `private` pour permettre override de la classe module
- Structure de fichiers respectant les standards PrestaShop
- Variables JavaScript dynamiques depuis configuration PHP
- Getters configurables remplaçant les constantes

### Technique

#### Structure des fichiers
- Module principal : `sj4web_paymentdiscount.php`
- Template d'administration : `views/templates/admin/configure.tpl`
- JavaScript frontend : `views/js/paymentdiscount.js`
- JavaScript mode dégradé : `views/js/fallback.js`
- Contrôleur AJAX : `controllers/front/toggle.php`
- Traductions : `translations/{locale}/ModulesSj4webPaymentdiscountAdmin.{locale}.xlf`
- Override automatique : `override/classes/Cart.php`

#### Hooks utilisés
- `actionCartSave` : Synchronisation lors de modifications du panier
- `displayHeader` : Injection JavaScript et synchronisation hors checkout
- `actionValidateOrder` : Validation finale avant création de commande
- `actionCarrierProcess` : Synchronisation lors de changement de transporteur
- `displayPaymentTop` : Fallback pour mode dégradé
- `actionSjPayDisCartGetOrderTotal` : Hook personnalisé pour recalculs précis

#### Sécurité
- Validation et échappement de tous les paramètres utilisateur
- Protection contre injections SQL avec requêtes préparées
- Fichiers `index.php` de protection dans tous les répertoires
- Tokens AJAX et validation des origines
- Mode développement pour affichage conditionnel d'informations sensibles

#### Compatibilité
- PrestaShop 1.7.6+ et 8.x
- PHP 7.4+
- Thèmes Classic et compatibles
- Modules de paiement standards (PayPlug, ps_wirepayment, PayPal, Stripe)

### Configuration par défaut
- Code bon de réduction : `PAY5`
- Seuil minimum : `500.00` €
- Modules autorisés : `payplug`, `ps_wirepayment`
- Mode debug : désactivé

### Notes de version
Cette version 1.0.0 établit une base solide pour la gestion automatique des réductions selon le mode de paiement. L'architecture modulaire et les méthodes protected permettent une extension facile pour des besoins spécifiques.

Le système d'installation intelligente de l'override garantit une compatibilité maximale avec les configurations existantes tout en offrant un mode de fonctionnement dégradé de secours.

La prévention des erreurs de timing résout les problèmes courants avec les paiements rapides comme PayPal, assurant que les montants corrects sont toujours transmis aux plateformes de paiement.
