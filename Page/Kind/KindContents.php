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

namespace TheliaCMS\Page\Kind;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Kind\ContentKind;
use TheliaCMS\Content\Kind\ContentKinds;
use TheliaCMS\Content\Kind\KindContentDeletionRefusedException;
use TheliaCMS\Content\Kind\KindContentNotFoundException;
use TheliaCMS\Content\Kind\PageCannotBeAdoptedException;
use TheliaCMS\Content\Kind\UnknownContentKindException;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\PageAuthorship;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\TheliaCMS;

/**
 * What a module does with its contents of a kind: make one, find them, read
 * what is published, send one to the bin and bring it back, and take a page of
 * the site in as one without losing what was written on it.
 *
 * A content is the hidden page of the kind (`ContentKind`); it is written on
 * the content screen of the pages, so nothing here writes a document. Every
 * read leaves out the other kinds, the pages and the bin.
 */
final readonly class KindContents
{
    public function __construct(
        private ContentKinds $kinds,
        private PageAuthorship $authorship,
        private CachePurger $httpCache,
        private PageInvalidator $invalidator,
        private MenuCache $menuCache,
        private CmsActivityLog $activityLog,
        private PublishedPageRepository $published,
        private CmsSettings $settings,
    ) {
    }

    /**
     * A content with no address, written by hand rather than through
     * `CmsPageWriter::saveDraft()`, which gives a page its address.
     *
     * @param array<string, string> $titles by locale, the name the content screen shows in that language
     *
     * @throws UnknownContentKindException when no active module declares the code
     * @throws \InvalidArgumentException   when no title is given
     */
    public function create(string $code, array $titles): CmsPage
    {
        $kind = $this->kinds->get($code);
        $titles = array_filter($titles, static fn (string $title): bool => '' !== trim($title));

        if ([] === $titles) {
            throw new \InvalidArgumentException('A content of a kind needs a title in at least one language.');
        }

        $content = (new CmsPage())
            ->setParent(0)
            ->setPosition(0)
            ->setVisible(0)
            ->setPageType('default')
            ->setModel(ContentKinds::MODEL)
            ->setContentKind($kind->code());

        foreach ($titles as $locale => $title) {
            $content->setLocale((string) $locale)->setTitle($title);
        }

        $this->authorship->stamp($content)->save();
        $this->activityLog->record('CREATE', (int) $content->getId(), \sprintf('%s created', $kind->activityName()));

        return $content;
    }

    /**
     * @throws UnknownContentKindException when no active module declares the code
     */
    public function find(string $code, int $id): ?CmsPage
    {
        return $this->query($this->kinds->get($code))->filterById($id)->findOne();
    }

    /**
     * @param string|null $locale the language the titles are read in, in the same query; they cost one query each without it
     *
     * @return list<CmsPage> by id
     *
     * @throws UnknownContentKindException when no active module declares the code
     */
    public function all(string $code, ?string $locale = null): array
    {
        $query = $this->query($this->kinds->get($code))->orderById();

        if (null !== $locale) {
            $query->joinWithI18n($locale, Criteria::LEFT_JOIN);
        }

        return iterator_to_array($query->find(), false);
    }

    /**
     * The contents of the kind in the bin, the latest binned first: the screen
     * of the bin of the pages leaves them out, the module that owns them lists
     * them to offer `restore()`.
     *
     * @return list<CmsPage>
     *
     * @throws UnknownContentKindException when no active module declares the code
     */
    public function binned(string $code): array
    {
        return iterator_to_array($this->query($this->kinds->get($code), binned: true)->orderByDeletedAt(Criteria::DESC)->orderById(Criteria::DESC)->find(), false);
    }

    /**
     * Brings a content back from the bin, as it was: its drafts, versions and
     * publications were kept.
     *
     * @throws UnknownContentKindException  when no active module declares the code
     * @throws KindContentNotFoundException when there is no such content in the bin
     */
    public function restore(string $code, int $id): CmsPage
    {
        $kind = $this->kinds->get($code);
        $content = $this->query($kind, binned: true)->filterById($id)->findOne() ?? throw KindContentNotFoundException::for($code, $id);

        $this->authorship->stamp($content->setDeletedAt(null))->save();

        $this->httpCache->purge($kind->cacheTags($content));
        $this->activityLog->record('RESTORE', $id, \sprintf('%s restored from the bin', $kind->activityName()));

        return $content;
    }

    /**
     * The published document of a content in a language, as it is drawn; null
     * when it is not published in that language, or says nothing.
     *
     * @throws UnknownContentKindException when no active module declares the code
     */
    public function publishedHtml(string $code, int $id, string $locale): ?string
    {
        $kind = $this->kinds->get($code);

        $rows = CmsPageContentQuery::create()
            ->filterByLocale($locale)
            ->filterByPublishedAt(null, Criteria::ISNOTNULL)
            ->useCmsPageQuery()
                ->filterById($id)
                ->filterByModel(ContentKinds::MODEL)
                ->filterByContentKind($kind->code())
                ->filterByDeletedAt(null, Criteria::ISNULL)
            ->endUse()
            ->select(['PublishedHtml'])
            ->limit(1)
            ->find()
            ->toArray();
        $html = $rows[0] ?? null;

        return \is_string($html) && '' !== trim($html) ? $html : null;
    }

    /**
     * Sends a content to the bin, where the purge of the bin deletes it once
     * its retention is over (`TrashPurger`). It is not on the screen of the
     * bin: the module that owns the contents is the one that brings one back.
     *
     * @throws UnknownContentKindException         when no active module declares the code
     * @throws KindContentNotFoundException        when there is no such content, or it is already in the bin
     * @throws KindContentDeletionRefusedException when the kind says its contents may not go to the bin
     */
    public function moveToBin(string $code, int $id): void
    {
        $kind = $this->kinds->get($code);
        $content = $this->query($kind)->filterById($id)->findOne() ?? throw KindContentNotFoundException::for($code, $id);
        $refusal = $kind->deletionRefusal();

        if (null !== $refusal) {
            throw new KindContentDeletionRefusedException($refusal, $code, $id);
        }

        $this->authorship->stamp($content->setDeletedAt(new \DateTimeImmutable()))->save();

        $this->httpCache->purge($kind->cacheTags($content));
        $this->activityLog->record('DELETE', $id, \sprintf('%s moved to the bin', $kind->activityName()));
    }

    /**
     * Turns an ordinary page into a content of the kind, in place: same id,
     * same texts, drafts, versions, translations and publications. What made
     * it a page of the site goes: its place in the tree, its addresses (and
     * the redirections of them), its lines of the search and its entries of
     * the menus; what hung under an entry of a menu is hung under the parent
     * of that entry, and its publication dates are cleared.
     *
     * A page holding a published version that is offline on the site (hidden,
     * or outside its publication window) is refused: the version would come
     * out published as a content. So is a page the site points at by its
     * settings or by a form.
     *
     * @throws UnknownContentKindException  when no active module declares the code
     * @throws PageCannotBeAdoptedException when the page is a model, already a content of a kind, in the bin, offline with a published version, the home page, the page shown for a missing address or for maintenance, the privacy policy of a form, or has pages under it
     */
    public function adopt(CmsPage $page, string $code): CmsPage
    {
        $kind = $this->kinds->get($code);
        $id = (int) $page->getId();

        if (0 !== $page->getModel()) {
            throw PageCannotBeAdoptedException::notAnOrdinaryPage($id);
        }

        if (null !== $page->getDeletedAt()) {
            throw PageCannotBeAdoptedException::inTheBin($id);
        }

        if (!$this->published->isLive($page) && $this->hasAPublishedVersion($id)) {
            throw PageCannotBeAdoptedException::offlineWithAPublishedVersion($id);
        }

        if ($id === (int) TheliaCMS::getConfigValue('home_page_id', '0')) {
            throw PageCannotBeAdoptedException::isTheHomePage($id);
        }

        if ($id === $this->settings->notFoundPageId()) {
            throw PageCannotBeAdoptedException::isTheNotFoundPage($id);
        }

        if ($id === $this->settings->maintenancePageId()) {
            throw PageCannotBeAdoptedException::isTheMaintenancePage($id);
        }

        if (CmsFormQuery::create()->filterByPrivacyPolicyPageId($id)->exists()) {
            throw PageCannotBeAdoptedException::isAPrivacyPolicy($id);
        }

        if (CmsPageQuery::create()->filterByParent($id)->exists()) {
            throw PageCannotBeAdoptedException::hasChildren($id);
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $this->authorship->stamp(
                $page->setModel(ContentKinds::MODEL)->setContentKind($kind->code())->setParent(0)->setPosition(0)->setVisible(0)->setPublishAt(null)->setUnpublishAt(null),
            )->save($connection);

            RewritingUrlQuery::create()
                ->filterByView($page->getRewrittenUrlViewName())
                ->filterByViewId((string) $id)
                ->delete($connection);
            CmsPageSearchQuery::create()->filterByPageId($id)->delete($connection);
            $menuEntries = $this->removeFromMenus($id, $connection);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->invalidator->presenceChanged($id);

        if ($menuEntries > 0) {
            $this->menuCache->invalidate();
            $this->httpCache->purge([CacheTags::MENUS]);
        }

        $this->httpCache->purge($kind->cacheTags($page));
        $this->activityLog->record('UPDATE', $id, \sprintf('CMS page #%d adopted as %s', $id, $kind->activityName()));

        return $page;
    }

    /**
     * @return int how many entries were removed
     */
    private function removeFromMenus(int $pageId, ConnectionInterface $connection): int
    {
        $entryIds = array_map(intval(...), CmsMenuItemQuery::create()
            ->filterByTargetType(MenuTargetType::Page->value)
            ->filterByTargetId($pageId)
            ->orderById()
            ->select(['Id'])
            ->find($connection)
            ->toArray());

        foreach ($entryIds as $entryId) {
            // Read again: an entry above this one may have been removed since.
            $entry = CmsMenuItemQuery::create()->findPk($entryId, $connection);

            if (null === $entry) {
                continue;
            }

            CmsMenuItemQuery::create()->filterByParent($entryId)->update(['Parent' => $entry->getParent()], $connection);
            $entry->delete($connection);
        }

        return \count($entryIds);
    }

    private function hasAPublishedVersion(int $pageId): bool
    {
        return CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByPublishedAt(null, Criteria::ISNOTNULL)
            ->exists();
    }

    private function query(ContentKind $kind, bool $binned = false): CmsPageQuery
    {
        return CmsPageQuery::create()
            ->filterByModel(ContentKinds::MODEL)
            ->filterByContentKind($kind->code())
            ->filterByDeletedAt(null, $binned ? Criteria::ISNOTNULL : Criteria::ISNULL);
    }
}
