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

namespace TheliaCMS\Generation\Admin;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Partial\PartialSection;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\TheliaCMS;

/**
 * The components a section is added from by hand, as an editor reads them:
 * grouped by what they are for, each with an icon, a name and one line.
 *
 * The description of a component is written for the generator, in English
 * for the components of the theme; it stays as it is. The components the
 * module and Flexy ship get words of their own here, the others keep their
 * label and description under the icon of their family.
 */
final readonly class SectionChoices
{
    private const string TEXT = 'text';
    private const string SHOP = 'shop';
    private const string TRUST = 'trust';
    private const string SITE = 'site';
    private const string BLOCKS = 'blocks';
    private const string OTHER = 'other';

    /** The families, in the order the dialog lists them. */
    private const array FAMILIES = [
        self::TEXT => 'Text and media',
        self::SHOP => 'Shop',
        self::TRUST => 'Reviews and reassurance',
        self::SITE => 'Content of the site',
        self::BLOCKS => 'Blocks of the site',
        self::OTHER => 'Other components',
    ];

    /**
     * In the order each family lists them; the others follow, as the catalogue gives them.
     *
     * @var array<string, array{family: string, icon: string, label: string, summary: string}>
     */
    private const array KNOWN = [
        'cms:richtext' => ['family' => self::TEXT, 'icon' => 'text-paragraph', 'label' => 'Free text', 'summary' => 'Paragraphs, headings and lists.'],
        'cms:image' => ['family' => self::TEXT, 'icon' => 'image', 'label' => 'Image', 'summary' => 'One picture of the library, with an optional caption.'],
        'cms:columns' => ['family' => self::TEXT, 'icon' => 'layout-three-columns', 'label' => 'Columns', 'summary' => 'Two to four columns: an image, a heading and a short text each.'],
        'cms:highlights' => ['family' => self::TEXT, 'icon' => 'layout-sidebar-inset', 'label' => 'Highlights', 'summary' => 'One to three blocks: a picture with a title, a catchphrase and a button each.'],
        'Organisms:VideoPlayer:Base' => ['family' => self::TEXT, 'icon' => 'play-btn', 'label' => 'Video', 'summary' => 'A YouTube, Vimeo or Dailymotion video.'],
        'Layouts:Subheader:Base' => ['family' => self::TEXT, 'icon' => 'type-h1', 'label' => 'Heading band', 'summary' => 'A title with an optional breadcrumb.'],
        'Layouts:CrossSelling:Base' => ['family' => self::SHOP, 'icon' => 'grid-3x2-gap', 'label' => 'Row of products', 'summary' => 'Products of the shop as cards side by side, under a heading.'],
        'Layouts:ProductCategory:Base' => ['family' => self::SHOP, 'icon' => 'diagram-3', 'label' => 'Main categories', 'summary' => 'The three main categories of the shop as cards.'],
        'Organisms:CategoryCard:Base' => ['family' => self::SHOP, 'icon' => 'folder2-open', 'label' => 'Category card', 'summary' => 'One category with its picture, its name and a button.'],
        'Layouts:Review:Base' => ['family' => self::TRUST, 'icon' => 'chat-quote', 'label' => 'Row of reviews', 'summary' => 'Customer reviews side by side, under a heading.'],
        'Organisms:ReviewCard:Base' => ['family' => self::TRUST, 'icon' => 'chat-square-quote', 'label' => 'Review card', 'summary' => 'One customer review, with its score out of five.'],
        'Layouts:ReinsuranceModule:Base' => ['family' => self::TRUST, 'icon' => 'shield-check', 'label' => 'Reassurance', 'summary' => 'Delivery, returns, payment: an icon and a short line each.'],
        'Organisms:ArticleCard:Base' => ['family' => self::SITE, 'icon' => 'newspaper', 'label' => 'Article card', 'summary' => 'A news item as a card: title, date, excerpt and link.'],
        'Partial:cms-form' => ['family' => self::SITE, 'icon' => 'ui-checks', 'label' => 'Form', 'summary' => 'A form of the site, shown as it is on every visit.'],
        'Partial:cms-page-list' => ['family' => self::SITE, 'icon' => 'list-ul', 'label' => 'Pages of a type', 'summary' => 'The pages of a type, listed as they are on every visit.'],
    ];

    public function __construct(
        private SectionCatalog $catalog,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{label: string, choices: list<array{name: string, icon: string, label: string, summary: string}>}>
     */
    public function groups(): array
    {
        $choices = array_fill_keys(array_keys(self::FAMILIES), []);

        foreach ($this->catalog->offered() as $name => $definition) {
            $known = self::KNOWN[$name] ?? null;
            $family = $known['family'] ?? self::familyOf($name);

            $choices[$family][] = [
                'name' => $name,
                'icon' => $known['icon'] ?? self::iconOf($family),
                'label' => null !== $known ? $this->trans($known['label']) : $definition->label(),
                // A component the module does not know keeps its own words,
                // translated when the site ships a key for them.
                'summary' => $this->trans(null !== $known ? $known['summary'] : $definition->description()),
            ];
        }

        $groups = [];
        $order = array_flip(array_keys(self::KNOWN));

        foreach ($choices as $family => $inFamily) {
            usort($inFamily, static fn (array $one, array $other): int => ($order[$one['name']] ?? \PHP_INT_MAX) <=> ($order[$other['name']] ?? \PHP_INT_MAX));

            if ([] !== $inFamily) {
                $groups[] = ['label' => $this->trans(self::FAMILIES[$family]), 'choices' => $inFamily];
            }
        }

        return $groups;
    }

    /**
     * The name an editor reads for a section of this component: the words
     * of the dialog when the component is one the module knows.
     */
    public function label(string $name, string $fallback): string
    {
        return isset(self::KNOWN[$name]) ? $this->trans(self::KNOWN[$name]['label']) : $fallback;
    }

    private static function familyOf(string $name): string
    {
        return match (true) {
            str_starts_with($name, PartialSection::PREFIX) => self::SITE,
            str_starts_with($name, SiteComponent::PREFIX) => self::BLOCKS,
            default => self::OTHER,
        };
    }

    private static function iconOf(string $family): string
    {
        return match ($family) {
            self::SITE => 'arrow-repeat',
            self::BLOCKS => 'bricks',
            default => 'puzzle',
        };
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
