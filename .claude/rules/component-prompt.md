---
paths:
  - "Generation/Component/ComponentPrompt.php"
  - "docs/evaluation/components/**"
  - "Content/HeadingSection.php"
---

# Prompt du générateur de composants

- **Le modèle ne suit pas un « may ».** Une possibilité seulement offerte dans le prompt n'est pas prise : `cms:heading` listé « drawable » → 0/3 (ronde 10) ; la même chose écrite en règle, avec l'appel exact (`SITE_HEADING_RULE`) → 2/3 (ronde 11). Même constat pour le bouton d'une bande : 0/3 sans `OFFERED_PARTS_RULE`, 3/3 avec.
- Une règle neuve est une constante publique de `ComponentPrompt`, testée par un test unitaire `*RuleOfThePromptTest` (le texte) et par `ComponentGeneratorTest` (présente dans le prompt système) ; puis une ronde du banc sur un cas qui l'exerce (payante : accord du dev).
- **Contrat d'un cas** : le type d'un champ suit ce que le composant appelé déclare. Le `href` d'un objet du thème (`{href: string, …}`) est un `text`, pas un `url` : un contrat qui exige `url` met 0/3 à des essais justes.
- Script navigateur du banc (`Tests/Browser/component-bench.mjs`) sur un hôte DDEV : `CMS_BASE_URL=http://<hôte>` (en HTTPS, Chromium refuse le certificat local et tous les essais échouent), `FORCE=1` pour réécrire les blocs `browser` déjà posés ; signer les aperçus juste avant (`--refresh-previews`, une heure).
- Une règle changée rend périmés les essais enregistrés (`ComponentBenchReplayTest` les marque incomplets, sans échec).
- Le schéma d'une section dessinable (`cms:heading`) est écrit dans le prompt (ligne « Drawable » de `ComponentPrompt::drawable()`) : y ajouter une prop rend périmés tous les essais enregistrés, comme une règle changée, et change l'attendu de `ComponentCompositionTest`. Ses valeurs dépendent du thème (styles de titre de Flexy).
