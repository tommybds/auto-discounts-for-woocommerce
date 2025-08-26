=== Auto Discounts for WooCommerce ===
Contributors: tommybordas
Tags: woocommerce, discounts, automatic, pricing, age-based
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Système de remises automatiques basé sur l'âge des produits pour WooCommerce.

== Description ==

Auto Discounts for WooCommerce permet d'appliquer automatiquement des remises sur vos produits en fonction de leur ancienneté dans votre catalogue.

Caractéristiques principales :
* Définissez des règles de remise basées sur l'âge des produits
* Configurez différents pourcentages de remise pour différentes périodes
* Excluez certaines catégories de produits
* Application automatique quotidienne ou manuelle à la demande
* Optimisé pour les performances avec traitement par lots

Ce plugin est idéal pour les boutiques qui souhaitent automatiser leurs stratégies de remise pour les produits plus anciens.

== Installation ==

1. Téléchargez le plugin et décompressez-le
2. Uploadez le dossier 'auto-discounts-for-woocommerce' dans le répertoire '/wp-content/plugins/'
3. Activez le plugin via le menu 'Extensions' dans WordPress
4. Accédez à WooCommerce > Remises auto pour configurer vos règles

== Frequently Asked Questions ==

= Comment fonctionne le calcul de l'âge des produits ? =

L'âge d'un produit est calculé à partir de sa date de création dans WordPress.

= Les remises s'appliquent-elles aux produits en rupture de stock ? =

Non, les remises sont uniquement appliquées aux produits en stock pour optimiser les performances.

= Puis-je exclure certaines catégories de produits ? =

Oui, vous pouvez sélectionner les catégories à exclure dans les paramètres du plugin.

== Screenshots ==

1. Interface d'administration pour configurer les règles de remise
2. Exemple de produits avec remises appliquées

== Changelog ==

= 1.0.3 =
* Sécurité: ajout et vérification de nonces (sauvegardes, AJAX, filtres)
* Sécurité: assainissement des entrées (`$_GET`, `$_POST`) et échappement des sorties
* Perf/Qualité: cache objet pour requêtes d’admin; annotations PHPCS
* Compatibilité: WooCommerce testé jusqu’à 10.1.1; HPOS déclaré
* Divers: Text domain unifié; readme et en-têtes conformes

= 1.0.0 =
* Version initiale

== Upgrade Notice ==

= 1.0.3 =
Mise à jour recommandée: corrections de sécurité (nonces/escapes), compat WooCommerce 10.1.1, et optimisations.

= 1.0.0 =
Version initiale du plugin. 