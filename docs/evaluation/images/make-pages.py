#!/usr/bin/env python3
"""Writes pages.json, the site export `thelia_cms:import` reads to create the
image test page. Usage: make-pages.py [--slug SLUG] <width>=<id>:<file> ...
(width, id and stored file name of each library image, one per use: 5 of 308 px,
4 of 900, 7 of 1200, 5 of 2400, see README.md).

With `--components`, it writes the page of the components bench (slug
`img-test-components` unless `--slug` says otherwise): a component of the theme
(`fixtures/ImgTestCard.html.twig`, opened by `fixtures/theliacms.yaml`) and a component of
the site (`ImgTestPhoto`, carried by the export) with their pictures framed;
`quadrants=<id>:<file>` and `portrait=<id>:<file>`. See README.md.

With `--frame`, it writes the page of the framing bench instead (slug
`img-test-frame` unless `--slug` says otherwise): `quadrants=<id>:<file>`, a
landscape picture whose four quarters are four plain colours (make-quadrants.php),
`portrait=<id>:<file>` and `small=<id>:<file>`, a portrait picture and one
narrower than the 960 px box. See README.md."""
import json
import sys

media = {}
ids = {}
slug = 'img-test-sections'
arguments = sys.argv[1:]
frame = '--frame' in arguments
components = '--components' in arguments
arguments = [argument for argument in arguments if argument not in ('--frame', '--components')]
if frame:
    slug = 'img-test-frame'
if components:
    slug = 'img-test-components'
if arguments[:1] == ['--slug']:
    slug = arguments[1]
    arguments = arguments[2:]
named = {}
for argument in arguments:
    width, _, rest = argument.partition('=')
    image_id, _, file_name = rest.partition(':')
    media[int(image_id)] = file_name
    if width in ('quadrants', 'portrait', 'small'):
        named[width] = int(image_id)
    else:
        ids.setdefault(int(width), []).append(int(image_id))


def take(width):
    """The next library image of this width: one per image of the page, as a
    browser that holds a file in its cache picks it for another image of the
    page, whatever its `sizes` say."""
    if not ids.get(width):
        sys.exit('Not enough images of %d px: give one per use (see README.md).' % width)
    return ids[width].pop(0)

EXTERNAL = 'https://img.logoipsum.com/243.svg'


def image(section_id, width, image_width, alt):
    return {'id': section_id, 'component': 'cms:image', 'props': {'image': take(image_width), 'alt': alt, 'caption': '', 'width': width}}


def external_image(section_id, width):
    return {'id': section_id, 'component': 'cms:image', 'props': {'image': EXTERNAL, 'alt': 'External logo', 'caption': '', 'width': width}}


def columns(section_id, sources):
    return {'id': section_id, 'component': 'cms:columns', 'props': {'title': '', 'columns': [
        {'image': source, 'image_alt': 'Test image', 'title': 'Column '+str(index + 1), 'text': 'A short text.'} for index, source in enumerate(sources)
    ]}}


def heading(section_id, title):
    return {'id': section_id, 'component': 'cms:richtext', 'props': {'html': '<h2>'+title+'</h2>'}}


def sizes_sections():
    return [
        heading('h-intro', 'Image sizes bench'),
        heading('h-image', 'Image sections'),
        image('image-full-2400', 'full', 2400, 'Full 2400'),
        image('image-full-308', 'full', 308, 'Full 308'),
        image('image-column-1200', 'column', 1200, 'Column 1200'),
        image('image-narrow-2400', 'narrow', 2400, 'Narrow 2400'),
        image('image-narrow-1200', 'narrow', 1200, 'Narrow 1200'),
        image('image-narrow-900', 'narrow', 900, 'Narrow 900'),
        image('image-narrow-308', 'narrow', 308, 'Narrow 308'),
        external_image('image-narrow-external', 'narrow'),
        heading('h-columns', 'Columns'),
        columns('columns-2', [take(1200), take(900)]),
        columns('columns-3', [take(1200), take(900), take(308)]),
        columns('columns-3-external', [EXTERNAL, take(1200), take(2400)]),
        columns('columns-4', [take(2400), take(1200), take(900), take(308)]),
        heading('h-html', 'Free text'),
        {'id': 'html-1200', 'component': 'cms:richtext', 'props': {'html': '<p>A picture in free text.</p><p><img src="/image-library/%d/full/max/0/default.jpg" alt="Free text 1200"></p>' % take(1200)}},
        {'id': 'html-308', 'component': 'cms:richtext', 'props': {'html': '<p><img src="/image-library/%d/full/max/0/default.jpg" alt="Free text 308"></p>' % take(308)}},
        heading('h-site', 'Component of the site'),
        {'id': 'site-scroller', 'component': 'Site:ScrollingImageFrame', 'props': {'image': take(2400), 'alt': 'Scroller 2400', 'direction': 'horizontal', 'speed': 'slow', 'height': 320, 'pause_label': ''}},
    ]

def framed(section_id, image_id, width_px, height_px, fit='cover', focus_x=50, focus_y=50, zoom=100, source=None):
    """A section of the Image component sized by the editor (editor-only props included)."""
    return {'id': section_id, 'component': 'cms:image', 'props': {
        'image': image_id if source is None else source, 'alt': section_id, 'caption': '', 'width': 'custom',
        'width_px': width_px, 'height_px': height_px, 'fit': fit, 'focus_x': focus_x, 'focus_y': focus_y, 'zoom': zoom}}


sections = [] if (frame or components) else sizes_sections()
site_components = []

