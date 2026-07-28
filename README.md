# SDX AutoStock

Module PrestaShop qui désactive les produits dont le stock agrégé atteint un seuil configurable, puis peut les réactiver lorsque le stock revient.

## Compatibilité

- PrestaShop 8.x, 9.0 et 9.1
- PHP 7.2.5 minimum pour PrestaShop 8 ; PHP 8.1 à 8.5 selon la version de PrestaShop 9 utilisée
- MySQL ou MariaDB pris en charge par la version de PrestaShop installée

## Installation

Le nom technique PrestaShop est `sdxautostock`. Le dossier installé doit donc obligatoirement s'appeler `sdxautostock` et contenir directement `sdxautostock.php`.

1. Renommer le dossier du dépôt en `sdxautostock`, ou créer un ZIP dont le dossier racine porte ce nom.
2. Copier le dossier dans `modules/` ou importer le ZIP depuis le gestionnaire de modules.
3. Installer puis ouvrir la page de configuration du module.

> La version 1.0 utilisait le nom invalide `sdx-autostock`. PrestaShop ne peut pas la mettre à niveau comme un module installé normal. Désinstallez cette ancienne copie si elle apparaît, supprimez son ancien dossier, puis installez `sdxautostock`.

## Fonctionnement

- Le stock lu est la ligne agrégée `id_product_attribute = 0`, qui inclut toutes les déclinaisons.
- L'activation est modifiée dans `product_shop`, séparément pour chaque boutique.
- Le partage de stock d'un groupe multiboutique est respecté via l'API `StockAvailable` de PrestaShop.
- Une table dédiée mémorise uniquement les produits désactivés par le module. Un produit désactivé manuellement n'est donc jamais réactivé automatiquement.
- Une modification manuelle du produit retire ce produit du suivi automatique et donne la priorité au choix du marchand.
- Les mises à jour sont groupées et exécutées dans une transaction, sans charger tous les objets `Product` en mémoire.

## Planification

La page de configuration affiche une URL de cron sécurisée pour chaque boutique du contexte courant. Conservez son jeton secret et appelez l'URL avec `curl` ou `wget`, par exemple :

```cron
0 5 * * * curl -fsS "URL_AFFICHEE_DANS_LE_MODULE" > /dev/null
```

Le hook `actionCronJob` et `getCronFrequency()` restent disponibles pour le module historique « Cron tasks manager ». Le fichier YAML présent dans la version 1.0 a été supprimé, car PrestaShop ne le charge pas comme une définition de tâche cron.

## Réglages

- **Seuil de stock** : un produit est désactivé si sa quantité est inférieure ou égale à ce seuil. La valeur par défaut est `0`.
- **Réactivation automatique** : si elle est active, seuls les produits mémorisés comme ayant été désactivés par SDX AutoStock sont réactivés.
- **Exécuter maintenant** : lance la synchronisation sur les boutiques du contexte courant.
- **Régénérer le jeton** : invalide immédiatement les anciennes URL de cron.

## Vérifications de développement

```bash
composer validate --strict
composer test
```

Le test ne dépend pas d'une installation PrestaShop : il vérifie la construction des requêtes multiboutiques, les compteurs et le rollback en cas d'erreur SQL.
