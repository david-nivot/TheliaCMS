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

namespace TheliaCMS\Tests\Integration\Media;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\LangQuery;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * The list of the media holds a copied catalogue: pages of it, what is still
 * to describe, what no page uses, a tag, an order, a search beyond the title.
 */
final class MediaListScreenTest extends AdminScreenTestCase
{
    private const string LIST = '/admin/cms/media';

    public function testTheLibraryIsListedByPagesAndAPageTooFarShowsTheLastOne(): void
    {
        for ($n = 1; $n <= 50; ++$n) {
            $this->image(\sprintf('Image %02d', $n));
        }

        self::assertCount(48, $this->cards($this->screen(self::LIST)));
        self::assertCount(2, $this->cards($this->screen(self::LIST.'?page=2')));
        self::assertCount(2, $this->cards($this->screen(self::LIST.'?page=9')), 'An address kept from a larger library shows the last page.');
        self::assertSame(1, $this->screen(self::LIST)->filter('[data-testid="cms-media-pages"]')->count());
    }

    public function testToDescribeListsWhatSaysNothingInTheLanguageEdited(): void
    {
        $described = $this->image('Décrite', alt: 'Un fauteuil vert');
        $undescribed = $this->image('Sans texte');
        $decorative = $this->image('Décorative', decorative: true);
        $elsewhere = $this->image('Décrite ailleurs', alt: 'A green armchair', locale: $this->otherLocale());

        self::assertSame(
            [$undescribed->getId(), $elsewhere->getId()],
            $this->idsOf($this->screen(self::LIST.'?filter=to-describe&order=oldest')),
            'An image described in another language only says nothing in this one.',
        );

        $counts = $this->getService(CmsMediaLibrary::class)->counts($this->locale());
        self::assertSame(2, $counts['undescribed'], 'The count says what the filter lists.');
        self::assertSame([$decorative->getId()], $this->idsOf($this->screen(self::LIST.'?filter=decorative')));
        self::assertNotContains($described->getId(), $this->idsOf($this->screen(self::LIST.'?filter=to-describe')));
    }

    public function testTheIconsFilterListsTheImagesThatHaveAnIconNameAndTheCardsShowIt(): void
    {
        $icon = $this->image('Une icône');
        $this->image('Une photo');
        $this->getService(IconNames::class)->assign((int) $icon->getId(), 'une-icone');

        $list = $this->screen(self::LIST.'?filter=icons');

        self::assertSame([$icon->getId()], $this->idsOf($list));
        self::assertSame('cms:une-icone', trim($list->filter('[data-testid="cms-media-icon-name"]')->text()));
        self::assertSame(1, $this->screen(self::LIST)->filter('[data-testid="cms-media-icon-name"]')->count(), 'Only the icon carries a name on the unfiltered list.');
        self::assertSame(1, $this->screen(self::LIST)->filter('[data-testid="cms-media-icon-badge"]')->count(), 'Only the icon is marked on its picture.');
        $rows = $this->screen(self::LIST.'?view=list');
        self::assertSame(1, $rows->filter('[data-testid="cms-media-row-icon-'.$icon->getId().'"]')->count(), 'The list marks the icon too.');
        self::assertSame(0, $rows->filter('[data-testid="cms-media-row-alt-'.$icon->getId().'"] .badge')->count(), 'An icon is neither said described nor to describe: its text depends on where it is placed.');
        self::assertSame('cms:une-icone', trim($this->screen(self::LIST.'/'.$icon->getId())->filter('[data-testid="cms-media-icon-name"] code')->text()), 'The sheet shows it, read only.');
    }

    public function testUnusedLeavesOutTheImageAPageShows(): void
    {
        $used = $this->image('Utilisée');
        $unused = $this->image('Inutilisée');
        $this->createPage('Page avec image')->setImageId($used->getId())->save();

        self::assertSame([$unused->getId()], $this->idsOf($this->screen(self::LIST.'?filter=unused')));
    }