if frame:
    for needed in ('quadrants', 'portrait', 'small'):
        if needed not in named:
            sys.exit('--frame needs %s=<id>:<file> (see README.md).' % needed)
    quadrants, portrait, small = named['quadrants'], named['portrait'], named['small']
    sections = [
        heading('h-frame', 'Framing bench'),
        framed('fill-0-0', quadrants, 960, 540, focus_x=0, focus_y=0),
        framed('fill-100-100', quadrants, 960, 540, focus_x=100, focus_y=100),
        framed('fill-50-50-zoom-200', quadrants, 960, 540, zoom=200),
        framed('fill-0-0-zoom-200', quadrants, 960, 540, focus_x=0, focus_y=0, zoom=200),
        framed('portrait-box-0-0', quadrants, 600, 800, focus_x=0, focus_y=0),
        framed('portrait-box-100-0', quadrants, 600, 800, focus_x=100, focus_y=0),
        framed('contain', quadrants, 960, 540, fit='contain'),
        framed('image-under-box', small, 960, 540, focus_x=0, focus_y=0),
        framed('external-box', None, 960, 540, focus_x=20, focus_y=80, source=EXTERNAL),
        framed('portrait-original', portrait, 960, 540, focus_x=50, focus_y=0),
        framed('width-only', quadrants, 600, 0),
        {'id': 'legacy', 'component': 'cms:image', 'props': {'image': quadrants, 'alt': 'legacy', 'caption': '', 'width': 'narrow'}},
    ]

if components:
    for needed in ('quadrants', 'portrait'):
        if needed not in named:
            sys.exit('--components needs %s=<id>:<file> (see README.md).' % needed)
    quadrants, portrait = named['quadrants'], named['portrait']
    EXTERNAL_PHOTO = EXTERNAL

    def card(section_id, image_id, **framing):
        """The theme component: the framing is stored beside the picture, as the form writes it."""
        return {'id': section_id, 'component': 'Organisms:ImgTestCard:Base', 'props': {'image': image_id, 'title': section_id, **framing}}

    def photo(section_id, image_id, **framing):
        return {'id': section_id, 'component': 'Site:ImgTestPhoto', 'props': {'photo': image_id, 'title': section_id, **framing}}

    # The quadrants are red, green, blue and yellow, top left to bottom right: the colour of
    # a zone says which part of the picture it is.
    sections = [
        heading('h-theme', 'Component of the theme'),
        card('theme-plain', quadrants),
        card('theme-1x1-red', quadrants, image__proportions='1:1', image__focus_x=0, image__focus_y=0, image__zoom=300),
        card('theme-original-yellow', quadrants, image__proportions='original', image__focus_x=100, image__focus_y=100, image__zoom=200),
        card('theme-16x9-green', quadrants, image__proportions='16:9', image__focus_x=100, image__focus_y=0, image__zoom=200),
        card('theme-external-1x1', EXTERNAL_PHOTO, image__proportions='1:1', image__zoom=300),
        {'id': 'theme-rows', 'component': 'Organisms:ImgTestCard:Base', 'props': {'title': 'theme-rows', 'slides': [
            {'title': 'row-1x1-green', 'picture': quadrants, 'picture__proportions': '1:1', 'picture__focus_x': 100, 'picture__focus_y': 0, 'picture__zoom': 300},
            {'title': 'row-plain', 'picture': quadrants},
        ]}},
        heading('h-site', 'Component of the site'),
        photo('site-plain', quadrants),
        photo('site-16x9-blue', quadrants, photo__proportions='16:9', photo__focus_x=0, photo__focus_y=100, photo__zoom=200),
        photo('site-portrait-16x9', portrait, photo__proportions='16:9', photo__focus_x=50, photo__focus_y=20),
    ]
    site_components = [{
        'code': 'ImgTestPhoto', 'label': 'Image test photo', 'description': 'A photo in a 16:9 box, for the framing bench.',
        'props': [
            {'name': 'photo', 'type': 'image', 'label': 'Photo'},
            {'name': 'title', 'type': 'text', 'label': 'Title'},
        ],
        'sample': {'photo': None, 'title': 'A photo'},
        'template': '<figure class="img-test-photo" style="max-width: 40rem; margin: 0 0 1.5rem;"><img src="{{ props.photo }}" alt="" style="display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover;"><figcaption>{{ props.title }}</figcaption></figure>',
        'css': '', 'provenance': None, 'status': 'ready',
    }]

document = {'version': 1, 'sections': sections}
export = {
    'format': 'thelia-cms', 'version': 3, 'generated_at': '2026-11-04T00:00:00+01:00', 'module_version': '2.10.0',
    'locales': ['fr_FR'], 'page_types': [], 'components': site_components, 'catalogue_contents': [], 'menus': [], 'forms': [], 'settings': {},
    'media': [{'id': image_id, 'file_name': file_name} for image_id, file_name in media.items()],
    'pages': [{
        'uid': slug, 'parent': None, 'position': 90, 'visible': True, 'page_type': 'default', 'model': False, 'fields': [],
        'tag': None, 'tag_color': None, 'tag_text_color': None, 'image_id': None, 'publish_at': None, 'unpublish_at': None,
        'translations': {'fr_FR': {'title': 'Image test '+slug, 'slug': slug, 'fixed_url': None, 'chapo': None, 'description': None,
            'meta_title': None, 'meta_description': None, 'og_title': None, 'og_description': None, 'og_image_id': None, 'twitter_card': None,
            'canonical': None, 'noindex': True, 'nofollow': True, 'fields': []}},
        'contents': {'fr_FR': {'draft_document': json.dumps(document), 'draft_html': None, 'draft_provenance': None, 'published_document': None,
            'published_html': None, 'published_provenance': None, 'published_at': None, 'updated_at': '2026-11-04T00:00:00+01:00'}},
    }],
}
print(json.dumps(export, indent=1))
