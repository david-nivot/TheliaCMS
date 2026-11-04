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

namespace TheliaCMS\ImportExport\Step;

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Page\PageTagName;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;

/**
 * The type, the model status and the tag of an imported page.
 */
final readonly class PageTaxonomy
{
    public function __construct(
        private PageTypeRepository $pageTypes,
        private PageTypeWriter $pageTypeWriter,
        private PageTagWriter $tagWriter,
    ) {
    }

    /**
     * Whether an imported page is the model of its type. A type has one: a
     * model the site has already keeps the place, and the page of the file
     * comes in as an ordinary draft.
     *
     * @param array<string, mixed> $page
     */
    public function isModel(array $page, ?CmsPage $existing, ImportReport $report, ConnectionInterface $connection): bool
    {
        if (true !== ($page['model'] ?? false)) {
            return false;
        }

        $model = $this->pageTypes->modelOf(PageTypeCode::orDefault(\is_string($page['page_type'] ?? null) ? $page['page_type'] : null), $connection);

        if (null === $model || (null !== $existing && (int) $model->getId() === (int) $existing->getId())) {
            return true;
        }

        $report->warn(\sprintf('Page "%s" is the model of its type, which already has one here: it was imported as an ordinary page.', (string) ($page['uid'] ?? '')));

        return false;
    }

    /**
     * The type of an imported page.
     *
     * Files written before 1.2.0 carry the former `layout` key, whose three
     * values are page types of the same code. A type this site lacks is created
     * when `$knownTypes` is given, which only an import of a site does: that is
     * the gesture of whoever runs the site. Starting a page from a template
     * needs nothing more than the right to write pages, so there a missing type
     * is the default one, never a type added behind the settings screen.
     *
     * @param array<string, mixed> $page
     * @param list<string>|null    $knownTypes the codes of the site, completed as types are created
     */
    public function pageTypeOf(array $page, ImportReport $report, ConnectionInterface $connection, ?array &$knownTypes = null): string
    {
        $raw = $page['page_type'] ?? $page['layout'] ?? PageTypeCode::DEFAULT;
        $code = \is_string($raw) ? $raw : '';

        if (!PageTypeCode::isValid($code)) {
            $report->warn('A page type that is not a valid code was replaced by the default type.');

            return PageTypeCode::DEFAULT;
        }

        if (null === $knownTypes) {
            return $this->pageTypes->exists($code, $connection) ? $code : PageTypeCode::DEFAULT;
        }

        if (!\in_array($code, $knownTypes, true)) {
            $this->pageTypeWriter->add($code, $connection);
            $knownTypes[] = $code;
            $report->warn(\sprintf('Page type "%s" did not exist on this site: it was created.', $code));
        }

        return $code;
    }

    /**
     * The tag of an imported page, created here when the site lacks it: a tag
     * is a label of the content, so bringing one in takes no more than the
     * right to write the page, from a site file or from a saved template.
     * Files written before 1.3.0 carry no tag, before 2.5.0 no color, and
     * before 2.7.0 a color that is a name of the former palette. The color
     * goes to a tag created here: a tag the site has keeps its own.
     *
     * @param array<string, mixed> $page
     */
    public function tagIdOf(array $page, ImportReport $report, ConnectionInterface $connection): ?int
    {
        $raw = $page['tag'] ?? null;

        if (null === $raw) {
            return null;
        }

        $name = \is_string($raw) ? PageTagName::normalize($raw) : '';

        if (!PageTagName::isValid($name)) {
            $report->warn('A page tag that is not a name was left out.');

            return null;
        }

        return (int) $this->tagWriter->add($name, $this->tagColorOf($page, $name, $report), $connection)->getId();
    }

    /**
     * @param array<string, mixed> $page
     */
    private function tagColorOf(array $page, string $name, ImportReport $report): ?PageTagColor
    {
        $raw = $page['tag_color'] ?? null;

        if (null === $raw) {
            return null;
        }

        $rawText = $page['tag_text_color'] ?? null;
        $color = \is_string($raw) ? PageTagColor::fromStored($raw, \is_string($rawText) ? $rawText : null) : null;

        if (null === $color) {
            $report->warn(\sprintf('The tag "%s" was imported without its color, which is not the code of a color.', $name));
        }

        return $color;
    }
}
