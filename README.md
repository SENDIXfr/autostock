# autostock

Module PrestaShop qui désactive les produits dont le stock agrégé atteint un seuil configurable, puis peut les réactiver lorsque le stock revient.

## Compatibilité

- PrestaShop 8.x, 9.0, 9.1 et 9.2 (plage déclarée : `8.0.0` à `9.2.99`)
- PHP 7.2.5 minimum pour PrestaShop 8 ; PHP 8.1 à 8.5 selon la version de PrestaShop 9 utilisée
- MySQL ou MariaDB pris en charge par la version de PrestaShop installée

La version 2.1.0 corrige les appels incompatibles avec les API de PrestaShop 9.2.0. Les tests locaux ont été exécutés avec PHP 8.4.22 ; une recette sur une boutique PrestaShop 9.2 reste nécessaire. Les versions futures de PrestaShop ne sont pas déclarées compatibles par anticipation. Voir [le rapport d’audit](AUDIT.md) pour les limites et les vérifications restantes.

## Installation

Le nom technique PrestaShop est `autostock`. Le dossier installé doit donc obligatoirement s'appeler `autostock` et contenir directement `autostock.php`.

1. Utiliser le dossier `autostock` du dépôt, ou créer un ZIP dont le dossier racine porte ce nom.
2. Copier le dossier dans `modules/` ou importer le ZIP depuis le gestionnaire de modules.
3. Installer puis ouvrir la page de configuration du module.

Une archive prête à importer est disponible dans `dist/autostock.zip`. Pour la reconstruire à partir des sources avec Python 3 :

```bash
python tools/build.py
```

Le script vérifie le contenu de l’archive et exclut les tests, outils, fichiers Git et anciennes archives. Le module n’a aucune dépendance PHP à installer avec Composer pour fonctionner.

### Installation après un changement de nom technique

PrestaShop identifie un module par son nom technique. Une copie installée sous un ancien nom ne peut donc pas être mise à niveau directement vers `autostock`. Le nouveau module utilise les clés `AUTOSTOCK_*`, la table `<préfixe>autostock_disabled` et de nouvelles URL cron ; aucune migration automatique depuis un ancien nom n’est exécutée.

Si une ancienne copie est déjà installée, sauvegarder ses réglages et sa table de suivi, puis désactiver son module et sa planification avant d’installer `autostock`. Reporter les réglages et copier les lignes de suivi dans la nouvelle table si la réactivation des produits précédemment désactivés doit être conservée. Mettre ensuite à jour la planification avec les URL affichées par `autostock`. Ne désinstaller l’ancienne copie qu’après récupération des données nécessaires.

### Mise à niveau d’une installation déjà nommée `autostock`

Pour une installation portant déjà ce nom technique en version 2.0.0, remplacer les fichiers par la version 2.1.0 puis lancer la mise à niveau depuis le gestionnaire de modules, sans désinstaller le module. Le script conserve les réglages, le jeton cron et les produits suivis. Il convertit uniquement la table de suivi en InnoDB pour garantir les transactions. Cette opération peut verrouiller la table pendant la conversion.

## Fonctionnement

- Le stock lu est la ligne agrégée `id_product_attribute = 0`, qui inclut toutes les déclinaisons.
- L'activation est modifiée dans `product_shop`, séparément pour chaque boutique. La table `product` est aussi alignée sur la boutique par défaut, et les caches internes des produits sont invalidés après modification.
- Le partage de stock d'un groupe multiboutique est respecté via l'API `StockAvailable` de PrestaShop.
- Une table dédiée mémorise uniquement les produits désactivés par le module. Un produit désactivé manuellement n'est donc jamais réactivé automatiquement.
- Une sauvegarde déclenchant `actionProductUpdate` retire le produit du suivi automatique dans les boutiques du contexte. Cela donne la priorité aux modifications du marchand, mais s’applique aussi aux imports et modules tiers qui déclenchent ce hook.
- Les mises à jour sont groupées et exécutées dans une transaction, sans charger tous les objets `Product` en mémoire.
- Un verrou MySQL/MariaDB par installation et boutique empêche deux synchronisations simultanées. Les lectures de verrou contournent le cache SQL.
- Seules les boutiques actives où le module est activé sont synchronisées. Leurs réglages héritent de leur propre groupe multiboutique.
- Une ligne de stock agrégé absente est considérée comme une quantité `0`.

## Planification

La page de configuration affiche une URL de cron sécurisée pour chaque boutique du contexte courant. Conservez son jeton secret et appelez l'URL avec `curl` ou `wget`, par exemple :

```cron
0 5 * * * curl -fsS "URL_AFFICHEE_DANS_LE_MODULE" > /dev/null
```

Le hook `actionCronJob` et `getCronFrequency()` restent disponibles pour le module historique « Cron tasks manager ». Le fichier YAML présent dans la version 1.0 a été supprimé, car PrestaShop ne le charge pas comme une définition de tâche cron.

Le cron répond en JSON, y compris en mode maintenance, et renvoie les statuts HTTP suivants : `200` en cas de succès, `400` pour une boutique invalide ou désactivée, `403` pour un jeton invalide, `409` si une synchronisation est déjà en cours, `500` en cas d’échec interne. Le statut `409` est accompagné de `Retry-After: 60` ; le planificateur peut réessayer ultérieurement. Le jeton est global à l’installation : il permet de lancer le cron sur toute boutique où le module est activé.

## Réglages

- **Seuil de stock** : un produit est désactivé si sa quantité est inférieure ou égale à ce seuil. La valeur par défaut est `0`.
- **Réactivation automatique** : si elle est active, seuls les produits mémorisés comme ayant été désactivés par autostock sont réactivés.
- **Exécuter maintenant** : lance la synchronisation sur les boutiques du contexte courant.
- **Régénérer le jeton** : invalide immédiatement les anciennes URL de cron.

## Vérifications de développement

```bash
composer validate --strict
composer test
```

Les tests sans installation PrestaShop couvrent les requêtes, le verrouillage, les erreurs et rollbacks, l’héritage multiboutique, les formulaires protégés, la migration et onze requêtes du contrôleur cron. Ils peuvent aussi être lancés directement :

```bash
php tests/StockSynchronizerTest.php
php tests/ModuleTest.php
php tests/CronControllerTest.php
```

Un test d’intégration SQL est fourni pour une base de test dédiée, avec `pdo_mysql` et les variables d’environnement `AUTOSTOCK_TEST_DSN`, `AUTOSTOCK_TEST_USER`, `AUTOSTOCK_TEST_PASSWORD` :

```bash
composer test:integration
```

Il crée des tables avec un préfixe aléatoire, puis les supprime à la fin. Il vérifie les changements réels de stock, l’idempotence, les déclinaisons, le multiboutique, le stock partagé, les rollbacks et la concurrence entre deux connexions. Le workflow GitHub Actions prévoit les tests PHP 7.2, 8.1, 8.4, 8.5 et les tests SQL sur MySQL 8.0 et MariaDB 10.11. Cette matrice teste le module isolé ; elle ne remplace pas une recette de PrestaShop complet.
