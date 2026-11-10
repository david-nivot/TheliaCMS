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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\Admin\MediaUsageFinder;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconCitations;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\IconRenaming;
use TheliaCMS\Media\MediaFilter;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Model\CmsComponent;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Model\CmsItemRevision;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevision;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * Where an icon is cited, what the sheet of its image says of it, and what
 * stops it from being renamed or losing its name.
 */
final class IconCitationsTest extends AdminScreenTestCase
{
    private const string CLEAN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $tokens = [];

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $this->files[] = TheliaLibrary::getImageDirectory().$image->getFileName();
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ($this->tokens as $token) {
            $this->getService(PreparedSvgStore::class)->forget($token);
        }

        parent::tearDown();
    }

    public function testAPageIsCountedOnceWhetherItCitesTheIconInItsDraftItsPublishedVersionOrBoth(): void
    {
        $draftOnly = $this->createPage('Draft only', published: false);
        $publishedOnly = $this->createPage('Published only', published: false);
        $both = $this->createPage('Both', published: false);
        $this->createPage('None', published: false);

        $this->documents($draftOnly, '{"icon":"cms:citee"}', null);
        $this->documents($publishedOnly, null, '{"icon":"cms:citee"}');
        $this->documents($both, '{"icon":"cms:citee"}', '{"icon":"cms:citee"}');

        $citation = $this->getService(IconCitations::class)->of('citee');

        self::assertSame(3, $citation->pages);
        self::assertSame(0, $citation->items);
        self::assertTrue($citation->isCited());
    }

    public function testTheUsageCountOfTheLibraryCountsAnIconCitedByADraft(): void
    {
        $icon = $this->icon('usage-draft');
        $other = $this->icon('usage-draft-2');
        $draftOnly = $this->createPage('Draft citing an icon', published: false);
        $this->documents($draftOnly, '{"icon":"cms:usage-draft-2"}', null);

        $usages = $this->getService(MediaUsageFinder::class);

        self::assertSame([(int) $icon->getId() => 0, (int) $other->getId() => 1], $usages->countsFor([(int) $icon->getId(), (int) $other->getId()]), 'Cited by a draft only, the icon is in use; a name that only starts another is not.');
        self::assertSame([(int) $draftOnly->getId()], array_map(static fn (array $usage): int => (int) $usage['page']->getId(), $usages->pagesUsing((int) $other->getId())));
        self::assertSame(1, $usages->useCount((int) $other->getId()), 'An icon in use is not deleted as unused.');
    }

    public function testAnIconWhoseNameStartsTheNameOfAnotherIsNotCounted(): void
    {
        $page = $this->createPage('Longer name', published: false);
        $this->documents($page, '{"icon":"cms:citee-2"}', '{"icon":"cms:citee-2"}');

        $citations = $this->getService(IconCitations::class);

        self::assertFalse($citations->of('citee')->isCited());
        self::assertSame(1, $citations->of('citee-2')->pages);
    }

    public function testACatalogueItemIsCountedApartAndTwoLanguagesOfAPageAreOnePage(): void
    {
        $page = $this->createPage('Two languages', published: false);
        $this->documents($page, '{"icon":"cms:citee"}', null);
        $this->secondLanguageRow($page, '{"icon":"cms:citee"}');

        $item = (new CmsItemContent())->setItemType('product')->setItemId(987654)->setLocale($this->locale())->setDraftDocument('{"icon":"cms:citee"}');
        $item->save();
        $other = (new CmsItemContent())->setItemType('category')->setItemId(987654)->setLocale($this->locale())->setPublishedDocument('{"icon":"cms:citee"}');
        $other->save();

        $citation = $this->getService(IconCitations::class)->of('citee');

        self::assertSame(1, $citation->pages);
        self::assertSame(2, $citation->items);
    }

    public function testARevisionIsNotACitation(): void
    {
        $page = $this->createPage('Revised', published: false);
        $this->documents($page, null, null);
        (new CmsPageRevision())->setPageId($page->getId())->setLocale($this->locale())->setDocument('{"icon":"cms:citee"}')->save();
        (new CmsItemRevision())->setItemType('product')->setItemId(987655)->setLocale($this->locale())->setDocument('{"icon":"cms:citee"}')->save();

        self::assertFalse($this->getService(IconCitations::class)->of('citee')->isCited());
    }

    public function testThePageInTheBinIsNotCounted(): void
    {
        $page = $this->createPage('Binned', published: false);
        $this->documents($page, '{"icon":"cms:citee"}', null);
        $page->setDeletedAt(new \DateTimeImmutable())->save();

        self::assertFalse($this->getService(IconCitations::class)->of('citee')->isCited());
    }

    public function testAComponentOfTheSiteCitingTheIconInItsTemplateIsCountedBetweenQuotesOnly(): void
    {
        $this->component('single', "<i>{{ cms_icon('cms:citee') }}</i>");
        $this->component('double', '<i>{{ cms_icon("cms:citee") }}</i>', 'ready');
        $this->component('longer', "<i>{{ cms_icon('cms:citee-2') }}</i>");
        $this->component('bare', '<i>cms:citee</i>');
        $this->component('none', null);

        $citations = $this->getService(IconCitations::class);

        self::assertSame(2, $citations->of('citee')->components);
        self::assertSame(0, $citations->of('citee')->pages);
        self::assertTrue($citations->of('citee')->isCited());
        self::assertSame(1, $citations->of('citee-2')->components);
        self::assertFalse($citations->of('citee-3')->isCited());
    }

    public function testAnIconCitedByAComponentIsNeitherRenamedNorStrippedNorDeleted(): void
    {
        $icon = $this->icon('compose');
        $this->component('uses-icon', "{{ cms_icon('cms:compose') }}");
        $id = (int) $icon->getId();
        $renaming = $this->getService(IconRenaming::class);

        self::assertFalse($renaming->rename($id, 'autre')->made);
        self::assertFalse($renaming->removeName($id)->made);
        self::assertSame('compose', $this->getService(IconNames::class)->nameOf($id));

        $usages = $this->getService(MediaUsageFinder::class);
        self::assertSame(['uses-icon'], $usages->componentsUsing($id));
        self::assertSame(1, $usages->useCount($id), 'An icon a component cites is in use: it is not deleted as unused.');
        self::assertSame([$id => 1], $usages->countsFor([$id]));
    }

    public function testTheSheetSaysHowManyComponentsOfTheSiteCiteTheIcon(): void
    {
        $icon = $this->icon('composant');
        $this->component('shows-icon', "{{ cms_icon('cms:composant') }}");

        $screen = $this->screen($this->sheet($icon));
        $block = $screen->filter('[data-testid="cms-media-icon"] [data-testid="cms-icon-citations"]');

        self::assertSame('1', $block->attr('data-components'));
        self::assertStringContainsString(str_replace('%count%', '1', $this->said('It is also cited by %count% component(s) of the site.')), preg_replace('/\s+/', ' ', $block->text()) ?? '');
        self::assertNotNull($screen->filter('[data-testid="cms-icon-rename-submit"]')->attr('disabled'));
        self::assertNotNull($screen->filter('[data-testid="cms-icon-remove-submit"]')->attr('disabled'));
        self::assertNotNull($screen->filter('[data-cms-media-delete] button[type="submit"]')->attr('disabled'), 'The icon is in use by a component: it is not deleted.');
    }

    public function testTheSheetSaysHowManyPagesCiteTheIconAndLocksItsName(): void
    {
        $icon = $this->icon('feuille');
        $page = $this->createPage('Cites it', published: false);
        $this->documents($page, '{"icon":"cms:feuille"}', '{"icon":"cms:feuille"}');
        $item = (new CmsItemContent())->setItemType('product')->setItemId(987656)->setLocale($this->locale())->setDraftDocument('{"icon":"cms:feuille"}');
        $item->save();

        $screen = $this->screen($this->sheet($icon));
        $block = $screen->filter('[data-testid="cms-media-icon"] [data-testid="cms-icon-citations"]');

        self::assertSame(1, $block->count());
        self::assertSame('1', $block->attr('data-pages'));
        self::assertSame('1', $block->attr('data-items'));
        self::assertNotNull($screen->filter('[data-testid="cms-icon-rename-submit"]')->attr('disabled'));
        self::assertNotNull($screen->filter('[data-testid="cms-icon-remove-submit"]')->attr('disabled'));
        self::assertSame(1, $screen->filter('[data-testid="cms-icon-locked"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-icon-citations"]')->count(), 'Said once, on the card of the icon: a cited icon is not deleted.');
        self::assertNotNull($screen->filter('[data-cms-media-delete] button[type="submit"]')->attr('disabled'), 'A cited icon is in use: it is not deleted.');
    }

    public function testAnIconAsksNoAlternativeTextAndKeepsTheOneItHad(): void
    {
        $icon = $this->icon('sans-alt');
        $icon->setLocale($this->locale())->setAlt('Texte d’avant')->save();
        $sheet = $this->sheet($icon);
        $screen = $this->screen($sheet);

        self::assertSame(0, $screen->filter('[name="cms_media[alt]"]')->count(), 'Its text belongs to where it is placed.');
        self::assertSame(0, $screen->filter('[name="cms_media[decorative]"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-media-icon-alt"]')->count(), 'The sheet says why.');

        $library = $this->getService(CmsMediaLibrary::class);
        self::assertContains((int) $icon->getId(), $library->selection($this->locale()));
        self::assertNotContains((int) $icon->getId(), $library->selection($this->locale(), filter: MediaFilter::ToDescribe), 'An icon is never to describe.');

        $form = $screen->filter('form:not([data-cms-media-delete])')->reduce(static fn (Crawler $candidate): bool => $candidate->filter('[name="cms_media[title]"]')->count() > 0)->first()->form();
        $this->client->request('POST', $sheet, $form->getPhpValues(), [], self::SAME_SITE);
        $icon->reload();

        self::assertSame('Texte d’avant', $icon->setLocale($this->locale())->getAlt(), 'Saving the sheet of an icon does not wipe a text it had.');
    }

    public function testAnIconNobodyCitesCanBeRenamedAndLosesItsNameOnRequest(): void
    {
        $icon = $this->icon('ancien');
        $names = $this->getService(IconNames::class);
        $screen = $this->screen($this->sheet($icon));

        self::assertNull($screen->filter('[data-testid="cms-icon-rename-submit"]')->attr('disabled'));
        self::assertSame(0, $screen->filter('[data-testid="cms-icon-locked"]')->count());

        $this->post($screen, 'icon_rename', $this->sheet($icon).'/icon', ['icon_name' => 'cms:nouveau']);
        self::assertSame('nouveau', $names->nameOf((int) $icon->getId()), 'The prefix typed is dropped.');

        $this->post($this->screen($this->sheet($icon)), 'icon_rename', $this->sheet($icon).'/icon', ['icon_name' => 'Pas valide']);
        self::assertSame('nouveau', $names->nameOf((int) $icon->getId()), 'An invalid name changes nothing.');

        $this->post($this->screen($this->sheet($icon)), 'icon_remove', $this->sheet($icon).'/icon/remove', []);
        self::assertNull($names->nameOf((int) $icon->getId()));
    }

    public function testRenamingToTheNameOfAnotherImageIsRefused(): void
    {
        $first = $this->icon('premiere');
        $second = $this->icon('seconde');
        $names = $this->getService(IconNames::class);

        $this->post($this->screen($this->sheet($second)), 'icon_rename', $this->sheet($second).'/icon', ['icon_name' => 'premiere']);

        self::assertSame('seconde', $names->nameOf((int) $second->getId()));
        self::assertSame('premiere', $names->nameOf((int) $first->getId()));
    }

    public function testACitedIconIsNeitherRenamedNorStrippedOfItsNameEvenByAForgedRequest(): void
    {
        $icon = $this->icon('cite');
        $page = $this->createPage('Cites icon', published: false);
        $this->documents($page, null, '{"icon":"cms:cite"}');
        $names = $this->getService(IconNames::class);
        $screen = $this->screen($this->sheet($icon));

        $this->post($screen, 'icon_rename', $this->sheet($icon).'/icon', ['icon_name' => 'autre']);
        self::assertSame('cite', $names->nameOf((int) $icon->getId()));

        $this->post($screen, 'icon_remove', $this->sheet($icon).'/icon/remove', []);
        self::assertSame('cite', $names->nameOf((int) $icon->getId()));
    }

    public function testTheWritesOfTheSheetNeedTheToken(): void
    {
        $icon = $this->icon('sans-jeton');

        self::assertSame(403, $this->send('POST', $this->sheet($icon).'/icon', ['icon_name' => 'autre']));
        self::assertSame(403, $this->send('POST', $this->sheet($icon).'/icon/remove'));
        self::assertSame('sans-jeton', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
    }

    public function testTheScreenOfAReplacementSaysHowManyPagesCiteTheIcon(): void
    {
        $icon = $this->icon('remplace');
        $page = $this->createPage('Cites replaced', published: false);
        $this->documents($page, '{"icon":"cms:remplace"}', null);

        $screen = $this->screen($this->replacementScreenOf($icon));
        $block = $screen->filter('[data-testid="cms-icon-citations"]');

        self::assertSame(1, $block->count());
        self::assertSame('1', $block->attr('data-pages'));
    }

    public function testTheScreenOfAReplacementSaysNothingOfAnIconNobodyCites(): void
    {
        $icon = $this->icon('non-cite');

        $screen = $this->screen($this->replacementScreenOf($icon));

        self::assertSame(0, $screen->filter('[data-testid="cms-icon-citations"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-svg-usage"] .alert-info')->count(), 'No empty box around a sentence that is not said.');
    }

    private function component(string $code, ?string $template, string $status = 'draft'): void
    {
        (new CmsComponent())->setCode($code)->setLabel($code)->setDescription($code)->setTemplate($template)->setStatus($status)->save();
    }

    private function said(string $english): string
    {
        return $this->getService(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans($english, [], \TheliaCMS\TheliaCMS::DOMAIN_NAME, $this->locale());
    }

    private function replacementScreenOf(LibraryImage $icon): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-icon-');
        $this->files[] = $path;
        file_put_contents($path, self::CLEAN);

        $sheet = $this->sheet($icon);
        $form = $this->screen($sheet)->filter('form:not([data-cms-media-delete])')->reduce(
            static fn (Crawler $candidate): bool => $candidate->filter('[name="cms_media[title]"]')->count() > 0,
        )->first()->form();
        // The sheet of an icon asks no alternative text: its own fields are sent as they are.
        $values = $form->getPhpValues();

        $this->client->request('POST', $sheet, $values, ['cms_media' => ['file' => new UploadedFile($path, 'new.svg', 'image/svg+xml', null, true)]], self::SAME_SITE);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#/admin/cms/media/svg/([a-f0-9]{32})#', $location, $matches), $location);
        $this->tokens[] = $matches[1];

        return $location;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(Crawler $screen, string $route, string $path, array $fields): void
    {
        $this->client->request('POST', $path, ['_token' => $this->tokenOfTheForm($screen, $this->parsedPath($screen, $route))] + $fields, [], self::SAME_SITE);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    /** The path of the form of the screen that posts to the route, with the address of the sheet where it stands. */
    private function parsedPath(Crawler $screen, string $route): string
    {
        $suffix = 'icon_rename' === $route ? '/icon' : '/icon/remove';
        $action = $screen->filter('form')->reduce(
            static fn (Crawler $form): bool => str_ends_with((string) parse_url((string) $form->attr('action'), \PHP_URL_PATH), $suffix),
        )->first()->attr('action');

        return (string) parse_url((string) $action, \PHP_URL_PATH);
    }

    private function documents(CmsPage $page, ?string $draft, ?string $published): void
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);
        $content->setDraftDocument($draft)->setPublishedDocument($published)->save();
    }

    private function secondLanguageRow(CmsPage $page, string $draft): void
    {
        $other = 'en_US' === $this->locale() ? 'fr_FR' : 'en_US';
        $row = new \TheliaCMS\Model\CmsPageContent();
        $row->setPageId($page->getId())->setLocale($other)->setDraftDocument($draft)->save();
    }

    private function sheet(LibraryImage $image): string
    {
        return \sprintf('/admin/cms/media/%d', $image->getId());
    }

    private function icon(string $name): LibraryImage
    {
        return $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, $name.'.svg', $this->locale(), $name);
    }
}
