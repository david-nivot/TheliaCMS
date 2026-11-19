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

namespace TheliaCMS\Tests\Integration\Kind;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Kind\ContentKinds;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Tests\Support\GuideKind;
use TheliaCMS\Zone\Admin\ZoneScreenNotes;
use TheliaCMS\Zone\HeaderZoneKind;

/**
 * Declares test kinds next to the header zone.
 *
 * No test service is declared to the container of the module (`Tests/` is
 * excluded from its service loading, so that doubles are never wired in
 * production): the registry is replaced in the container, as the screen tests
 * do with `AiAvailability`. Call it from `setUp()`, right after the parent,
 * before any service that reads the registry is built.
 */
trait DeclaresTestKinds
{
    /**
     * @return array{guide: GuideKind, kept: GuideKind, header: HeaderZoneKind}
     */
    protected function declareTestKinds(): array
    {
        $guide = new GuideKind('test_guide');
        // Another kind, refusing the bin: what a content of one kind does not do for the other.
        $kept = new GuideKind('test_kept', mayBeBinned: false);
        $header = new HeaderZoneKind(
            $this->getService(UrlGeneratorInterface::class),
            $this->getService(AdminControllerSupport::class),
            $this->getService(ZoneScreenNotes::class),
        );

        static::getContainer()->set(ContentKinds::class, new ContentKinds([$header, $guide, $kept]));

        return ['guide' => $guide, 'kept' => $kept, 'header' => $header];
    }

    /**
     * A content of a kind with a draft, published in the language unless told otherwise.
     */
    protected function kindContent(string $code, string $title, string $html = '<h2>Guide</h2><p>Mesurez votre tour de poitrine.</p>', bool $published = true): CmsPage
    {
        $content = $this->getService(KindContents::class)->create($code, [$this->locale() => $title]);
        $this->writer()->saveContent($content, $this->locale(), Document::richText($html));

        if ($published) {
            $this->writer()->publish($content, $this->locale());
        }

        return $content;
    }

    protected function writer(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }
}
