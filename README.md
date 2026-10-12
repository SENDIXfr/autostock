# autostock

Module PrestaShop qui désactive les produits dont le stock agrégé atteint un seuil configurable, puis peut les réactiver lorsque le stock revient.

## Compatibilité

- PrestaShop 8.x, 9.0, 9.1 et 9.2 (plage déclarée : `8.0.0` à `9.2.99`)
- PHP 7.2.5 minimum pour PrestaShop 8 ; PHP 8.1 à 8.5 selon la version de PrestaShop 9 utilisée
- MySQL ou MariaDB pris en charge par la version de PrestaShop installée

La version 2.1.0 corrige les appels incompatibles avec les API de PrestaShop 9.2.0. Les tests locaux ont été exécutés avec PHP 8.4.22 ; une recette sur une boutique PrestaShop 9.2 reste nécessaire. Les versions futures de PrestaShop ne sont pas déclarées compatibles par anticipation. Voir [le rapport d’audit](AUDIT.md) pour les limites et les vérifications restantes.

La version 2.2.0 ajoute une route webcron dédiée et les URL à copier dans la configuration du module.

La version 2.3.0 remplace le 404 des produits encore désactivés par autostock par une réponse HTTP `503 Service Unavailable` avec `Retry-After: 3600`.

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

Pour une installation portant déjà ce nom technique en version 2.0.0, 2.1.0 ou 2.2.0, remplacer les fichiers par la version 2.3.0 puis lancer la mise à niveau depuis le gestionnaire de modules, sans désinstaller le module. Les réglages, le jeton cron et les produits suivis sont conservés. Depuis la version 2.0.0, le script de mise à niveau 2.1.0 convertit uniquement la table de suivi en InnoDB pour garantir les transactions ; cette opération peut verrouiller la table pendant la conversion. Le script 2.3.0 enregistre le hook de gestion des produits indisponibles : remplacer uniquement les fichiers sans lancer la mise à niveau ne suffit pas pour activer cette fonctionnalité sur une installation existante.

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

## Produits désactivés et référencement

Les visites ordinaires `GET` et `HEAD` d’une fiche encore inactive et suivie par autostock dans la boutique courante répondent en HTTP `503 Service Unavailable`, avec un en-tête `Retry-After: 3600` (nouvel essai conseillé dans une heure, sans promesse de réapprovisionnement). Une page légère, traduite en français, indique l’indisponibilité et propose un retour à la boutique. L’URL reste celle du produit, sans redirection ni directive `noindex`. La réponse n’est pas mise en cache (`Cache-Control: private, no-store, max-age=0`).

Les produits réactivés reprennent le comportement normal de PrestaShop dès la requête suivante. Les produits désactivés manuellement, supprimés, suivis dans une autre boutique, les prévisualisations et les produits interdits au groupe du client restent gérés par PrestaShop. Le module ne modifie aucun fichier du cœur ; il intervient via `actionFrontControllerInitAfter`, avant le traitement natif des produits désactivés, après les restrictions de maintenance et de pays. Les requêtes AJAX et les méthodes autres que `GET` / `HEAD` restent également gérées par PrestaShop.

