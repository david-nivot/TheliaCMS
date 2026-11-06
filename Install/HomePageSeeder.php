<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TheliaCMS\Install;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Clock\Clock;
use Thelia\Model\LangQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\HighlightsSection;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\TheliaCMS;

/**
 * Creates an example home page on the first activation, published and set as
 * the home page of the site, so that a new site opens on a page of the CMS the
 * editor only has to rewrite.
 *
 * It opens on its own sections, without the title band (`title_band = 0`): a
 * free text, then highlights with no picture, the media library being empty
 * at that point. It is written in the languages the seeder has a text for;
 * in another one `/` keeps the index of the theme, as for any home page not
 * published in a language.
 *
 * The services of the module do not exist yet in the container that activates
 * it: the document is rendered here with the two components it holds, and the
 * draft and the published version carry that HTML. The next save of the page
 * renders it again through the writer, as for any page.
 */
final readonly class HomePageSeeder
{
    /** What the home page says, by locale. */
    public const array PAGES = [
        'fr_FR' => [
            'title' => 'Accueil',
            'heading' => 'Bienvenue dans notre boutique',
            'intro' => 'Présentez ici votre boutique : ce que vous vendez, pour qui, et ce qui vous distingue. Cette page est la page d’accueil du site ; modifiez-la depuis CMS > Pages.',
            'blocks' => [
                ['title' => 'Nouveautés', 'text' => 'Les derniers produits arrivés en boutique.', 'button' => 'Découvrir'],
                ['title' => 'Meilleures ventes', 'text' => 'Les produits que nos clients préfèrent.', 'button' => 'Voir la sélection'],
                ['title' => 'Offres du moment', 'text' => 'Des prix doux, pour un temps limité.', 'button' => 'En profiter'],
            ],
        ],
        'en_US' => [
            'title' => 'Home',
            'heading' => 'Welcome to our shop',
            'intro' => 'Present your shop here: what you sell, to whom, and what sets you apart. This page is the home page of the site; change it from CMS > Pages.',
            'blocks' => [
                ['title' => 'New arrivals', 'text' => 'The latest products in the shop.', 'button' => 'Discover'],
                ['title' => 'Best sellers', 'text' => 'The products our customers like best.', 'button' => 'See the selection'],
                ['title' => 'Current offers', 'text' => 'Gentle prices, for a limited time.', 'button' => 'Take advantage'],
            ],
        ],
    ];

    public function __construct(
        private CmsUrlService $urls = new CmsUrlService(),
        private DocumentRenderer $renderer = new DocumentRenderer(new SectionCatalog([new RichTextSection(), new HighlightsSection()])),
    ) {
    }

    /**
     * @return CmsPage|null the home page, or null when no active language has a text
     */
    public function seed(): ?CmsPage
    {
        // Default language first: it takes the clean address.
        $locales = [];

        foreach (LangQuery::create()->filterByActive(true)->orderByByDefault(Criteria::DESC)->orderByPosition()->find() as $lang) {
            if (isset(self::PAGES[(string) $lang->getLocale()])) {
                $locales[] = (string) $lang->getLocale();
            }
        }

        if ([] === $locales) {
            return null;
        }

        $page = (new CmsPage())
            ->setParent(0)
            ->setPosition(0)
            ->setVisible(1)
            ->setPageType('default')
            ->setTitleBand(0);

        foreach ($locales as $locale) {
            $page->setLocale($locale)->setTitle(self::PAGES[$locale]['title']);
        }

        $page->save();

        foreach ($locales as $locale) {
            $document = self::document(self::PAGES[$locale]);
            $html = $this->renderer->render($document, $locale);
            // One instant for the draft and its publication: the page is not "changed since".
            $now = Clock::get()->now();

            (new CmsPageContent())
                ->setPageId($page->getId())
                ->setLocale($locale)
                ->setDraftDocument($document->toJson())
                ->setDraftHtml($html)
                ->setPublishedDocument($document->toJson())
                ->setPublishedHtml($html)
                ->setPublishedAt($now)
                ->setCreatedAt($now)
                ->setUpdatedAt($now)
                ->save();

            $this->urls->refresh($page, $locale);
        }

        TheliaCMS::setConfigValue('home_page_id', (string) $page->getId());

        return $page;
    }

    /**
     * @param array{title: string, heading: string, intro: string, blocks: list<array{title: string, text: string, button: string}>} $text
     */
    public static function document(array $text): Document
    {
        // No level 1 heading: the template keeps the title of the page as one.
        $intro = '<h2>'.htmlspecialchars($text['heading'], \ENT_QUOTES).'</h2><p>'.htmlspecialchars($text['intro'], \ENT_QUOTES).'</p>';

        return Document::fromArray([
            'version' => 1,
            'sections' => [
                ['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => $intro]],
                ['id' => 'highlights', 'component' => HighlightsSection::NAME, 'props' => ['blocks' => array_map(
                    static fn (array $block): array => [
                        'title' => $block['title'],
                        'text' => $block['text'],
                        'button_label' => $block['button'],
                        'button_url' => '/',
                        'image' => null,
                        'image_alt' => '',
                        'image_mobile' => null,
                        'focus_x' => 50,
                        'focus_y' => 50,
                    ],
                    $text['blocks'],
                )]],
            ],
        ]);
    }
}