    public function testATagNarrowsTheListAndIsOfferedWithItsCount(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $armchair = $this->image('Fauteuil');
        $library->retag($armchair, ['Fauteuils']);
        $this->image('Chaise');

        $screen = $this->screen(self::LIST.'?tag=Fauteuils');

        self::assertSame([$armchair->getId()], $this->idsOf($screen));
        self::assertSame('Fauteuils (1)', trim($screen->filter('#cms-media-tag option[selected]')->text()));
        self::assertSame([], $this->idsOf($this->screen(self::LIST.'?tag=Inconnue')), 'A tag nobody carries lists nothing.');
    }

    public function testTheListIsOrderedAsAsked(): void
    {
        $small = $this->image('Banc', fileSize: 1000);
        $large = $this->image('Armoire', fileSize: 9000);
        $middle = $this->image('Canapé', fileSize: 5000);

        self::assertSame([$middle->getId(), $large->getId(), $small->getId()], $this->idsOf($this->screen(self::LIST)), 'Newest first by default.');
        self::assertSame([$large->getId(), $small->getId(), $middle->getId()], $this->idsOf($this->screen(self::LIST.'?order=title')));
        self::assertSame([$large->getId(), $middle->getId(), $small->getId()], $this->idsOf($this->screen(self::LIST.'?order=weight')));
        self::assertSame([$middle->getId(), $large->getId(), $small->getId()], $this->idsOf($this->screen(self::LIST.'?order=sideways')), 'An order the screen does not offer is the default one.');
    }

    public function testTheSearchReadsTheDescriptionAndTheCaption(): void
    {
        $byAlt = $this->image('Modèle 12', alt: 'Fauteuil en velours vert');
        $byCaption = $this->image('Modèle 13', caption: 'Collection velours');
        $this->image('Modèle 14', alt: 'Chaise en chêne');

        self::assertSame([$byAlt->getId(), $byCaption->getId()], $this->idsOf($this->screen(self::LIST.'?search=velours&order=oldest')));
    }

    /**
     * Opened from a filtered list, an image leads back to that list: from its
     * sheet, and once deleted.
     */
    public function testAnImageOpenedFromAFilteredListLeadsBackToIt(): void
    {
        $image = $this->image('Fauteuil à reprendre');
        $this->getService(CmsMediaLibrary::class)->retag($image, ['Fauteuils']);

        $sheet = $this->cards($this->screen(self::LIST.'?tag=Fauteuils&order=title'))->filter('a')->first()->attr('href');
        self::assertIsString($sheet);
        self::assertStringContainsString('tag=Fauteuils', $sheet);
        self::assertStringContainsString('order=title', $sheet);

        $screen = $this->screen($sheet);
        $back = $screen->filter('[data-testid="cms-media-back"]')->attr('href');
        self::assertStringContainsString('tag=Fauteuils', (string) $back);
        self::assertStringContainsString('order=title', (string) $back);

        $action = (string) $screen->filter('form[action*="/delete"]')->attr('action');
        self::assertSame(302, $this->send('POST', $action, ['_token' => $this->tokenOfTheForm($screen, (string) parse_url($action, \PHP_URL_PATH))]));
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('tag=Fauteuils', $location, 'Deleted, the image leads back to the list it came from.');
        self::assertStringContainsString('order=title', $location);
    }

