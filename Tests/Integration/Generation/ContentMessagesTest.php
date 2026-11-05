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
use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ItemContentWriter;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Install\LegalPageTemplates;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The messages the content screen gives, read in French: the page and the
 * catalogue item have each their own keys, and their French is not always
 * the same sentence (a "brief" on one, a "consigne" on the other). A message
 * factored into one key would lose the translation of the other without any
 * other test noticing.
 */
final class ContentMessagesTest extends AdminScreenTestCase
{
    private const string FRENCH = '?lang=fr_FR';

    public function testWhatAPageHoldsNothingOfCannotBeChanged(): void
    {
        $page = $this->createPage('Vide', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->delete();

        self::assertStringContainsString(
            'Cette page ne contient encore rien à modifier : écrivez-la d’abord depuis un brief.',
            $this->answer($this->pageBase($page), 'update', ['cms_section_intro' => ['section' => 'intro', 'html' => '<h2>Texte</h2>']]),
        );
    }

    public function testWhatAnItemHoldsNothingOfCannotBeChanged(): void
    {
        $content = $this->content();

        self::assertStringContainsString(
            'Ce contenu ne contient encore rien à modifier : écrivez-le d’abord à partir d’une consigne.',
            $this->answer($this->itemBase($content), 'update', ['cms_section_intro' => ['section' => 'intro', 'html' => '<h2>Texte</h2>']]),
        );
    }

    public function testAnEmptyPageIsRefusedForPublicationInFrench(): void
    {
        $page = $this->createPage('Vide', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->delete();

        self::assertStringContainsString('Cette page n’a pas encore de contenu : écrivez-la depuis un brief avant de la publier.', $this->answer($this->pageBase($page), 'publish'));
    }

    public function testAnEmptyItemIsRefusedForPublicationInFrench(): void
    {
        self::assertStringContainsString('Ce contenu est vide : écrivez-le avant de le publier.', $this->answer($this->itemBase($this->content()), 'publish'));
    }

    public function testAPageHoldingTheSampleTextIsRefusedForPublicationInFrench(): void
    {
        $page = $this->createPage('Mentions', published: false);
        $sample = LegalPageTemplates::PAGES['legal-notice'][$this->locale()] ?? LegalPageTemplates::PAGES['legal-notice']['en_US'];
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), Document::richText(LegalPageTemplates::html($sample)));

        self::assertStringContainsString(
            'Cette page contient encore le texte d’exemple avec lequel elle a été créée : rédigez le vôtre avant de la publier.',
            $this->answer($this->pageBase($page), 'publish'),
        );
    }

    public function testTheModelOfAPageTypeIsRefusedForPublicationInFrench(): void
    {
        $page = $this->createPage('Modèle', published: false);
        $page->setModel(1)->save();

        self::assertStringContainsString(
            'Cette page est le modèle de son type : elle n’est jamais publiée. Créez plutôt une nouvelle page à partir d’elle.',
            $this->answer($this->pageBase($page), 'publish'),
        );
    }

    public function testABrokenHeadingStructureIsRefusedInFrenchWithTheWordsOfEachFamily(): void
    {
        $page = $this->createPage('Titres', published: false);
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), Document::richText('<h2>Un</h2><h4>Saut</h4>'));
        $content = $this->content();
        $this->getService(ItemContentWriter::class)->saveContent(CatalogueItemType::Content, (int) $content->getId(), $this->locale(), Document::richText('<h2>Un</h2><h4>Saut</h4>'));

        $this->withHeadingMode('block', function () use ($page, $content): void {
            self::assertStringContainsString('La page n’a pas été publiée : corrigez d’abord la structure des titres.', $this->answer($this->pageBase($page), 'publish'));
            self::assertStringContainsString('Le contenu n’a pas été publié : corrigez d’abord la structure des intertitres.', $this->answer($this->itemBase($content), 'publish'));
        });
    }

    public function testADraftTheValidatorRefusesIsRefusedInFrench(): void
    {
        $page = $this->createPage('Invalide', published: false);
        CmsPageContentQuery::create()->filterByPageId($page->getId())->findOne()?->setDraftDocument(Document::richText('<p>Écrit par un script.</p>', 'Not an id')->toJson())->save();
        $content = $this->content();
        $this->getService(ItemContentWriter::class)->saveContent(CatalogueItemType::Content, (int) $content->getId(), $this->locale(), Document::richText('<p>Texte.</p>'));
        CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->findOne()?->setDraftDocument(Document::richText('<p>Écrit par un script.</p>', 'Not an id')->toJson())->save();

        self::assertStringContainsString('La page n’a pas été publiée : son contenu n’est plus valide. Ouvrez-la dans l’éditeur pour voir ce qu’il faut corriger.', $this->answer($this->pageBase($page), 'publish'));
        self::assertStringContainsString('Le contenu n’a pas été publié : il n’est plus valide. Ouvrez-le dans l’éditeur pour voir ce qu’il faut corriger.', $this->answer($this->itemBase($content), 'publish'));
    }

    public function testPublishingAndTakingOfflineAreAnnouncedInFrench(): void
    {
        $page = $this->createPage('En ligne', published: false);
        $content = $this->content();
        $this->getService(ItemContentWriter::class)->saveContent(CatalogueItemType::Content, (int) $content->getId(), $this->locale(), Document::richText('<h2>Titre</h2><p>Texte.</p>'));

        self::assertStringContainsString('La page est en ligne.', $this->answer($this->pageBase($page), 'publish', [], 'success'));
        self::assertStringContainsString('Le contenu est en ligne sur la page de l’élément.', $this->answer($this->itemBase($content), 'publish', [], 'success'));
        self::assertStringContainsString('Le contenu est retiré du site : le brouillon est conservé.', $this->answer($this->itemBase($content), 'unpublish', [], 'success'));
    }

    /**
     * Posts the action in the language of the back office and reads what the
     * screen says of it.
     *
     * @param array<string, mixed> $fields
     */
    private function answer(string $base, string $action, array $fields = [], string $type = 'danger'): string
    {
        $token = $this->tokenOfTheScripts($this->screen($base.self::FRENCH));

        self::assertSame(302, $this->send('POST', $base.'/'.$action, ['_token' => $token, ...$fields]));

        $flash = $this->screen($base.self::FRENCH)->filter('[data-testid="cms-builder-flash-'.$type.'"]');
        self::assertGreaterThan(0, $flash->count(), \sprintf('The screen says something of %s.', $action));

        return implode(' | ', $flash->each(static fn (Crawler $notice): string => trim($notice->text())));
    }

    private function pageBase(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d/content', $page->getId());
    }

    private function itemBase(Content $content): string
    {
        return \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());
    }

    private function content(): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
    }

    private function withHeadingMode(string $mode, callable $run): void
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::HEADING_CHECK_MODE, 'warn');
        TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, $mode);

        try {
            $run();
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, (string) $previous);
        }
    }
}
