# Auto Discounts for WooCommerce

Système de remises automatiques basé sur l’âge des produits pour WooCommerce.

- Text domain: `auto-discounts-for-woocommerce`
- Version: 1.0.2
- Requires: WordPress 5.0+, PHP 7.2+, WooCommerce 3.0+
- Tested up to: WordPress 6.8, WooCommerce 8.0
- License: GPL-2.0-or-later

## Fonctionnalités
- Règles de remise basées sur l’ancienneté (en jours)
- Exclusions par produit et par catégorie
- Application automatique quotidienne (cron) + bouton manuel
- Prévisualisation avant application
- Compatible HPOS (High-Performance Order Storage)

## Installation
1. Cloner dans `wp-content/plugins/auto-discounts-for-woocommerce`
2. Activer le plugin dans WordPress
3. Aller dans WooCommerce > Remises auto pour configurer les règles

## Utilisation
- Ajouter des règles avec priorité, âge minimum (jours) et % de remise
- Exclure des produits depuis l’éditeur (case « Exclure des remises auto »)
- Tâche cron quotidienne: `wcad_daily_discount_update` (minuit)
- Prévisualiser l’impact d’une règle depuis la page d’admin du plugin

## Développement
- Slug/dossier: `auto-discounts-for-woocommerce`
- Text domain: `auto-discounts-for-woocommerce`
- Traductions: chargées automatiquement via WordPress.org (si publié)

## Contribuer
Les contributions sont bienvenues via issues et pull requests.

## Changelog
### 1.0.2
- Conformité i18n et sécurité
- Déclaration compat HPOS
- Optimisations mineures

## Licence
GPL-2.0-or-later