    public function testTheListDrawsARowPerFileWithWhatTheCardsShowOnHover(): void
    {
        $described = $this->image('Rangée décrite', alt: 'Un fauteuil bleu', fileSize: 2048);
        $missing = $this->image('Rangée sans texte');
        $decorative = $this->image('Rangée décorative', decorative: true);

        $screen = $this->screen(self::LIST.'?view=list');

        self::assertCount(0, $this->cards($screen), 'The list draws no card.');
        $row = $screen->filter(\sprintf('[data-testid="cms-media-row-%d"]', $described->getId()));
        self::assertSame(1, $row->count(), 'Each file has its row.');
        self::assertStringContainsString('800 × 600', $row->text(), 'The dimensions are in a column.');
        self::assertStringContainsString('2 kB', $row->text(), 'The weight is in a column.');
        self::assertSame(1, $row->filter('input[data-cms-media-select][form="cms-media-bulk"]')->count(), 'The row is selected for the bulk actions, as a card.');
        self::assertSame('Described', trim($this->altOf($screen, $described)));
        self::assertStringContainsString('No alternative text', $this->altOf($screen, $missing));
        self::assertSame('Decorative', trim($this->altOf($screen, $decorative)));
    }

    public function testEveryLinkOfTheListKeepsTheView(): void
    {
        for ($n = 1; $n <= 50; ++$n) {
            $this->image(\sprintf('Vue %02d', $n));
        }

        $screen = $this->screen(self::LIST.'?view=list&order=title');

        self::assertSame('true', $screen->filter('[data-testid="cms-media-view-list"]')->attr('aria-current'));
        self::assertStringNotContainsString('view=', (string) $screen->filter('[data-testid="cms-media-view-grid"]')->attr('href'), 'The grid is the plain address.');
        self::assertStringContainsString('order=title', (string) $screen->filter('[data-testid="cms-media-view-grid"]')->attr('href'), 'Switching the view keeps the order.');
        self::assertSame('list', $screen->filter('[data-testid="cms-media-filters"] input[name="view"]')->attr('value'), 'Filtering keeps the view.');
        self::assertStringContainsString('view=list', (string) $screen->filter('[data-testid="cms-media-pages"] a')->last()->attr('href'), 'The next page keeps the view.');
        self::assertStringContainsString('view=list', (string) $screen->filter('form#cms-media-bulk')->attr('action'), 'The bulk actions lead back to the view.');

        $sheet = (string) $screen->filter('[data-testid^="cms-media-row-"] a')->first()->attr('href');
        self::assertStringContainsString('view=list', $sheet);
        self::assertStringContainsString('view=list', (string) $this->screen($sheet)->filter('[data-testid="cms-media-back"]')->attr('href'), 'An image opened from the list leads back to the list.');
    }

    private function altOf(Crawler $screen, LibraryImage $image): string
    {
        return $screen->filter(\sprintf('[data-testid="cms-media-row-alt-%d"]', $image->getId()))->text();
    }

    /**
     * @return list<int>
     */
    private function idsOf(Crawler $screen): array
    {
        return array_values(array_map(
            static fn (string $testId): int => (int) substr($testId, \strlen('cms-media-card-')),
            $this->cards($screen)->each(static fn (Crawler $card): string => (string) $card->attr('data-testid')),
        ));
    }

    private function cards(Crawler $screen): Crawler
    {
        return $screen->filter('[data-testid^="cms-media-card-"]');
    }

    /**
     * An image row without a file: the list reads the row, and a thumbnail
     * that does not load is no concern of these tests.
     */
    private function image(string $title, ?string $alt = null, ?string $caption = null, bool $decorative = false, ?string $locale = null, int $fileSize = 1000): LibraryImage
    {
        $image = (new LibraryImage())
            ->setFileName(bin2hex(random_bytes(6)).'.jpg')
            ->setWidth(800)
            ->setHeight(600)
            ->setFileSize($fileSize)
            ->setDecorative($decorative ? 1 : 0);
        $image->setLocale($locale ?? $this->locale())->setTitle($title)->setAlt($alt)->setCaption($caption);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    private function otherLocale(): string
    {
        $other = LangQuery::create()->filterByActive(1)->filterByLocale($this->locale(), Criteria::NOT_EQUAL)->findOne();

        if (null === $other) {
            self::markTestSkipped('The shop has a single language.');
        }

        return (string) $other->getLocale();
    }
}
