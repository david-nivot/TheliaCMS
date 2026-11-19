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

namespace TheliaCMS\Content\Kind;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TheliaCMS\Model\CmsPage;

/**
 * A family of contents a module declares, written on the content screen of
 * the pages (draft, versions, translations, one publication per language)
 * and shown nowhere as a page: no address, no line in the pages list, the
 * search, the sitemap or the menus (ADR 20).
 *
 * Each content is a hidden page (`cms_page.model = 2`) that carries the code
 * of its kind in `cms_page.content_kind`. A service that implements this
 * interface is tagged by autoconfiguration; the header zone is the first kind.
 * The rights are those of the pages: nothing here asks for a right of its own.
 */
#[AutoconfigureTag(self::TAG)]
interface ContentKind
{
    public const string TAG = 'thelia_cms.content_kind';

    /** Stored in `cms_page.content_kind`: lowercase letters, digits and `_`, 1 to 32 characters. */
    public function code(): string;

    /** Where the content screen links back to, in the language being edited. */
    public function backUrl(CmsPage $content, int $languageId): string;

    /** Translated: the label of that link. */
    public function backLabel(): string;

    /** Translated: the hint under the publish button. */
    public function publishHint(): string;

    /** Translated: the message once a draft went online. */
    public function onlineMessage(): string;

    /** Translated: the refusal when someone tries to show it as a page (visibility switch). */
    public function notAPage(): string;

    /**
     * Translated notes at the top of the content screen.
     *
     * @return list<string>
     */
    public function notes(CmsPage $content, string $locale): array;

    /**
     * Keys of the shared cache that show the content, purged after a publication,
     * an unpublication or a new rendering (`TheliaCMS\Http\CacheTags`, or core keys).
     *
     * @return list<string>
     */
    public function cacheTags(CmsPage $content): array;

    /** How the activity log names one content of the kind, untranslated: `CMS header zone`. */
    public function activityName(): string;

    /** Translated refusal when a content of the kind may not go to the bin; null when it may. */
    public function deletionRefusal(): ?string;
}
