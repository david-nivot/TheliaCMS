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
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
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

    /**
     * Sabotage expected to turn it red: drop `kind:` from `MediaQuery::fromRequest()`
     * (every list shows both kinds), or `kind` from `MediaQuery::parameters()`
     * (the pagination, the sheet and the bulk form lose it).
     */
    public function testTheTypeNarrowsTheListAndCombinesWithTheTagTheSearchAndThePages(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $armchair = $this->image('Fauteuil velours', alt: 'Fauteuil en velours');
        $library->retag($armchair, ['Salon']);
        $plain = $this->image('Chaise');
        $clip = Mp4Videos::row($library, $this->locale(), 'Clip velours');
        $library->retag($clip, ['Salon']);
        $other = Mp4Videos::row($library, $this->locale(), 'Clip nu');

        self::assertEqualsCanonicalizing([$armchair->getId(), $plain->getId(), $clip->getId(), $other->getId()], $this->idsOf($this->screen(self::LIST)), 'Both kinds by default.');
        self::assertEqualsCanonicalizing([$clip->getId(), $other->getId()], $this->idsOf($this->screen(self::LIST.'?kind=video')));
        self::assertEqualsCanonicalizing([$armchair->getId(), $plain->getId()], $this->idsOf($this->screen(self::LIST.'?kind=image')));
        self::assertEqualsCanonicalizing([$armchair->getId(), $plain->getId(), $clip->getId(), $other->getId()], $this->idsOf($this->screen(self::LIST.'?kind=film')), 'A type the screen does not offer is no type.');
        self::assertSame([$clip->getId()], $this->idsOf($this->screen(self::LIST.'?kind=video&tag=Salon')));
        self::assertSame([$armchair->getId()], $this->idsOf($this->screen(self::LIST.'?kind=image&tag=Salon')));
        self::assertSame([$clip->getId()], $this->idsOf($this->screen(self::LIST.'?kind=video&search=velours')));
        self::assertSame([$clip->getId()], $this->idsOf($this->screen(self::LIST.'?kind=video&search=velours&tag=Salon&order=title')));
        self::assertSame([], $this->idsOf($this->screen(self::LIST.'?kind=video&tag=Inconnue')));

        $screen = $this->screen(self::LIST.'?kind=video&order=oldest');
        self::assertSame('video', $screen->filter('#cms-media-kind option[selected]')->attr('value'), 'The select shows the type of the address.');
        $count = $screen->filter('[data-testid="cms-media-count"]')->text();
        self::assertStringContainsString('2 video(s)', $count);
        self::assertStringNotContainsString('image(s)', $count, 'The counts of the header follow the type.');
        self::assertStringContainsString('2 matching', $count);
        self::assertStringContainsString('kind=video', (string) $screen->filter('form#cms-media-bulk')->attr('action'), 'The bulk actions lead back to the same list.');
        self::assertStringContainsString('kind=video', (string) $screen->filter('[data-testid^="cms-media-card-"] a')->first()->attr('href'), 'So does a sheet opened from it.');
        self::assertStringContainsString('No video matches.', $this->screen(self::LIST.'?kind=video&tag=Inconnue')->text());
    }

    public function testTheTypeSurvivesThePagesOfTheList(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);

        for ($n = 1; $n <= 50; ++$n) {
            Mp4Videos::row($library, $this->locale(), \sprintf('Clip %02d', $n));
        }

        $this->image('Une image');

        $screen = $this->screen(self::LIST.'?kind=video');
        self::assertCount(48, $this->cards($screen));
        self::assertStringContainsString('kind=video', (string) $screen->filter('[data-testid="cms-media-pages"] a')->last()->attr('href'), 'The next page keeps the type.');
        self::assertCount(2, $this->cards($this->screen(self::LIST.'?kind=video&page=2')), 'Page 2 holds the last two videos, not the image.');
    }

    /**
     * Sabotage expected to turn it red: put `{% if tags is not empty %}` back
     * around the tag select of `media/list.html.twig`.
     */
    public function testTheTagFilterIsShownWhateverTheLibraryTagsAndExplainsHowToTag(): void
    {
        $image = $this->image('Sans étiquette');

        $screen = $this->screen(self::LIST);
        $select = $screen->filter('[data-testid="cms-media-tag"]');

        self::assertSame(1, $select->count(), 'No tag in the library, the filter is there all the same.');
        self::assertNotNull($select->attr('disabled'), 'But it has nothing to offer.');
        self::assertStringContainsString('Add a tag', $screen->filter('[data-testid="cms-media-tag-hint"]')->text(), 'It says how to give a tag.');

        $this->getService(CmsMediaLibrary::class)->retag($image, ['Salon']);
        $screen = $this->screen(self::LIST);

        self::assertNull($screen->filter('[data-testid="cms-media-tag"]')->attr('disabled'));
        self::assertSame(0, $screen->filter('[data-testid="cms-media-tag-hint"]')->count());
    }

    public function testTheFiltersKeepTheirLabelsOnOneLineAndTheBulkActionsSpeakOfFiles(): void
    {
        $this->image('Sans étiquette');

        $screen = $this->screen(self::LIST);
        $classes = explode(' ', (string) $screen->filter('[data-testid="cms-media-filters"]')->attr('class'));

        // Aligned at the bottom, the column of the tag, which carries a hint
        // under its select, would push its label above the others.
        self::assertContains('align-items-start', $classes);
        self::assertNotContains('align-items-end', $classes);
        self::assertSame('With the selected files…', trim($screen->filter('#cms-media-bulk-action option')->first()->text()), 'A video is selected as well as an image.');
        self::assertStringContainsString('selected files', $screen->filter('#cms-media-bulk-title')->text());
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