**Le 503 convient uniquement à une indisponibilité courte.** Il ne garantit pas la conservation de l’indexation : des erreurs 503 prolongées ralentissent l’exploration et peuvent entraîner la suppression des URL de l’index. Pour des ruptures longues ou fréquentes, privilégier une fiche accessible en HTTP `200`, décrivant le produit sans autoriser l’achat, ce qui nécessite de changer la politique de désactivation. Voir les recommandations Google sur les [codes HTTP](https://developers.google.com/crawling/docs/troubleshooting/http-status-codes) et la [restriction temporaire des fonctionnalités](https://developers.google.com/search/docs/crawling-indexing/pause-online-business).

Après mise à niveau, purger les éventuels caches de pages ou CDN qui ont déjà mémorisé les anciennes réponses 404, puis vérifier une fiche concernée avec `curl -I "URL_DU_PRODUIT"` : statut `503`, `Retry-After: 3600`, aucune redirection. Vérifier aussi son retour à `200` après réactivation et le maintien des vraies erreurs 404 sur la boutique de test.

## Planification

### Webcron depuis un service externe

Un webcron permet de planifier la synchronisation en appelant une URL HTTP depuis un service externe, sans accès aux tâches cron du serveur.

1. Ouvrir la configuration d’`autostock`, panneau **Cron et webcron**.
2. Copier le champ **URL webcron** de la boutique concernée dans le service de planification.
3. Choisir la méthode **GET**, sans corps de requête ni connexion au back-office. Le jeton secret est déjà inclus dans l’URL HTTPS.
4. Choisir la fréquence et le fuseau horaire dans le service, par exemple tous les jours à 05 h 00 en Europe/Paris. Le module ne programme pas lui-même les appels.
5. Lancer un test depuis le service et vérifier une réponse HTTP `200` avec `success: true` et les compteurs `disabled` / `enabled`.

Une tâche doit être créée pour chaque boutique à synchroniser. La route dédiée est `webcron`, par exemple `https://votre-boutique.example/index.php?fc=module&module=autostock&controller=webcron&id_shop=1&token=VOTRE_JETON`. Utiliser l’URL générée par le module pour respecter le domaine, le sous-dossier et la langue de la boutique.

Le webcron partage l’authentification, les réglages par boutique, la protection contre les exécutions simultanées et les réponses du cron serveur. Il fonctionne aussi en mode maintenance. La rotation du jeton invalide les anciennes URL cron et webcron : les mettre à jour dans le planificateur. Les appels `POST` sont également acceptés ; `HEAD`, `OPTIONS` et les autres méthodes ne lancent aucune synchronisation et renvoient `405`.

### Cron du serveur

Le même panneau affiche l’**URL cron serveur** pour chaque boutique du contexte courant. Conservez son jeton secret et appelez l’URL avec `curl` ou `wget`, par exemple :

```cron
0 5 * * * curl -fsS "URL_AFFICHEE_DANS_LE_MODULE" > /dev/null
```

Le hook `actionCronJob` et `getCronFrequency()` restent disponibles pour le module historique « Cron tasks manager ». Le fichier YAML présent dans la version 1.0 a été supprimé, car PrestaShop ne le charge pas comme une définition de tâche cron.

Le cron et le webcron répondent en JSON, y compris en mode maintenance, et renvoient les statuts HTTP suivants : `200` en cas de succès, `400` pour une boutique invalide ou désactivée, `403` pour un jeton invalide, `405` pour une méthode non acceptée, `409` si une synchronisation est déjà en cours, `500` en cas d’échec interne. Le statut `409` est accompagné de `Retry-After: 60` ; le planificateur peut réessayer ultérieurement. Le jeton est global à l’installation : il permet de lancer le cron ou le webcron sur toute boutique où le module est activé.

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

Les tests sans installation PrestaShop couvrent les requêtes, le verrouillage, les erreurs et rollbacks, l’héritage multiboutique, les formulaires protégés, la migration et 32 requêtes des contrôleurs cron et webcron. Une quatrième suite lance un serveur PHP local et vérifie 18 requêtes HTTP du traitement des produits indisponibles : 503, en-têtes, HEAD sans corps, échappement HTML et retour au contrôleur natif pour les cas exclus. Elle nécessite l’accès réseau à `127.0.0.1`. Ces tests utilisent des doublures du cœur et de la base ; ils ne remplacent pas une recette sur une boutique réelle. Ils peuvent aussi être lancés directement :

```bash
php tests/StockSynchronizerTest.php
php tests/ModuleTest.php
php tests/CronControllerTest.php
php tests/ProductAvailabilityTest.php
```

Un test d’intégration SQL est fourni pour une base de test dédiée, avec `pdo_mysql` et les variables d’environnement `AUTOSTOCK_TEST_DSN`, `AUTOSTOCK_TEST_USER`, `AUTOSTOCK_TEST_PASSWORD` :

```bash
composer test:integration
```

Il crée des tables avec un préfixe aléatoire, puis les supprime à la fin. Il vérifie les changements réels de stock, l’idempotence, les déclinaisons, le multiboutique, le stock partagé, les rollbacks et la concurrence entre deux connexions. Le workflow GitHub Actions prévoit les tests PHP 7.2, 8.1, 8.4, 8.5 et les tests SQL sur MySQL 8.0 et MariaDB 10.11. Cette matrice teste le module isolé ; elle ne remplace pas une recette de PrestaShop complet.
