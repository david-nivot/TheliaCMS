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

namespace TheliaCMS\Media\Icon;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;

/**
 * Where an icon is cited.
 *
 * A page or an item of the catalogue names an icon in its document, draft or
 * published, as the string "cms:<name>". The HTML rendered from the document
 * holds the drawing, not the name, and the revisions are history: only the two
 * documents of `cms_page_content` and `cms_item_content` count. The search
 * is for the string between its quotes, so "cms:arrow" is not found in a
 * document that cites "cms:arrow-2".
 *
 * The template of a component of the site (`cms_component.template`) may
 * write `cms_icon('cms:<name>')` itself, between single or double quotes: a
 * component that does is counted too, draft or ready. Its template is raw
 * Twig, so only the string between its quotes counts there as well.
 *
 * A page in the bin is not counted, as MediaUsageFinder does not count it.
 */
final readonly class IconCitations
{
    public function of(string $name): IconCitation
    {
        if (!IconName::isValid($name)) {
            return new IconCitation(0, 0, 0);
        }

        // BINARY: the collation of the columns ignores the case. The name holds no wildcard of LIKE.
        $pattern = '%"'.IconName::prefixed($name).'"%';

        $pages = $this->connection()->prepare(
            'SELECT COUNT(DISTINCT content.page_id) FROM cms_page_content AS content INNER JOIN cms_page AS page ON page.id = content.page_id'
            .' WHERE page.deleted_at IS NULL AND (content.draft_document LIKE BINARY :draft OR content.published_document LIKE BINARY :published)',
        );
        $pages->execute(['draft' => $pattern, 'published' => $pattern]);

        $items = $this->connection()->prepare(
            'SELECT COUNT(DISTINCT CONCAT(content.item_type, \'-\', content.item_id)) FROM cms_item_content AS content'
            .' WHERE content.draft_document LIKE BINARY :draft OR content.published_document LIKE BINARY :published',
        );
        $items->execute(['draft' => $pattern, 'published' => $pattern]);

        $components = $this->connection()->prepare(
            'SELECT COUNT(*) FROM cms_component WHERE template LIKE BINARY :single OR template LIKE BINARY :double',
        );
        $components->execute([
            'single' => '%\''.IconName::prefixed($name).'\'%',
            'double' => '%"'.IconName::prefixed($name).'"%',
        ]);

        return new IconCitation((int) $pages->fetchColumn(), (int) $items->fetchColumn(), (int) $components->fetchColumn());
    }

    private function connection(): ConnectionInterface
    {
        return Propel::getConnection('TheliaMain');
    }
}
