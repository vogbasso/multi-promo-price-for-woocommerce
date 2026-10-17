# Multi Promo Price for WooCommerce

Extension WooCommerce permettant d'ajouter une promotion secondaire (promo flash) avec date d'expiration obligatoire par-dessus une promotion standard sans date de fin.

## Fonctionnalités
- Promotion flash secondaire prioritaire sur la promotion standard WooCommerce.
- Date de fin obligatoire avec expiration automatique à 23:59:59 pour les promos flash.
- **Compte à rebours en cascade (Sale Countdown Timer)** :
  - **Cas 1 (Vente Flash)** : Si la super promo flash est active avec sa date de fin, affichage du compte à rebours ciblant cette date avec le titre « Vente Flash se termine dans : ».
  - **Cas 2 (Promo standard WooCommerce)** : Si aucune vente flash n'est active mais que la promo standard WooCommerce dispose d'une date de fin (`_sale_price_dates_to`), affichage automatique du compte à rebours avec le titre « Offre promotionnelle se termine dans : » (calée à 23:59:59 si l'heure n'est pas spécifiée).
  - **Cas 3** : Masquage automatique du compte à rebours si aucune date de fin n'est programmée.
- Compatible avec les produits simples et les variations de produits (mise à jour dynamique en Vanilla JS lors du changement de variation).
- Chargement optimisé des styles et scripts uniquement sur les pages produits avec promo active.

**Auteur :** Vogel Sessien Paolo BASSO
