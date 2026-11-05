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
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\TheliaCMS;

/**
 * A page written in a second language from the content screen, then published
 * there, without a visit to its settings screen in that language.
 *
 * The address of a page is only built when its settings are saved, so a page
 * that went online from the content screen alone was published in a language
 * where it had no rewritten address of its own.
 */
final class TranslatedPageAddressTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useAnInstalledFrontTheme();
        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testAPageTranslatedAndPublishedFromTheContentScreenAnswersOnAnAddressOfItsLanguage(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $target = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($target, 'The shop of the tests has a second active language.');
        $locale = $target->getLocale();

        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our services</h2><p>Translated for the test.</p>']],
        ]], \JSON_THROW_ON_ERROR)));

        $url = \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId());
        $form = $this->screen($url)->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $form = $this->screen($url)->filter('[data-testid="cms-content-publish"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $rows = RewritingUrlQuery::create()
            ->filterByView(TheliaCMS::PAGE_VIEW)
            ->filterByViewId((string) $page->getId())
            ->filterByViewLocale($locale)
            ->filterByRedirected(null, Criteria::ISNULL)
            ->find();
        self::assertCount(1, $rows, \sprintf('Published in %s, the page has one rewritten address there.', $locale));

        $address = (string) $rows->getFirst()->getUrl();
        self::assertSame($address, (string) CmsPageQuery::create()->findPk($page->getId())->getRewrittenUrl($locale));
        self::assertSame(200, $this->send('GET', '/'.$address, server: []), 'The address answers.');
        self::assertStringContainsString('Our services', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Nos services', (string) $this->client->getResponse()->getContent(), 'It answers in its own language.');
    }

    /**
     * Every way of publishing goes through the same writer: the settings
     * screen, `thelia_cms:publish` and the content screen all end here.
     */
    public function testAPageWrittenInAnotherLanguageAndPublishedByTheWriterIsGivenItsAddress(): void
    {
        $locale = $this->secondLocale();
        $parent = $this->createPage('Le groupe');
        $writer = $this->getService(CmsPageWriter::class);
        $child = (new CmsPage())->setParent((int) $parent->getId())->setPosition(0)->setVisible(1)->setPageType('default');
        $writer->saveDraft($child, Lang::getDefaultLanguage()->getLocale(), new PageDraft(title: 'Agence de Lyon'));
        // The child is written and published first: it answers at the root of the language until its parent has an address there.
        $writer->saveContent($child, $locale, Document::richText('<h2>Lyon agency</h2>'));
        $writer->publish($child, $locale);
        $childAddress = $this->addressIn($child->getId(), $locale);

        $writer->saveContent($parent, $locale, Document::richText('<h2>The group</h2>'));
        $writer->publish($parent, $locale);
        $parentAddress = $this->addressIn($parent->getId(), $locale);

        self::assertSame('page-'.$parent->getId(), $parentAddress, 'A page with no title in the language is named after its id, as on the settings screen.');
        self::assertSame($parentAddress.'/page-'.$child->getId(), $this->addressIn($child->getId(), $locale), \sprintf('The child, first addressed at "%s", goes under its parent.', $childAddress));
    }

    public function testPublishingAgainLeavesTheAddressAnAuthorChose(): void
    {
        $locale = $this->secondLocale();
        $page = $this->createPage('Nos services');
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveDraft($page, $locale, new PageDraft(title: 'Our services', slug: 'what-we-do'));
        $writer->saveContent($page, $locale, Document::richText('<h2>Our services</h2>'));
        $writer->publish($page, $locale);
        $writer->publish($page, $locale);

        self::assertSame('what-we-do', $this->addressIn($page->getId(), $locale));
    }

    private function secondLocale(): string
    {
        $target = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($target, 'The shop of the tests has a second active language.');

        return $target->getLocale();
    }

    private function addressIn(int $pageId, string $locale): string
    {
        $row = RewritingUrlQuery::create()
            ->filterByView(TheliaCMS::PAGE_VIEW)
            ->filterByViewId((string) $pageId)
            ->filterByViewLocale($locale)
            ->filterByRedirected(null, Criteria::ISNULL)
            ->findOne();

        return null === $row ? '' : (string) $row->getUrl();
    }

    /**
     * Points the shop at a front theme it has: the seed names the one of a
     * stock install, which is not necessarily installed here.
     */
    private function useAnInstalledFrontTheme(): void
    {
        $helper = $this->getService(TemplateHelperInterface::class);

        if (is_dir($helper->getActiveFrontTemplate()->getAbsolutePath())) {
            return;
        }

        $installed = $helper->getList(TemplateDefinition::FRONT_OFFICE);

        if ([] === $installed) {
            self::markTestSkipped('The shop has no front theme installed, so no page can be rendered.');
        }

        ConfigQuery::write('active-front-template', $installed[0]->getName());
    }
}
