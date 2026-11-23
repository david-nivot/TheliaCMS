# TheliaCMS : module Thelia 3 de pages, composants et sections, rédaction par la couche IA du core

Dépôt public `thelia-modules/TheliaCMS` (GitHub). Ce dépôt EST le module : pas d'application, pas de `vendor/` propre.

## Stack

| Composant | Version |
|-----------|---------|
| Framework | Thelia 3 (`thelia/core ^3.4`, `<thelia>3.4.0</thelia>` dans `Config/module.xml`) |
| PHP | ≥ 8.3 (CI : 8.3, 8.4, 8.5) |
| ORM / front | Propel (`Config/schema.xml`) ; front Flexy/Twig (`templates/frontOffice/flexy`, `templates/front`) ; back-office Twig (`templates/backOffice/default-twig`) |
| Environnement | Aucun dans le dépôt : testé dans un hôte Thelia 3 sous DDEV, module cloné (pas lié) dans `local/modules/TheliaCMS` |
| CI | GitHub Actions : `ci.yml` au push de toute branche (style, `composer validate`, audit, tests sur `thelia/thelia@main` en PHP 8.3 à 8.5, sur Thelia 3.4.0 (plancher) et sur la ligne 3.4, PHPStan), `release.yml` (release auto au push sur `main`, workflow partagé épinglé) |

## Namespace et fichiers clés

- Racine : `TheliaCMS\` → racine du dépôt ; classe module `TheliaCMS.php`
- Schéma : `Config/schema.xml`, `Config/TheliaMain.sql` (install neuve), `Config/update/<version>.sql` (montées)
- Version : `<version>` de `Config/module.xml`
- Doc contributeur : `docs/developing.md` (pièges), `docs/adr/` (décisions), `CHANGELOG.md` (Keep a Changelog, section `[Unreleased]`)

## Commandes

```bash
# Qualité (PHPStan niveau 5 + baseline, style du core), depuis local/modules/TheliaCMS de l'hôte
composer stan        # baseline : régénérer après un correctif, jamais y ajouter une erreur neuve
composer cs          # composer cs:fix pour appliquer
composer test        # = suite unitaire ci-dessous

# Tests : depuis la racine de l'hôte Thelia 3, dans le conteneur DDEV
vendor/bin/phpunit -c local/modules/TheliaCMS/phpunit.xml.dist
php bin/test-prepare   # avant la première suite d'intégration
env -u DATABASE_NAME vendor/bin/phpunit -c local/modules/TheliaCMS/phpunit.integration.xml.dist

# Cache du conteneur de test (après service, route, listener ou template modifié)
env -u DATABASE_NAME php Thelia cache:clear --env=test --no-debug
```

## Zones protégées

Ne jamais modifier :
- `vendor/`, `node_modules/` de l'hôte
- `composer.lock` de l'hôte, `.env.local`
- `Model/Base/`, `Model/Map/` (générés par Propel, ignorés par git)
- Un `Config/update/<version>.sql` déjà publié (créer une nouvelle version)

## Skills actifs

- `os:security`, `os-php:php`, `os-php:symfony`
- `thelia:thelia3`, `thelia:propel-thelia`, `thelia:api-platform`, `thelia:symfony-ux`
- `thelia:thelia3-backoffice-twig` (écrans d'administration du module)
- `thelia:thelia3-tooling`, `thelia:ddev` (hôte de test)
- `thelia:code-review-expert`, `thelia:docs-templates`

## Enrichissement auto

`/os:review` et `/os:feature` enrichissent ce fichier (conventions) et `REVIEW.md` (règles de review) quand des patterns projet sont découverts. `/os:capitalize` y verse en fin de session ce qui sert à toute l'équipe : pièges, intégrations externes et leurs runbooks, cartographie d'exploitation, sans compte ni accès ni secret. Ne pas supprimer les entrées ajoutées automatiquement sauf si obsolètes.

## Conventions projet

- Commits en anglais, `type(hors-ticket): …` (dépôt sans ticket GitLab) ; branche `feat/…` ou `fix/…`, PR sur GitHub vers `main`
- Release : branche `chore/release-X.Y.Z` depuis `main`, un commit `chore(release): X.Y.Z, <résumé>` qui monte `<version>` de `Config/module.xml` et transforme `[Unreleased]` du CHANGELOG en `[X.Y.Z] - AAAA-MM-JJ` (avec ses liens de pied), puis PR ; « Auto Release » pose le tag et la release à la fusion
- Chaque changement visible ajoute son entrée dans `CHANGELOG.md` (`[Unreleased]`), dans le même commit
- Tout appel IA passe par `Thelia\Domain\Ai\AiGatewayInterface` sous un usage `#[AsAiUsage]` du module ; aucun SDK de fournisseur
- Décision d'architecture : un ADR numéroté dans `docs/adr/`

