# Header highlights in TheliaCMS (N22)

Status: decided (2026-11-05), not started. Branch `feat/header-highlights`, from `main` at `4fb9daf`.

## Goal

The editor sets the banner of one to three highlighted blocks that HeaderHighlights draws today, from TheliaCMS: media library images with their size and crop (N8), translations, draft, publication and versions. A site no longer needs HeaderHighlights for it.

## What HeaderHighlights 2.2.2 does (read in the test host, `vendor/thelia/modules/HeaderHighlights`)

- One banner for the whole site. A row of `header_highlights` is one block (`image_block` 1 to 3, block 1 being the large one) for one display (`display_type`: `desktop` or `mobile`), with an i18n title, catchphrase, call to action and URL, and its images in `header_highlights_image`. `category_id` is set to the first category at creation (`Model/HeaderHighlights.php:23`) and read nowhere else.
- A mobile block falls back to the desktop block of the same number (`Twig/HeaderHighlights.php`, `getMobiles()`).
- Rendered by a theme hook on `layout.header.bottom` (`Hook/Theme/HeaderHighlightsThemeHook.php`), which Flexy calls on every page (`templates/frontOffice/flexy/base.html.twig:119`), through the Twig component `HeaderHighlights`: `<ul class="Hero">`, a `<picture>` with the desktop image above 1024px and the mobile one below, a `Molecules:Button:Base` per block.
- Served by `/api/header-highlights` (API Platform resources in `Api/Resource/`), with its own shared-cache purge (`SharedCache/`).
- No stylesheet: the `.Hero…` classes are left to the theme, and Flexy has none (no `.Hero` rule under `templates/frontOffice/flexy`).
- Test host: the four tables are empty. A data migration has to be tested on a fixture, or on a copy of a site that uses the module.

## What TheliaCMS already has

- Theme hooks rendered by the module: `Menu/FooterMenuThemeHook.php`, `Consent/ConsentThemeHook.php`, `Catalogue/ItemContentThemeHook.php` and three more. Drawing something of the CMS on `layout.header.bottom` follows a known pattern.
- Images with width, height, ratio, focal point and zoom (N8, 2.12.0). One ratio per image: `docs/plans/image-dimensions.md` §5 left a second ratio for narrow screens (art direction) for later.
- A CMS home page (`home_page_id`). No shared section yet (F8 is not engaged).

## Options

### Where the banner lives

- (a) A section component of the module, `cms:highlights`, one to three blocks, placed in any page by the editor or the generator. Shows only where it is placed (the CMS home page, typically), not on every page as HeaderHighlights does.
- (b) A site zone drawn by TheliaCMS on `layout.header.bottom`, edited like a page (a hidden page used as a zone). Reproduces today's behaviour on every page. It is the first piece of F8 (shared sections): one fixed zone, not the general mechanism.
- (c) A Flexy component declared in `config/theliacms.yaml`. No module code, but it needs a Flexy PR for the component and its styles, and it shares the limit of (a): no display on every page.
- (a) and (b) combine: the component is written once, and the zone of (b) holds one section of it.

### The mobile image

- (m1) Each block has an optional `image_mobile` prop, drawn in a `<picture>` below 1024px, as HeaderHighlights does. Local to the component.
- (m2) One image per block, cropped by the N8 ratio on every screen. Less to fill, loses what a site set for mobile.
- (m3) Art direction for every image of the module (second ratio, `docs/plans/image-dimensions.md` §5 (c)). Larger, outside N22.

### The existing data and the API

- (d1) A command `thelia_cms:import:header-highlights` that copies the banner to a section (images to the media library, every language, desktop and mobile), as a draft: the editor publishes it, then removes HeaderHighlights. `/api/header-highlights` is not reproduced: a site that reads it keeps the module.
- (d2) Same, and the module answers `/api/header-highlights` from the section, so a client reading the API keeps working.
- (d3) No import: the editor writes the banner again.

## Decided (2026-11-05)

- Place: (a) then (b). The component `cms:highlights` first, usable in any page; then a zone on `layout.header.bottom` that holds one section of it, for display on every page.
- Mobile image: (m1), an optional `image_mobile` prop per block, drawn below 1024px.
- Data: (d1), a command that imports the banner as a draft; `/api/header-highlights` is not reproduced, a site that reads it keeps HeaderHighlights.

## Steps

1. Component `cms:highlights`: props, render (`<ul class="cms-highlights">`, `<picture>`), styles in `assets/blocks.css` (Flexy does not compile Tailwind utilities written in the module), `help` texts for the generator, catalogue sheet, tests (render, props, generator schema).
2. `image_mobile` per block (m1), falling back to the desktop image like HeaderHighlights.
3. Zone on `layout.header.bottom` (b), in its own PR after step 1: theme hook, hidden zone page, screen, cache invalidation with the page, test of the hook on a Flexy page.
4. Import (d1): command, fixture of the four tables, test seen red, dry run.
5. CHANGELOG, README (Theme integration), version bump with its SQL file if a table or a setting is added.

## Estimate (reading, not a prototype)

- (a) with (m1): 1 to 1.25 days.
- (b) on top: 1 to 1.5 days.
- (d1): 0.5 day; (d2): 0.5 to 0.75 day more.
- Contingency 25% on the batch: no real data in the test host, and the zone of (b) touches the page cache (`PageInvalidator`, `ScheduledChanges`).

## Not checked

- The exact HTML HeaderHighlights draws on a site that styles `.Hero`; whether sites read `/api/header-highlights` outside the theme.
- Whether a second HeaderHighlights per category exists on any site (`category_id` suggests an intent the code does not follow).
