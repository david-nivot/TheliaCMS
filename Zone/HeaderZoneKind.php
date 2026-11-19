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

namespace TheliaCMS\Zone;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Content\Kind\ContentKind;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Zone\Admin\ZoneScreenNotes;

/**
 * The header zone as the first kind of content: back to the list of pages, a
 * publication that reaches every page of the site (so it drops the whole shared
 * cache), and a zone that cannot be deleted, only taken offline in each
 * language.
 */
final readonly class HeaderZoneKind implements ContentKind
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private ZoneScreenNotes $notes,
    ) {
    }

    public function code(): string
    {
        return HeaderZone::KIND;
    }

    public function backUrl(CmsPage $content, int $languageId): string
    {
        return $this->urls->generate('admin.cms.pages.list', [EditLanguage::PARAMETER => $languageId]);
    }

    public function backLabel(): string
    {
        return $this->support->translate('Back to pages');
    }

    public function publishHint(): string
    {
        return $this->support->translate('Publishing puts the draft online in the header of every page of the site, in this language.');
    }

    public function onlineMessage(): string
    {
        return $this->support->translate('The header zone is online in this language: it is drawn in the header of every page of the site.');
    }

    public function notAPage(): string
    {
        return $this->support->translate('The header zone is never online as a page: it is drawn in the header of the site once published.');
    }

    public function notes(CmsPage $content, string $locale): array
    {
        return $this->notes->for($content);
    }

    public function cacheTags(CmsPage $content): array
    {
        return [CacheTags::SITE];
    }

    public function activityName(): string
    {
        return 'CMS header zone';
    }

    public function deletionRefusal(): string
    {
        return $this->support->translate('The header zone cannot be deleted: unpublish it in each language to draw nothing.');
    }
}