## Pièges connus

Liste complète et parades dans `docs/developing.md`.

| Piège | Parade |
|-------|--------|
| Lien symbolique de l'hôte vers ce clone : non résolu dans le conteneur DDEV | Cloner dans `local/modules/TheliaCMS` de l'hôte |
| SQL ajouté à un `update/<version>.sql` déjà déployé : jamais rejoué | Nouveau fichier de version + `module.xml` + `TheliaMain.sql`, rejouable (`information_schema`) |
| Colonne du core en utf8mb3 sur une base venue de Thelia 2 (`module_config_i18n.value`) : `COLLATE utf8mb4_bin` refusé (1253), et la version déjà enregistrée si un `ALTER` du même passage a commité | `CONVERT(col USING utf8mb4) COLLATE utf8mb4_bin` ; tester sur la colonne en utf8mb3 (`EffortFromCoreMigrationTest::valueColumnIn()`) ; rattrapage par une nouvelle version (`2.42.1.sql`) |
| Modèles Propel gardés tant que le hash correspond | Après `schema.xml` modifié : supprimer `var/propel/dev` et `var/propel/test` de l'hôte |
| `DATABASE_NAME=db` de DDEV branche les tests d'intégration sur la base de dev | `env -u DATABASE_NAME`, et reconstruire `var/cache/test` / `var/propel/test` bâtis sans |
| Twig de test non rechargé : un test passe sur l'ancien gabarit | Écarter `var/cache/test/twig` (ou `touch` + `var/cache/test`) avant les tests |
| `module_asset()` du core copie un asset une fois dans `public/assets/backOffice/default-twig/TheliaCMS/`, jamais remplacé hors debug | `cms_asset()` dans les gabarits du module (recopie si la source diffère) ; `assets_version` pour le cache du navigateur |
| URL d'une image de la médiathèque (`publicUrl()`, `PageImage::url`) : un chemin, pas une adresse | `absolute_url()` dans tout ce qu'un tiers va chercher (`og:image`, flux, courriel) |
| `composer cs:fix` lancé dans le clone de l'hôte : la correction reste dans l'hôte | Recopier les fichiers corrigés vers ce dépôt (`cmp` fichier par fichier) avant de committer |
| La CI garde le `upload_max_filesize` par défaut de PHP (2 Mo) : un fichier de test plus lourd est refusé par PHP avant le module | `ini-values` de `setup-php` dans `ci.yml` ; un test qui en dépend se saute, avec sa raison, sous la limite |
| Après une montée de Flexy dans l'hôte (`composer update thelia/flexy`), tout le front répond 500 (asset `@hotwired/stimulus` absent), ou des styles neufs du thème manquent (« Aller au contenu » visible : `var/tailwind/app.built.css` plus ancien que la source) | `php Thelia importmap:install`, `bin/console tailwind:build`, puis `cache:clear` |
| Flexy `main` exige `thelia/core ^3.4`, que Packagist ne publie pas encore : `main` ne peut pas sortir de version installable | Les correctifs pour le core 3.3 partent sur la branche de maintenance `1.3` de Flexy (même schéma que `1.2` ; n'en créer aucune parallèle) : PR vers `1.3`, qui lance la CI du thème sans file de fusion ; puis une PR qui monte `<version>` de `template.xml`, et le tag `1.3.N` sur son commit de fusion. Flexy supprime la branche d'une PR fusionnée. Ne pas forcer `-W`, qui monte le core |
| Un écran qui refuse un fichier côté navigateur (`data-max-size`) donne la raison que le gabarit lui passe | Lui passer le message de la limite qui joue : plafond du module ou limite de PHP |
| Test qui compte les requêtes : le pool d'instances de Propel et la mémoire d'adresses du core cachent la requête posée par page | `forgetWhatWasRead()` du trait `CountsStatements` avant chaque mesure |
| « Auto Release » rouge sur `main` après une fusion sans montée de version (« <version> is still X.Y.Z, already tagged ») : voulu, pas une panne | Rien à réparer : la PR `chore(release): X.Y.Z, …` publie, et la release passe au vert |
| CI sous MySQL 8, hôte DDEV sous MariaDB : une date à fraction de seconde est arrondie par l'un, tronquée par l'autre (test vert sur l'hôte, rouge une fois sur deux en CI) | Date de test construite à la seconde (`date('Y-m-d H:i:s', …)`) |
| Hôte de test en environnement dev : le profileur Symfony pose ses propres cookies (`main_deauth_profile_token`), le cache partagé du core ne partage donc aucune page ; `curl -I` y montre toujours `private, no-store`, quel que soit le code | Prouver les en-têtes de cache par un test qui passe par tout `kernel.response` (`CoreSharedCacheTest::respondThroughTheKernel`), ou sur un hôte en environnement prod |
| SEOne : la CI refuse une PR sans montée de version (job « Version bump ») ; Flexy (thelia-templates/flexy) fusionne par une file de fusion | Monter `<version>` et le titre du CHANGELOG dans la PR SEOne ; compter environ 15 min pour la file de Flexy |
| La suite de Flexy sur l'hôte (`vendor/bin/phpunit -c templates/frontOffice/flexy/phpunit.xml.dist`) tourne sans debug : après un gabarit du thème modifié, `cache:clear --env=test` vide le conteneur debug et Twig sert encore l'ancien gabarit | `bin/console cache:clear --env=test --no-debug` |
| Deux suites d'intégration en même temps sur l'hôte (ou une suite coupée) : doublons en base de test (`admin-40`, `ROUND-TRIP-REF`), des centaines d'échecs sans rapport avec le code | `php bin/test-prepare`, puis une seule suite à la fois |
| Clone de l'hôte passé d'une branche à une autre quand l'une ajoute un service : le conteneur de test compilé ne se reconstruit pas, toute la suite tombe (plus de mille erreurs, aucune assertion) | Renommer `var/cache/test` de l'hôte (jamais le supprimer), puis relancer la suite |
| Banc de déterminisme en cours sur l'hôte (appels payants pendant une demi-heure par manche) : une copie de code, un `cache:clear` ou une suite fait échouer les appels en vol ; deux manches ensemble se bloquent (une génération par page à la fois) | Rien sur l'hôte pendant une manche ; les manches l'une après l'autre |
| Base de test sans adresse de boutique (`store_email` vide) : tout courriel échoue sur l'expéditeur, et `SubmissionMailer` journalise sans lever | Écrire `store_email` dans le `setUp()` ; lire les envois par `mailer.message_logger_listener` (`FormSubmissionTest`) |
| Deux PR ouvertes qui ajoutent chacune une section sous `[Unreleased]` du CHANGELOG : conflit dès que la première est fusionnée, et `gh pr update-branch` échoue alors | Fusionner `main` dans la branche, garder les deux entrées dans l'ordre Added, Changed, Fixed, relancer la suite |
| `IntegrationTestCase` enveloppe chaque test dans une transaction (`$useTransaction = true`) : un test qui vérifie qu'un effet suit un commit en comparant le niveau de transaction à zéro ne voit jamais le commit | Comparer `ConnectionWrapper::getNestedTransactionCount()` à sa valeur avant l'appel (`Tests/Integration/Content/ContentSideEffectsTest.php`) |
| Une date posée par le code avant `save()` (`published_at`) et `updated_at` posé ensuite par le timestampable de Propel : une seconde qui tourne entre les deux date la modification après la publication (page vue « modifiée depuis la publication », test rouge au hasard en CI) | Poser les deux colonnes au même instant (`Clock::get()->now()`) ; le prouver par `ClockSensitiveTrait::mockTime()` à un instant fixe (`PageStatusCriteriaTest::testAPageJustPublishedIsNotModifiedSincePublication`) |
| La `.card` du thème `default-twig` a `overflow: hidden` : un panneau en `position: absolute` posé dedans (menu déroulant, `<details>`) est coupé au bord de la carte | `overflow: visible` sur cette carte seule (`.cms-filter-card`, `_filter_bar.css.twig`, partagé par la liste des pages et les réponses) |
| Réponse en flux (`StreamedResponse`, export des réponses) dans un test : `$client->getResponse()->getContent()` rend `false` | Lire `$client->getInternalResponse()->getContent()` (`SubmissionExportFileTest`) |
| Le bloc du module sur l'accueil du back-office (`Hook/CmsDashboardHook.php`) ne s'affiche qu'en mode vitrine : un test qui cherche ses éléments sur `/admin` trouve une page sans eux | Passer `CmsSettings::SITE_MODE` à `SiteMode::Showcase` le temps de la requête et le remettre ensuite (`StaleTranslationTest::dashboard()`) |
| La base de test n'a ni connexion ni modèle IA, et le juge des fichiers comme la passerelle du core sont des services privés : un test d'intégration n'envoie aucun appel réel (le juge répond `no_model`) | Pour un essai avec un vrai modèle, script jetable dans `var/` de l'hôte, sur le noyau `dev` : récupérer les services du core par réflexion depuis un service public (le contrôleur `ContentController`), puis appeler le service ; les appels s'inscrivent au journal IA de la base de dev |
| Test d'intégration : après un écran du back-office, le noyau de test garde les gabarits du back-office devant ceux du thème ; une page du site demandée ensuite (aperçu, front) sort dans la mise en page du back-office, sans les hooks du thème | Demander cette page en premier dans le test (`SiteComponentScreenTest::testThePreviewLinksTheScriptOfTheVideosAsTheSiteDoes`) |
| Une PR (externe ou d'une autre session) ouverte avant une release peut garder ses entrées de CHANGELOG sous le titre de la version publiée entre-temps : la fusion de `main` passe sans conflit et les y laisse | Avant chaque release, comparer chaque section publiée à son tag : `diff <(git show X.Y.Z:CHANGELOG.md \| sed -n '/^## \[X.Y.Z\]/,/^## \[/p') <(sed -n '/^## \[X.Y.Z\]/,/^## \[/p' CHANGELOG.md)` ; déplacer les entrées sous `[Unreleased]` |
| Deux PR ouvertes qui créent chacune un ADR prennent le même numéro (`docs/adr/0013-…` deux fois) : la fusion de `main` passe sans conflit de fichier, seul `docs/adr/README.md` entre en conflit | À la fusion de `main` dans une branche, `ls docs/adr` : renuméroter l'ADR de la branche, son titre, sa ligne du README et tous ses renvois (`grep -rn "ADR <n>"`) |
| Un contrat de cas du banc changé (`docs/evaluation/components/cases/*.json`) fait changer le verdict des essais enregistrés : `ComponentBenchReplayTest` échoue sur des essais dont le code n'a pas bougé | Rejuger les enregistrements concernés (`Tests/Fixtures/component-bench/<ronde>/<cas>-*.json`, bloc `verdict`) dans le même commit que le contrat, et le dire dans le message |
| Flexy avant 1.3.1 ne livre pas de `config/theliacms.yaml` : sur un hôte en 1.3.0, le catalogue n'offre aucun composant du thème, ni au générateur ni à `cms_component()` | `composer update thelia/flexy` (1.3.1 : quinze composants, `heading_styles`, `product.content`, méta sociales), puis `php Thelia importmap:install`, `bin/console tailwind:build`, `cache:clear` |
| La CI du module installe `thelia/flexy:dev-main` (jobs Thelia `main` et 3.4) : une PR Flexy fusionnée qui change un `@prop` ou le `theliacms.yaml` peut rendre `main` du module rouge sans aucun commit du module (le bouton objet de CrossSelling a ajouté une liste au formulaire de la bande) | Un test d'écran vise un champ ou une liste par son nom, jamais par un compte ; après la fusion d'une PR Flexy de ce type, relancer la CI du module avant d'enchaîner les fusions |
| Une page de preuve « supprimée » au back-office va à la corbeille (`deleted_at`) ; `thelia_cms:pages:purge-trash` ne l'efface qu'après la durée de rétention | Effacer la page par son id, puis vider la corbeille seulement si elle ne contient que les pages créées pour la preuve |
| Les cadres d'aperçu du back-office (page d'un composant, prototype de l'écran de contenu) sont isolés (`sandbox` sans `allow-same-origin`, origine `null`) : les modules JavaScript de Flexy et la barre de débogage y sont bloqués par CORS, la console se remplit d'erreurs, et un composant qui dépend d'un contrôleur Stimulus paraît inerte | Erreurs attendues, pas une régression : les écarter dans une sonde au navigateur ; juger le comportement JavaScript d'un composant sur une page du site, pas dans le cadre (`components/show.html.twig`, `content/edit.html.twig`) |
| Deux sessions sur le dépôt préparent la même release : `chore/release-X.Y.Z` existe déjà sur GitHub, le push est refusé (non fast-forward) | Avant de préparer une release : `gh pr list --search "chore(release)" --state all -L 3` et `git ls-remote --heads origin 'chore/release-*'` ; une PR ouverte : ne pas la doubler |
| Fusionner `main` dans une branche de PR pendant qu'une suite tourne sur le clone de l'hôte : les fichiers changent sous la suite | Faire la fusion dans un `git worktree` hors du clone (`git worktree add <dossier> <branche>`), puis `git worktree remove` ; les tests se jouent dans le clone, une fois la suite finie |
| Une branche de Flexy ou de SEOne à tester sur l'hôte : les deux y sont installés par Composer (`templates/frontOffice/flexy`, `vendor/thelia/modules/SEOne`), qu'on ne modifie pas | Flexy : vérifier que la copie de l'hôte est identique à son tag (`git archive <tag>` extrait à part, puis `diff -rq`), y recopier les fichiers de la branche, tester, puis y remettre les fichiers du tag et retirer ceux ajoutés. SEOne : `git worktree add <hôte>/local/modules/SEOne <branche>` (prime sur `vendor/`), `vendor/bin/phpunit -c phpunit.xml.dist local/modules/SEOne/Tests` comme la CI partagée, puis `git worktree remove`. Dans les deux cas, renommer `var/cache/test` avant la suite et après |
| Flexy n'affiche que le premier niveau du menu `footer` (`Layouts/Footer/Base.html.twig` parcourt `content_slot('footer_links')` sans les enfants), et une entrée sans cible ni libellé dans la langue lue est écartée du menu : un pied de page fait d'intitulés de groupe n'affiche aucun lien, et un site servi en `en_US` perd les entrées libellées en français seulement | Menu `footer` à plat ; une entrée sans cible (intitulé) ou vers une URL porte un libellé dans chaque langue active |
| `fetchColumn()` sur une requête préparée par la connexion Propel (`Propel::getConnection()->prepare()`) rend `null`, pas `false`, quand il n'y a aucune ligne : une boucle `while (false !== $id = $statement->fetchColumn())` tourne une fois de trop avec un id nul | Tester l'id lu (`0 < (int) $statement->fetchColumn()`) ; `Tests/Browser/sample-site/seed.php` |
| Un droit déclaré dans `CmsResources::splitFrom()` reçoit à la montée tout le masque d'accès du droit d'origine : contrôlé sur VIEW, il est accordé à chaque profil qui ne faisait que lire l'origine | Contrôler un droit dérivé sur l'accès que l'origine exigeait pour ce geste (UPDATE pour publier) ; le prouver par un profil qui n'a que VIEW sur l'origine (`PagePublishRightTest::testTheRightToPublishOnViewOnlyPublishesNothing`) |
| Base de test installée avant un droit neuf : la ressource n'existe pas, et `EditorProfileSeeder` (qui parcourt `resource`) ne l'accorde pas ; `restrictedAdmin()` la crée, lui, sans titre | Jouer `TheliaCMS::update($v, $v)` dans le test avant le seeder (`PagePublishRightTest::testTheSeededEditorProfilePublishes`) |
| Toute écriture d'un brouillon depuis le back-office poste `draft_version` (la version lue) ; sans elle, ou périmée, le serveur refuse (409 sur l'écran de contenu) : un test qui poste à la main un tableau de champs échoue | Lire la version dans l'écran (`input[name="draft_version"]`) et la poster ; un écrivain hors écran (commande, import) passe `null` et fait avancer la version |
| Une autre session publie une version pendant qu'une branche prépare sa migration : un `Config/update/<version>.sql` au numéro d'une version déjà taguée n'est jamais joué sur les sites qui l'ont (2.36.0 publiée sans SQL pendant la marche M, migration renommée en 2.37.0) | Avant de pousser une migration, et à chaque fusion de `main` dans la branche : `git ls-remote --tags origin <version>` ; une version taguée : renommer le fichier, son en-tête et ses mentions (CHANGELOG, plan) au numéro suivant |
| La version d'un brouillon lue sur le modèle Propel peut être celle du début de la requête (pool d'instances) ; et une course entre lecture et écriture ne se montre pas dans un seul processus | Comparer sous verrou en SQL (`DraftLock::claim()`, `SELECT … FOR UPDATE`) ; prouver une course par deux processus (`DraftRaceTest`, modèle de `SenderBurstTest`) |
| Un hôte où le site d'exemple a tourné (`Tests/Browser/sample-site/seed.php` désactive Page et TheliaBlocks) : `bin/install` plante ensuite au démarrage (« Page\Model\Base\PageTypeQuery not found »), sur des modèles Propel bâtis sans ces modules ; et le script Composer `demo-database` de l'hôte passe `APP_ENV=test`, donc réinstalle la base de test | Renommer `var/propel/dev` avant `bin/install` ; pour la base de dev, lancer `bin/install` dans le conteneur avec les `DATABASE_*` de l'environnement, pas par ce script |
