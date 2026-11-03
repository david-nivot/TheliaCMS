# TheliaCMS : module Thelia 3 de pages, composants et sections, rédaction par la couche IA du core

Dépôt public `thelia-modules/TheliaCMS` (GitHub). Ce dépôt EST le module : pas d'application, pas de `vendor/` propre.

## Stack

| Composant | Version |
|-----------|---------|
| Framework | Thelia 3 (`thelia/core ^3.3`, `<thelia>3.3.0</thelia>` dans `Config/module.xml`) |
| PHP | ≥ 8.3 (CI : 8.3, 8.4, 8.5) |
| ORM / front | Propel (`Config/schema.xml`) ; front Flexy/Twig (`templates/frontOffice/flexy`, `templates/front`) ; back-office Twig (`templates/backOffice/default-twig`) |
| Environnement | Aucun dans le dépôt : testé dans un hôte Thelia 3 sous DDEV, module cloné (pas lié) dans `local/modules/TheliaCMS` |
| CI | GitHub Actions : `ci.yml` au push de toute branche (style, `composer validate`, audit, tests sur `thelia/thelia@main` en PHP 8.3 à 8.5 et sur Thelia 3.3.0, PHPStan), `release.yml` (release auto au push sur `main`, workflow partagé épinglé) |

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
- Release : après merge, commit `chore(hors-ticket): ship <résumé> as X.Y.Z` qui ne touche que `<version>` de `Config/module.xml`
- Chaque changement visible ajoute son entrée dans `CHANGELOG.md` (`[Unreleased]`), dans le même commit
- Tout appel IA passe par `Thelia\Domain\Ai\AiGatewayInterface` sous un usage `#[AsAiUsage]` du module ; aucun SDK de fournisseur
- Décision d'architecture : un ADR numéroté dans `docs/adr/`

## Pièges connus

Liste complète et parades dans `docs/developing.md`.

| Piège | Parade |
|-------|--------|
| Lien symbolique de l'hôte vers ce clone : non résolu dans le conteneur DDEV | Cloner dans `local/modules/TheliaCMS` de l'hôte |
| SQL ajouté à un `update/<version>.sql` déjà déployé : jamais rejoué | Nouveau fichier de version + `module.xml` + `TheliaMain.sql`, rejouable (`information_schema`) |
| Modèles Propel gardés tant que le hash correspond | Après `schema.xml` modifié : supprimer `var/propel/dev` et `var/propel/test` de l'hôte |
| `DATABASE_NAME=db` de DDEV branche les tests d'intégration sur la base de dev | `env -u DATABASE_NAME`, et reconstruire `var/cache/test` / `var/propel/test` bâtis sans |
| Twig de test non rechargé : un test passe sur l'ancien gabarit | Écarter `var/cache/test/twig` (ou `touch` + `var/cache/test`) avant les tests |
| Assets back-office copiés une fois dans `public/assets/backOffice/default-twig/TheliaCMS/` | Recopier, et monter `assets_version` |
| URL d'une image de la médiathèque (`publicUrl()`, `PageImage::url`) : un chemin, pas une adresse | `absolute_url()` dans tout ce qu'un tiers va chercher (`og:image`, flux, courriel) |
| `composer cs:fix` lancé dans le clone de l'hôte : la correction reste dans l'hôte | Recopier les fichiers corrigés vers ce dépôt (`cmp` fichier par fichier) avant de committer |
| La CI garde le `upload_max_filesize` par défaut de PHP (2 Mo) : un fichier de test plus lourd est refusé par PHP avant le module | `ini-values` de `setup-php` dans `ci.yml` ; un test qui en dépend se saute, avec sa raison, sous la limite |
| Après une montée de Flexy dans l'hôte (`composer update thelia/flexy`), tout le front répond 500 (asset `@hotwired/stimulus` absent) | `php Thelia importmap:install`, puis `cache:clear` |
| Un écran qui refuse un fichier côté navigateur (`data-max-size`) donne la raison que le gabarit lui passe | Lui passer le message de la limite qui joue : plafond du module ou limite de PHP |
| SEOne : la CI refuse une PR sans montée de version (job « Version bump ») ; Flexy (thelia-templates/flexy) fusionne par une file de fusion | Monter `<version>` et le titre du CHANGELOG dans la PR SEOne ; compter environ 15 min pour la file de Flexy |
