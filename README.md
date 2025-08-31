# SJ4WEB Payment Discount Sync

Module PrestaShop qui applique ou retire automatiquement une réduction selon le total du panier et le mode de paiement choisi.

## Fonctionnalités

- **Réduction automatique** : Application/suppression d'un bon de réduction selon un seuil configurable
- **Modes de paiement sélectifs** : Réduction disponible uniquement pour certains moyens de paiement
- **Configuration flexible** : Interface d'administration pour paramétrer le module
- **Double mode de fonctionnement** :
    - Mode optimal avec override Cart.php
    - Mode dégradé sans override
- **Internationalisation complète** : Support multi-langues avec fichiers .xlf
- **Prévention des erreurs de timing** : Protection contre les soumissions rapides

## Prérequis

- PrestaShop 1.7.6+ ou 8.x
- PHP 7.4+
- Module de paiement compatible (PayPlug, virement bancaire, PayPal, etc.)

## Installation

1. **Télécharger le module** dans `modules/sj4web_paymentdiscount/`
2. **Créer le bon de réduction** dans `Promotion > Règles panier`
    - Code : configurable via l'interface (défaut : `PAY5`)
    - Type : montant fixe ou pourcentage selon besoins
    - Statut : Actif
3. **Installer le module** depuis l'interface PrestaShop
4. **Configurer** via `Modules > Modules installés > Configurer`

## Configuration

### Interface d'administration

| Paramètre | Description | Défaut |
|-----------|-------------|---------|
| Code du bon | Code du bon de réduction PrestaShop | `PAY5` |
| Seuil minimum | Montant minimum du panier (€) | `500.00` |
| Modules autorisés | Modules de paiement (un par ligne) | `payplug`<br>`ps_wirepayment` |
| Mode debug | Logs détaillés dans PrestaShop | Désactivé |

### Exemples de modules de paiement

#### Format simple (v1.0.0 - compatibilité)
```
payplug
ps_wirepayment
paypal
stripe_official
ps_checkpayment
```

#### Format granulaire (v1.1.0 - recommandé)
```
payplug:standard
payplug:applepay
ps_wirepayment
paypal
stripe_official
```

#### Exemples PayPlug détaillés
```
payplug:standard              # CB classique uniquement
payplug:applepay              # Apple Pay uniquement  
payplug:oney_x3_without_fees  # Oney 3x uniquement
payplug:oney_x4_without_fees  # Oney 4x uniquement
```

## Structure des fichiers

```
sj4web_paymentdiscount/
├── sj4web_paymentdiscount.php     # Module principal
├── views/templates/admin/
│   └── configure.tpl              # Interface de configuration
├── views/js/
│   ├── paymentdiscount.js         # Logique frontend
│   └── fallback.js                # Mode dégradé
├── controllers/front/
│   └── toggle.php                 # API AJAX
├── translations/
│   ├── fr-FR/
│   │   └── ModulesSj4webPaymentdiscountAdmin.fr-FR.xlf
│   └── en-US/
│       └── ModulesSj4webPaymentdiscountAdmin.en-US.xlf
└── override/classes/
    └── Cart.php                   # Généré automatiquement si possible
```

## Fonctionnement technique

### Mode optimal

- Installation automatique d'un override `Cart.php`
- Recalcul des totaux en temps réel selon le contexte de paiement
- Précision maximale sur tous les affichages

### Mode dégradé

- Fonctionne sans override
- Correction au niveau des hooks de paiement
- Léger décalage possible sur certains affichages

### Prévention des erreurs de timing

Le module bloque temporairement les boutons de paiement pendant la synchronisation pour éviter l'envoi de montants incorrects aux plateformes de paiement.

## Logs et débogage

Activer le mode debug pour obtenir des logs détaillés dans l'interface PrestaShop :

- Détection des modules de paiement
- Application/suppression des réductions
- Calculs de totaux
- Erreurs éventuelles

## Compatibilité

### PrestaShop
- 1.7.6 à 1.7.8
- 8.0 à 8.1

### Modules de paiement testés
- PayPlug
- Virement bancaire (ps_wirepayment)
- PayPal (module officiel)
- Stripe

### Thèmes
- Classic
- Warehouse
- Autres thèmes compatibles PrestaShop 1.7+

## Personnalisation

### Extension du module

Les méthodes principales sont `protected` pour permettre l'override :

```php
class MyCustomPaymentDiscount extends Sj4web_PaymentDiscount 
{
    protected function isPaymentAllowed(string $paymentModule): bool
    {
        // Logique personnalisée
        return parent::isPaymentAllowed($paymentModule);
    }
}
```

### Ajout de langues

1. Créer le répertoire `translations/xx-XX/`
2. Copier et traduire le fichier `.xlf`
3. Vider le cache PrestaShop

## Support

### Problèmes courants

**Le bon ne s'applique pas**
- Vérifier que le bon existe dans PrestaShop
- Contrôler la configuration des modules autorisés
- Activer le mode debug

**Erreurs de timing avec PayPal**
- S'assurer que le JavaScript est bien chargé
- Vérifier la console pour les erreurs JS

**Override non installé**
- Le module fonctionne en mode dégradé
- Vérifier les permissions d'écriture sur `/override/classes/`

### Logs

Les logs sont visibles dans :
- Interface PrestaShop > Paramètres avancés > Logs
- Filtre sur "Module" ou "Cart"

## Informations techniques

- **Version** : 1.1.0
- **Auteur** : SJ4WEB.FR
- **Licence** : Propriétaire
- **Compatibilité** : PrestaShop 1.7.6+ / 8.x
- **Langues** : Français, Anglais (extensible)

## Sécurité

- Validation côté serveur de tous les paramètres
- Protection contre les injections SQL
- Échappement des données d'affichage
- Fichiers `index.php` de protection dans tous les répertoires
