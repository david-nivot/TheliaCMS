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

namespace TheliaCMS\Tests\Integration\Generation;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\ContentQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Editing\TranslationLink;
use TheliaCMS\Content\Section;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\StalePages;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The header of a page translated along with its content: what the screen
 * writes and says of it, and how the header joins the words the translation
 * is followed on.
 */
final class TranslatedHeaderScreenTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testTheTranslationOfAPageWritesItsHeaderAndTheScreenSaysWhatWasWrittenAndWhatWasKept(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->in($target->getLocale(), $page)->setTitle('Mon titre')->save();
        $this->api->answer($this->answer(['title' => 'Our prices', 'summary' => '<p>Advice.</p>', 'description' => '<p>For companies.</p>', 'og_title' => 'Our prices, shared', 'og_description' => 'Advice for companies']));

        $location = $this->translate($page, $target);

        self::assertStringContainsString('header=', $location, 'The outcome travels in the address: a stream cannot leave a flash behind.');
        $held = $this->in($target->getLocale(), $page);
        self::assertSame('Mon titre', $held->getTitle(), 'The title an editor wrote is kept.');
        self::assertSame(['<p>Advice.</p>', '<p>For companies.</p>', 'Our prices, shared', 'Advice for companies'], [$held->getChapo(), $held->getDescription(), $held->getOgTitle(), $held->getOgDescription()]);
        self::assertSame('mon-titre', $held->getRewrittenUrl($target->getLocale()), 'The language had no address: it gets one from the title it holds.');

        $source = $this->in($this->locale(), $page);
        self::assertSame(['Nos tarifs', '<p>Du conseil.</p>'], [$source->getTitle(), $source->getChapo()], 'The source header is untouched.');

        $notices = $this->notices($location);
        self::assertContains('Header written in this language: summary, description, sharing title, sharing description, address.', $notices);
        self::assertContains('Kept as it was: title.', $notices, 'What the editor wrote is said to be kept, apart from what was written.');
        self::assertNotContains('Header written in this language: title, summary, description, sharing title, sharing description, address.', $notices);
    }

    public function testNothingIsSaidWhenTheModelAnsweredNoHeader(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->api->answer($this->answer([]));

        $location = $this->translate($page, $target);

        self::assertStringNotContainsString('header=', $location);
        self::assertNull($this->in($target->getLocale(), $page)->getTitle() ?: null, 'Nothing was proposed, nothing is written.');
        self::assertSame([], array_values(array_filter($this->notices($location), static fn (string $line): bool => str_contains($line, 'Header written'))));
    }

    public function testTheHeaderOfAPageWithoutOneIsNotAskedAndTheTranslationStaysWhatItWas(): void
    {
        $target = $this->otherLanguage();
        $page = $this->createPage('Nos tarifs', published: false);
        CmsPageQuery::create()->findPk($page->getId())?->setLocale($this->locale())->setTitle('')->save();
        $this->api->answer($this->answer([]));

        $this->translate($page, $target);

        self::assertStringNotContainsString('The page header in', $this->api->requests[0]->messages[0]->content, 'A source with no header is not asked about.');
        self::assertNotContains('title', array_keys($this->api->requests[0]->outputSchema?->schema['properties'] ?? []));
    }

    public function testTheTranslationOfAnItemWritesNoHeaderAndSaysNothingOfIt(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $item = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $target = $this->otherLanguage();
        $this->getService(ContentWriter::class)->saveContent(
            $this->getService(ContentTargets::class)->forItemKey(CatalogueItemType::Content, (int) $item->getId(), $this->locale()),
            new Document([new Section('content', 'cms:richtext', ['html' => '<h2>Notre méthode</h2>'])]),
        );
        $this->api->answer($this->answer(['title' => 'Our method', 'summary' => '<p>Three steps.</p>', 'description' => '<p>Detail.</p>'], 'content', '<h2>Our method</h2>'));

        $screen = $this->screen(\sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $item->getId(), $target->getId()));
        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        $location = (string) $this->client->getResponse()->headers->get('Location');

        self::assertStringNotContainsString('header=', $location);
        self::assertStringNotContainsString('The page header in', $this->api->requests[0]->messages[0]->content, 'The header of an item is the core\'s: not asked.');
        self::assertSame([], array_values(array_filter($this->notices($location), static fn (string $line): bool => str_contains($line, 'Header written'))));
        self::assertNull(ContentQuery::create()->findPk($item->getId())?->setLocale($target->getLocale())->getTitle() ?: null, 'Nothing is written in the core.');
    }

    public function testWithoutTheRightToGenerateTheTranslationIsRefusedAndWritesNoHeader(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $token = $this->tokenOfTheForm($this->screen($this->url($page, $target)), \sprintf('/admin/cms/pages/%d/content/translate', $page->getId()));
        $factory = new FixtureFactory($this->getPropelConnection());
        $this->logInAs($factory->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $this->api->answer($this->answer(['title' => 'Our prices']));

        self::assertSame(403, $this->send('POST', \sprintf('/admin/cms/pages/%d/content/translate?edit_language_id=%d', $page->getId(), $target->getId()), ['_token' => $token, 'cms_translate' => ['source' => $this->locale()]]));
        self::assertNull($this->in($target->getLocale(), $page)->getTitle() ?: null);
        self::assertSame([], $this->api->requests, 'Nothing was sent to the API.');
    }

    public function testTheOutcomeIsReadInFrench(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->in($target->getLocale(), $page)->setChapo('<p>Mon résumé.</p>')->save();
        $this->api->answer($this->answer(['title' => 'Our prices', 'summary' => '<p>Advice.</p>', 'og_title' => 'Shared']));

        $location = $this->translate($page, $target);

        $notices = $this->notices($location.'&lang=fr_FR');
        self::assertContains('L’en-tête de la page est écrit dans cette langue : titre, titre de partage, adresse.', $notices);
        self::assertContains('Conservé tel quel : résumé.', $notices);
    }

    public function testTheReasonsAnAddressWasNotDerivedAreSaidInBothLanguages(): void
    {
        $target = $this->otherLanguage();
        $reserved = $this->pageWithAHeader('Chercher');
        $this->api->answer($this->answer(['title' => 'Search']));
        $location = $this->translate($reserved, $target);

        self::assertContains('No address was derived: “Search” is a reserved path. Set one in the settings of the page.', $this->notices($location));
        self::assertContains('Aucune adresse n’a été déduite : « Search » est un chemin réservé. Choisissez-en une dans les réglages de la page.', $this->notices($location.'&lang=fr_FR'));

        $parent = $this->pageWithAHeader('Nos ateliers');
        $child = $this->pageWithAHeader('Poterie', (int) $parent->getId());
        $this->api->answer($this->answer(['title' => 'Pottery']));
        $location = $this->translate($child, $target);

        self::assertContains('The parent page has no address in this language yet: this page answers at the root until it has one.', $this->notices($location.'&lang=en_US'), 'The language chosen once stays: it is named again.');
        self::assertContains('La page parente n’a pas encore d’adresse dans cette langue : cette page répond à la racine tant qu’elle n’en a pas.', $this->notices($location.'&lang=fr_FR'));
    }

    public function testTheHeaderOfTheSourceIsFollowedLikeASection(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->api->answer($this->answer([]));
        $this->translate($page, $target);

        self::assertFalse($this->link($page, $target)->isStale(), 'Just written, the translation is up to date.');

        foreach (['title' => 'Nos tarifs, revus', 'chapo' => '<p>Autre résumé.</p>', 'description' => '<p>Autre description.</p>', 'og_title' => 'Autre partage', 'og_description' => 'Autre texte de partage'] as $column => $value) {
            $before = (string) $this->in($this->locale(), $page)->{'get'.str_replace('_', '', ucwords($column, '_'))}();
            $this->in($this->locale(), $page)->{'set'.str_replace('_', '', ucwords($column, '_'))}($value)->save();

            $link = $this->link($page, $target);
            self::assertTrue($link->isStale(), \sprintf('A change of the %s of the source header makes the translation stale.', $column));
            self::assertTrue($link->header);
            self::assertSame([], $link->changed, 'The header is not a section.');
            self::assertSame([], $link->removed);

            $this->in($this->locale(), $page)->{'set'.str_replace('_', '', ucwords($column, '_'))}($before)->save();
            self::assertFalse($this->link($page, $target)->isStale(), \sprintf('The %s put back is the one that was translated.', $column));
        }

        $this->in($this->locale(), $page)->setMetaTitle('Autre titre pour Google')->setSlug('autre-adresse')->save();
        self::assertFalse($this->link($page, $target)->isStale(), 'The meta fields and the address are not words of the header that is translated.');
    }

    public function testTheBannerNamesThePageHeaderAndTheListAndTheDashboardCountIt(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->api->answer($this->answer([]));
        $this->translate($page, $target);
        $this->in($this->locale(), $page)->setTitle('Nos tarifs, revus')->save();

        $screen = $this->screen($this->url($page, $target));

        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-stale"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-translation-header"]')->count(), 'The banner names the header of the page, not a section.');
        self::assertStringContainsString('The header of the page', $screen->filter('[data-testid="cms-content-translation-header"]')->text());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-retranslate"]')->count());
        self::assertArrayHasKey((int) $page->getId(), $this->getService(StalePages::class)->ids($target->getLocale(), [(int) $page->getId()]), 'The list of pages says it too.');

        $french = $this->screen($this->url($page, $target).'&lang=fr_FR');
        self::assertStringContainsString('L’en-tête de la page', $french->filter('[data-testid="cms-content-translation-header"]')->text());

        $this->in($this->locale(), $page)->setTitle('Nos tarifs')->save();
        self::assertSame(0, $this->screen($this->url($page, $target))->filter('[data-testid="cms-content-translation-header"]')->count());
    }

    public function testATranslationRecordedBeforeTheHeaderWasFollowedIsNotStaleForItUntilItIsMarkedUpToDate(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->api->answer($this->answer([]));
        $this->translate($page, $target);
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($target->getLocale())->findOne();
        $recorded = json_decode((string) $content?->getSourceFingerprint(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('header', $recorded, 'A translation written now follows the header.');
        // What F6 stored: the sections alone.
        $content->setSourceFingerprint(json_encode(['sections' => $recorded['sections']], \JSON_THROW_ON_ERROR))->save();

        $this->in($this->locale(), $page)->setTitle('Nos tarifs, revus')->setChapo('<p>Autre.</p>')->save();
        self::assertFalse($this->link($page, $target)->isStale(), 'An older record has no header to compare: every existing translation is not stale at once.');
        self::assertSame([], $this->getService(StalePages::class)->ids($target->getLocale(), [(int) $page->getId()]));
        self::assertSame(0, $this->screen($this->url($page, $target))->filter('[data-testid="cms-content-translation-stale"]')->count());

        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([new Section('content', 'cms:richtext', ['html' => '<h1>Nos tarifs</h1><p>Changé.</p>'])]));
        self::assertTrue($this->link($page, $target)->isStale(), 'A section of an old record is still followed.');
        self::assertFalse($this->link($page, $target)->header);

        $screen = $this->screen($this->url($page, $target));
        $form = $screen->filter('[data-testid="cms-content-translation-current"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertFalse($this->link($page, $target)->isStale());

        $this->in($this->locale(), $page)->setTitle('Encore un autre titre')->save();
        self::assertTrue($this->link($page, $target)->header, 'Marked up to date, the translation follows the header from then on.');
    }

    public function testTheCopyOfATranslationFollowsTheHeaderOfTheCopyOfItsSource(): void
    {
        $target = $this->otherLanguage();
        $page = $this->pageWithAHeader();
        $this->api->answer($this->answer([]));
        $this->translate($page, $target);

        $copy = $this->getService(CmsPageWriter::class)->duplicate($page, $this->locale(), 'copie');

        self::assertFalse($this->link($copy, $target)->isStale(), 'The title of a copy carries a suffix: it is not a change of the header the translation was written from.');

        $this->in($this->locale(), $copy)->setTitle('Un autre titre')->save();
        self::assertTrue($this->link($copy, $target)->header, 'It follows the header of its own source from then on.');
        self::assertFalse($this->link($page, $target)->isStale(), 'The original is not touched by an edit of its copy.');
    }

    public function testAnItemFollowsNoHeader(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $item = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $target = $this->otherLanguage();
        $this->getService(ContentWriter::class)->saveContent(
            $this->getService(ContentTargets::class)->forItemKey(CatalogueItemType::Content, (int) $item->getId(), $this->locale()),
            new Document([new Section('content', 'cms:richtext', ['html' => '<h2>Notre méthode</h2>'])]),
        );
        $this->api->answer($this->answer([], 'content', '<h2>Our method</h2>'));
        $screen = $this->screen(\sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $item->getId(), $target->getId()));
        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = $this->getService(ContentTargets::class)->forItemKey(CatalogueItemType::Content, (int) $item->getId(), $target->getLocale())->row();

        self::assertNull(TranslationFreshness::decodeHeader($row->followedSource()['fingerprint'] ?? null), 'An item records the sections only, as it always did.');
    }

    // — helpers —

    private function pageWithAHeader(string $title = 'Nos tarifs', int $parent = 0): CmsPage
    {
        $page = $this->createPage($title, published: false);
        $page->setParent($parent)->save();
        $this->in($this->locale(), $page)->setChapo('<p>Du conseil.</p>')->setDescription('<p>Pour les PME.</p>')->setOgTitle('Nos tarifs, partagés')->setOgDescription('Du conseil pour les PME')->save();

        return $page;
    }

    private function in(string $locale, CmsPage $page): CmsPage
    {
        return (CmsPageQuery::create()->findPk($page->getId()) ?? throw new \LogicException('The page exists.'))->setLocale($locale);
    }

    /**
     * @param array<string, mixed> $header
     */
    private function answer(array $header, string $id = 'content', string $html = '<h1>Our prices</h1><p>Written for the test.</p>'): \Thelia\Domain\Ai\AiResponse
    {
        return FakeAiGateway::response(json_encode([
            'version' => 1,
            'sections' => [['id' => $id, 'component' => 'cms:richtext', 'props' => ['html' => $html]]],
            ...$header,
        ], \JSON_THROW_ON_ERROR));
    }

    private function translate(CmsPage $page, Lang $target): string
    {
        $screen = $this->screen($this->url($page, $target));
        $form = $screen->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * What the screen says after the call: the notices, message and details.
     *
     * @return list<string>
     */
    private function notices(string $location): array
    {
        $lines = [];

        $this->screen($location)->filter('.cms-toast .cms-toast__message p')->each(static function (Crawler $line) use (&$lines): void {
            $lines[] = trim($line->text());
        });

        return $lines;
    }

    private function url(CmsPage $page, Lang $lang): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $lang->getId());
    }

    private function link(CmsPage $page, Lang $target): TranslationLink
    {
        $targets = $this->getService(ContentTargets::class);

        return $this->getService(TranslationFreshness::class)->link($targets->forPage($page, $target->getLocale()), static fn (string $source) => $targets->forPage($page, $source)) ?? throw new \LogicException('The translation follows a source.');
    }

    private function otherLanguage(): Lang
    {
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');

        return $other;
    }
}
