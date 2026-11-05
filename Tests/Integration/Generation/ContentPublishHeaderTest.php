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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\BuildsItemTargets;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The Publish button of the header of the content screen, of a page and of a
 * catalogue item: first of the actions, reachable by keyboard when there is
 * nothing to publish and saying why, and a message after publishing that is
 * true of the page.
 */
final class ContentPublishHeaderTest extends AdminScreenTestCase
{
    use BuildsItemTargets;

    public function testPublishIsTheFirstOfTheActionsOfAPage(): void
    {
        $page = $this->createPage('Première action', published: false);

        $actions = $this->screen($this->pageUrl($page->getId()))->filter('.cms-content__actions > *');

        self::assertSame('cms-content-publish', $actions->first()->attr('data-testid'));
    }

    public function testPublishIsTheFirstOfTheActionsOfAnItem(): void
    {
        $content = $this->itemWithADraft();

        $actions = $this->screen($this->itemUrl($content))->filter('.cms-content__actions > *');

        self::assertSame('cms-content-publish', $actions->first()->attr('data-testid'));
    }

    public function testTheButtonOfAnEmptyPageCanBeFocusedAndSaysWhyItDoesNothing(): void
    {
        $page = $this->createPage('Vide', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->delete();

        $this->assertTheButtonSaysWhyItIsOff($this->screen($this->pageUrl($page->getId())));
    }

    public function testTheButtonOfAnEmptyItemCanBeFocusedAndSaysWhyItDoesNothing(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Vide', 'locale' => $this->locale()]);

        $this->assertTheButtonSaysWhyItIsOff($this->screen($this->itemUrl($content)));
    }

    public function testTheButtonOfAPageWithContentIsOnAndDescribedByWhatItDoes(): void
    {
        $page = $this->createPage('Prête', published: false);

        $button = $this->screen($this->pageUrl($page->getId()))->filter('[data-testid="cms-content-publish"] button');

        self::assertNull($button->attr('aria-disabled'));
        self::assertNull($button->attr('disabled'));
        self::assertNotSame('', trim($this->description($button)), 'The hint is read with the button, not only on hover.');
    }

    public function testAReadOnlyProfileSeesNoPublishButton(): void
    {
        $page = $this->createPage('Lecture', published: false);
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));

        self::assertSame(0, $this->screen($this->pageUrl($page->getId()))->filter('[data-testid="cms-content-publish"]')->count());
    }

    public function testAPageOnlineIsSaidOnline(): void
    {
        $page = $this->createPage('En ligne', published: false);

        self::assertSame([$this->say('The page is online.')], $this->publishFromTheContentScreen($page->getId()));
    }

    public function testAHiddenPageIsNotSaidOnline(): void
    {
        $page = $this->createPage('Masquée', published: false);
        $page->setVisible(0)->save();

        $messages = $this->publishFromTheContentScreen($page->getId());

        self::assertSame([$this->say('The content is published, but the page is hidden: show it to put it online.')], $messages);
    }

    public function testAScheduledPageSaysWhenItGoesOnline(): void
    {
        $page = $this->createPage('Programmée', published: false);
        $goesOnline = new \DateTimeImmutable('+10 days');
        $page->setPublishAt($goesOnline)->save();

        $messages = $this->publishFromTheContentScreen($page->getId());

        $locale = $this->getService(TranslatorInterface::class)->getLocale();
        $date = (new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT))->format($goesOnline);
        self::assertNotSame($goesOnline->format('Y-m-d H:i'), $date);
        self::assertSame([$this->say('The content is published: the page goes online on %date%.', ['%date%' => $date])], $messages);
    }

    private function assertTheButtonSaysWhyItIsOff(Crawler $screen): void
    {
        $button = $screen->filter('[data-testid="cms-content-publish"] button');

        self::assertSame(1, $button->count());
        self::assertSame('true', $button->attr('aria-disabled'), 'Announced as unavailable.');
        self::assertNull($button->attr('disabled'), 'A disabled button leaves the tab order: a keyboard user would never learn why.');
        self::assertStringContainsString($this->say('Nothing to publish yet: write the content first.'), $this->description($button));
    }

    private function description(Crawler $button): string
    {
        $id = (string) $button->attr('aria-describedby');
        self::assertNotSame('', $id, 'The button points to its explanation.');

        $described = $button->closest('form')?->filter(\sprintf('[id="%s"]', $id));
        self::assertNotNull($described);
        self::assertSame(1, $described->count(), 'The explanation is on the screen.');

        return $described->text();
    }

    /**
     * @return list<string> the success messages the screen shows after the click
     */
    private function publishFromTheContentScreen(int $pageId): array
    {
        $form = $this->screen($this->pageUrl($pageId))->filter('[data-testid="cms-content-publish"]')->form();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        return $this->screen($this->pageUrl($pageId))->filter('[data-testid="cms-builder-flash-success"]')->each(static fn (Crawler $flash): string => trim($flash->text()));
    }

    private function itemWithADraft(): \Thelia\Model\Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Un contenu', 'locale' => $this->locale()]);
        // A section rendered before any screen leaves the `app` global of Twig without a request.
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()), Document::richText('<h2>Un</h2><p>Un texte.</p>'));

        return $content;
    }

    private function pageUrl(int $id): string
    {
        return \sprintf('/admin/cms/pages/%d/content', $id);
    }

    private function itemUrl(\Thelia\Model\Content $content): string
    {
        return \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());
    }

    /**
     * @param array<string, string> $parameters
     */
    private function say(string $message, array $parameters = []): string
    {
        $translator = $this->getService(TranslatorInterface::class);

        return $translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }
}
