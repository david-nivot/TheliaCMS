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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\Content;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Editing\TranslationLink;
use TheliaCMS\Generation\TranslationBackfill;
use TheliaCMS\Generation\TranslationsFollowCommand;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\Map\CmsItemContentTableMap;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The translations written before a site was updated follow nothing. The
 * command ties the ones the ledger of the generator proves to be current: a
 * translation whose source was not saved after the call that wrote it. The
 * others stay as they are, for the editor to tie by hand; it writes nothing
 * unless asked to, and a second run changes nothing.
 */
final class TranslationsFollowCommandTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testADryRunNamesWhatItWouldTieAndWritesNothing(): void
    {
        $target = $this->otherLanguage();
        $page = $this->untrackedTranslation('Nos tarifs', $target, sourceSavedBeforeTheCall: true);

        $tester = $this->follow([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Nothing is written', $tester->getDisplay(), 'Said, so nobody takes a dry run for the real thing.');
        self::assertStringContainsString('page #'.$page->getId(), $tester->getDisplay());
        self::assertNull($this->pageLink($page, $target), 'A dry run ties nothing.');
    }

    public function testApplyTiesOnlyTheTranslationsTheLedgerProvesCurrent(): void
    {
        $target = $this->otherLanguage();
        $proven = $this->untrackedTranslation('Prouvée', $target, sourceSavedBeforeTheCall: true);
        $edited = $this->untrackedTranslation('Modifiée depuis', $target, sourceSavedBeforeTheCall: false);
        $rewritten = $this->untrackedTranslation('Réécrite depuis un brief', $target, sourceSavedBeforeTheCall: true);
        $this->ledgerEntryAfter($rewritten, $target, 'brief');
        $binned = $this->untrackedTranslation('À la corbeille', $target, sourceSavedBeforeTheCall: true);
        $this->getService(CmsPageWriter::class)->moveToTrash($binned);

        $item = $this->untrackedItemTranslation($target);

        $tester = $this->follow(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $link = $this->pageLink($proven, $target);
        self::assertSame($this->locale(), $link?->sourceLocale, 'The source was not saved after the call: the translation is current by proof.');
        self::assertFalse($link->isStale());
        self::assertNull($this->pageLink($edited, $target), 'The source was saved after the call: nothing proves it is what the translation was written from.');
        self::assertNull($this->pageLink($rewritten, $target), 'Written from a brief since, it is no longer a translation of the source.');
        self::assertNull($this->pageLink($binned, $target), 'A page in the bin is not touched.');
        $itemLink = $this->itemLink($item, $target);
        self::assertSame($this->locale(), $itemLink?->sourceLocale, 'The items of the catalogue are read the same way.');

        // What is tied is then followed like any translation.
        $this->getService(CmsPageWriter::class)->saveContent($proven, $this->locale(), Document::richText('<h2>Nos tarifs, revus</h2>'));
        self::assertTrue($this->pageLink($proven, $target)?->isStale());
    }

    public function testASecondRunChangesNothing(): void
    {
        $target = $this->otherLanguage();
        $page = $this->untrackedTranslation('Prouvée', $target, sourceSavedBeforeTheCall: true);

        $this->follow(['--apply' => true]);
        $before = $this->storedLink($page, $target);
        self::assertNotNull($before);

        $tester = $this->follow(['--apply' => true]);

        self::assertSame($before, $this->storedLink($page, $target), 'Already followed: left as it is.');
        self::assertStringContainsString('0 tied', $tester->getDisplay());
    }

    public function testATranslationAlreadyFollowedIsNotMovedToTheCurrentSource(): void
    {
        $target = $this->otherLanguage();
        $page = $this->untrackedTranslation('Suivie', $target, sourceSavedBeforeTheCall: true);
        $this->follow(['--apply' => true]);
        $before = $this->storedLink($page, $target);

        // Changed since, the ledger entry is older than the source: a second run must not take the new source as the one written from.
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), Document::richText('<h2>Suivie, modifiée</h2>'));
        $this->follow(['--apply' => true]);

        self::assertSame($before, $this->storedLink($page, $target));
        self::assertTrue($this->pageLink($page, $target)?->isStale());
    }

    // — helpers —

    /**
     * A page translated through the screen, as a site did before the update:
     * nothing is followed, and the dates say when the source was last saved
     * in relation to the call that wrote the translation.
     */
    private function untrackedTranslation(string $title, Lang $target, bool $sourceSavedBeforeTheCall): CmsPage
    {
        $page = $this->createPage($title, published: false);
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>%s</h2>', $title)));
        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Translated</h2>']],
        ]], \JSON_THROW_ON_ERROR)));
        $form = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), $target->getId()))->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $connection = $this->getPropelConnection();
        $connection->exec(\sprintf("UPDATE cms_page_generation SET created_at = '2026-01-01 11:00:00' WHERE target_type = 'page' AND target_id = %d AND kind = 'translation'", $page->getId()));
        $connection->exec(\sprintf("UPDATE cms_page_content SET updated_at = '%s' WHERE page_id = %d AND locale = '%s'", $sourceSavedBeforeTheCall ? '2026-01-01 10:00:00' : '2026-01-01 12:00:00', $page->getId(), $this->locale()));
        $connection->exec(\sprintf('UPDATE cms_page_content SET translated_from = NULL, source_fingerprint = NULL WHERE page_id = %d', $page->getId()));
        CmsPageContentTableMap::clearInstancePool();

        return $page;
    }

    private function untrackedItemTranslation(Lang $target): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $item = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $id = (int) $item->getId();
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()), Document::richText('<h2>Notre méthode</h2>'));
        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Our method</h2>']],
        ]], \JSON_THROW_ON_ERROR)));
        $form = $this->screen(\sprintf('/admin/cms/catalogue/content/%d/content?edit_language_id=%d', $id, $target->getId()))->filter('[data-testid="cms-content-translate"] form')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $connection = $this->getPropelConnection();
        $connection->exec(\sprintf("UPDATE cms_page_generation SET created_at = '2026-01-01 11:00:00' WHERE target_type = 'content' AND target_id = %d AND kind = 'translation'", $id));
        $connection->exec(\sprintf("UPDATE cms_item_content SET updated_at = '2026-01-01 10:00:00' WHERE item_type = 'content' AND item_id = %d AND locale = '%s'", $id, $this->locale()));
        $connection->exec(\sprintf("UPDATE cms_item_content SET translated_from = NULL, source_fingerprint = NULL WHERE item_type = 'content' AND item_id = %d", $id));
        CmsItemContentTableMap::clearInstancePool();

        return $item;
    }

    /**
     * A later call on the same content and language, of another kind.
     */
    private function ledgerEntryAfter(CmsPage $page, Lang $target, string $kind): void
    {
        $this->getPropelConnection()->exec(\sprintf(
            "INSERT INTO cms_page_generation (target_type, target_id, locale, kind, brief, status, created_at, updated_at) VALUES ('page', %d, '%s', '%s', '{\"text\":\"Une page.\"}', 'done', '2026-01-01 11:30:00', '2026-01-01 11:30:00')",
            $page->getId(),
            $target->getLocale(),
            $kind,
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function follow(array $options): CommandTester
    {
        $tester = new CommandTester(new TranslationsFollowCommand($this->getService(TranslationBackfill::class)));
        $tester->execute($options);

        return $tester;
    }

    private function otherLanguage(): Lang
    {
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');

        return $other;
    }

    private function pageLink(CmsPage $page, Lang $target): ?TranslationLink
    {
        CmsPageContentTableMap::clearInstancePool();
        $targets = $this->getService(ContentTargets::class);

        return $this->getService(TranslationFreshness::class)->link($targets->forPage($page, $target->getLocale()), static fn (string $locale) => $targets->forPage($page, $locale));
    }

    private function itemLink(Content $item, Lang $target): ?TranslationLink
    {
        CmsItemContentTableMap::clearInstancePool();

        return $this->getService(TranslationFreshness::class)->link(
            $this->itemTarget(CatalogueItemType::Content, (int) $item->getId(), $target->getLocale()),
            fn (string $locale) => $this->itemTarget(CatalogueItemType::Content, (int) $item->getId(), $locale),
        );
    }

    private function storedLink(CmsPage $page, Lang $target): ?string
    {
        $stored = $this->getPropelConnection()->query(\sprintf("SELECT CONCAT(IFNULL(translated_from, ''), '|', IFNULL(source_fingerprint, '')) FROM cms_page_content WHERE page_id = %d AND locale = '%s'", $page->getId(), $target->getLocale()))->fetchColumn();

        return \is_string($stored) && '|' !== $stored ? $stored : null;
    }
}
