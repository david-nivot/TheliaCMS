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

namespace TheliaCMS\Tests\Support;

use TheliaCMS\Content\Kind\ContentKind;
use TheliaCMS\Model\CmsPage;

/**
 * A kind of content a module could declare (the size guides of a shop), with
 * words of its own so a screen test can tell them from those of the header
 * zone. Not a service: tests hand it to the registry.
 */
final readonly class GuideKind implements ContentKind
{
    public const string BACK_LABEL = 'Back to the guides';

    public const string PUBLISH_HINT = 'Publishing puts the guide online in the sheet of the products.';

    public const string ONLINE_MESSAGE = 'The guide is online in this language.';

    public const string NOT_A_PAGE = 'A guide is never shown as a page.';

    public const string DELETION_REFUSAL = 'A guide is removed from the guides screen.';

    public function __construct(
        private string $code = 'test_guide',
        private bool $mayBeBinned = true,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function backUrl(CmsPage $content, int $languageId): string
    {
        return \sprintf('/admin/guides-of-the-test?edit_language_id=%d', $languageId);
    }

    public function backLabel(): string
    {
        return self::BACK_LABEL;
    }

    public function publishHint(): string
    {
        return self::PUBLISH_HINT;
    }

    public function onlineMessage(): string
    {
        return self::ONLINE_MESSAGE;
    }

    public function notAPage(): string
    {
        return self::NOT_A_PAGE;
    }

    public function notes(CmsPage $content, string $locale): array
    {
        return [\sprintf('Guide #%d read in %s.', $content->getId(), $locale)];
    }

    public function cacheTags(CmsPage $content): array
    {
        return [self::tagOf((int) $content->getId())];
    }

    public static function tagOf(int $id): string
    {
        return 'test-guide-'.$id;
    }

    public function activityName(): string
    {
        return 'Test guide';
    }

    public function deletionRefusal(): ?string
    {
        return $this->mayBeBinned ? null : self::DELETION_REFUSAL;
    }
}
