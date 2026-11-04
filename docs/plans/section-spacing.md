# Precise room around a section (N17)

Phase F of the `feat/image-dimensions` branch, delivered in the same pull request as the images.

## Problem

The **Appearance** of a section offers three presets of room (`small`, `medium`, `large`: 1.5, 3, 5 rem), one value written as `padding-block` on the band `div.cms-section-styled`. No outer margin, no precise value.

## Decisions of the maintainer

1. Inner room (`padding`, the background of the section goes with it) and outer room (`margin`, keeps the section apart from its neighbours, the background does not follow).
2. Top and bottom apart, for each. The buttons Small / Medium / Large stay and set the inner room above and below together; precise fields sit next to them.
3. Units `px` (0 to 320) and `rem` (0 to 20). Strict pattern: a number of two decimals at most, then `px` or `rem`. No `calc()`, `var()`, negative value or other unit. An empty field is the room of the component, as without a setting.
4. Delivered in this branch.
5. The existing `padding` key also accepts a length.

## Decisions of the code

- Keys: `padding` (preset name or length, both sides), `padding_top`, `padding_bottom`, `margin_top`, `margin_bottom` (lengths only; no preset for a margin).
- Pattern `^(0|[1-9]\d*)(\.\d{1,2})?(px|rem)$`, applied after trim and lowercase; then the bound of the unit. A bare `0` is refused: `0px` says it.
- Priority: a side that is set (`padding_top`, `padding_bottom`) wins over `padding` for that side. The style writes `padding-block` first, then the sides, so CSS order does the arbitration with no logic in the renderer.
- Style order: background, text colour, `padding-block`, `padding-top`, `padding-bottom`, `margin-top`, `margin-bottom`, `text-align`. A section without the new keys writes the same style as before, byte for byte.
- The validation of the appearance is the only barrier (the CSS filter of the rendering does not bound a length): the style is built from the checked values only.
- Form: one text field per side (a number and its unit in one field, `64px`), more direct than a number plus a unit select for an editor who types a value, with an example as placeholder and the bounds in the help. A `padding` stored as a length, which no button stands for, is shown in the top and bottom fields, and saved there.
- First delivery: no button checked when the room is not a preset; replaced by the "Precise room" button below.
- Left and right (asked after the first delivery): `padding_left`, `padding_right`, `margin_left`, `margin_right`, same pattern and bounds; style order `padding-block`, `padding-top`, `padding-bottom`, `padding-left`, `padding-right`, `margin-top`, `margin-bottom`, `margin-left`, `margin-right`. A side margin narrows the band (the wrapper of the whole width). Narrow screens: a side on the left or the right is written `max(min(L, 5vw), min((100vw - 48rem) * 1000, L))`, the length from 48rem up and 5% of the screen at most below, in the style attribute (no media query reaches it, no layered rule of the module would outweigh an unlayered reset of a theme); `min` and `max` are among the functions the publication filter keeps.
- Form: the eight fields show under the room button "Precise room" only (value `precise`, never stored); another button leaves them out of the save. A preset beside a precise side opens on "Precise room", the preset written as its length in the inside fields left empty.
- Generator: the schema given to the model and the sheet of the catalogue are unchanged; the model sets presets only. A translation takes the appearance of the source. Export and import carry the document as it is; a site without this lot refuses the document of a page that holds a precise value (warning, page imported without document): said in the CHANGELOG.

## Tests

One per refused case (`calc(`, `var(`, unknown unit, negative, too large by unit, three decimals), the accepted cases, the render of an existing section byte for byte, form round trip, export then import.
