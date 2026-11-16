---
paths:
  - "assets/blocks.css"
  - "Content/HeadingSection.php"
---

# Feuille des blocs (`blocks.css`)

- **Taille d'un titre : toujours `var(--cms-font-size-hN)`**, jamais `var(--font-size-hN-d)` ni `-m` directement. Le thème donne une taille mobile (`-m`) et une taille bureau (`-d`), Flexy bascule à 48rem ; les jetons du module font la même bascule. Lire `-d` partout donnait sur téléphone un titre de section plus grand que le titre de la page (36 px contre 28). Gardé par `Tests/Unit/Front/HeadingScaleTest.php`.
- Un niveau de titre de plus (h5…) : un jeton `--cms-font-size-hN` dans les deux blocs `:root` (mobile et `@media (min-width: 48rem)`), et le niveau dans la boucle du test.
- **Un bloc dessiné dans un composant du site** (`cms_component()`, ADR 17) est sous `.cms-site` : la largeur d'une section autonome (`--cms-block-inline`, `margin-inline: auto`) l'y décale de 1rem. `.cms-site .cms-heading` la retire et lui donne 1,5rem dessous, le composant ne le stylant pas (`HeadingSectionTest::testAHeadingDrawnByAComponentTakesTheBoxOfTheComponent`).
- **Un titre dans un style du thème** (`cms-heading__text--styled`, ADR 18) ne reçoit du module ni taille, ni graisse, ni interligne : la couche `cms` peut passer après celle du thème (Tailwind `utilities`) et la battrait. Toute règle typographique d'un titre exclut `:not(.cms-heading__text--styled)` (`HeadingSectionTest::testTheSheetSizesByItsLevelOnlyAHeadingWithoutAStyle`).
- Preuve : mesurer `getComputedStyle().fontSize` et les bords des boîtes à 375 et 1440 px. Une page statique qui charge `blocks.css` et les variables du thème suffit pour une règle de feuille seule (`page.setContent`).
