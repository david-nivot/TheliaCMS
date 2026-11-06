---
paths:
  - "templates/backOffice/default-twig/content/_section_form*.html.twig"
  - "templates/backOffice/default-twig/content/edit.html.twig"
  - "templates/backOffice/default-twig/assets/content-editor.*"
  - "templates/backOffice/default-twig/assets/media-picker.js"
  - "templates/backOffice/default-twig/assets/image-crop.js"
  - "Generation/Admin/SectionFormBuilder.php"
  - "Generation/Admin/SectionItemType.php"
  - "Content/Theme/ComponentPropsReader.php"
  - "Content/Site/SitePropReader.php"
---

# Formulaires d'édition des sections

Le formulaire d'une section, dans le tiroir de l'écran de contenu d'une page, est l'écran où un éditeur passe le plus de temps. Son rendu et son ergonomie demandent une attention particulière : un changement qui passe les tests peut le rendre illisible ou pénible.

## À chaque changement qui touche ces fichiers

- Preuve au navigateur, captures avant/après, à 1440 px, donc avec le tiroir à sa largeur réelle (environ 806 px). Un HTTP 200 ou un test DOM ne suffit pas.
- Couvrir trois formulaires au moins : une section native à liste (Mises en avant), un composant du thème à liste (`list<{…}>`), une section à image (cadrage).
- Pas de débordement horizontal du formulaire (`scrollWidth` ≤ `clientWidth`). Un texte de titre (80 caractères) se lit sans être tronqué.
- Libellés visibles sur chaque champ, y compris dans les lignes 2 et suivantes d'une liste. « (facultatif) » seulement sur un champ facultatif.
- Ce qui se règle rarement (apparence, cadrage, sources d'une image) reste replié ; ce qui se règle souvent (texte, image, lien) est visible.
- Tout se fait au clavier : ordre de tabulation, focus gardé après une action (déplacer une ligne, ajouter, retirer), annonces `aria-live` des changements.
- Recopier les assets dans l'hôte (`cms_asset()` le fait au premier appel) et recharger sans cache avant de juger le rendu.
- Libellés et aides traduits (`I18n/fr_FR.php`, `I18n/en_US.php`) et contrôlés en français, la langue des éditeurs.

## Repères dans le code

- Rangement en grille : `.cms-content__item-fields`, `.cms-content__field` (subgrid) dans `content-editor.css`.
- Une ligne de liste : macro `item` de `_section_form.html.twig` (repli sous le numéro et le premier texte, outils haut/bas/✕ hors du `summary`).
- Les attributs qui décident de la largeur d'un champ : `data-cms-long`, `data-cms-wide` posés par `SectionFormBuilder::addProp()`.
