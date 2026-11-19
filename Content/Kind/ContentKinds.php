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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TheliaCMS\Model\CmsPage;

/**
 * The kinds of content the active modules declare, by code.
 *
 * Built from the services tagged `ContentKind::TAG` the first time something
 * asks for a kind, as any service of the container is: it refuses two kinds of
 * the same code and a code that is not stored as is in `cms_page.content_kind`,
 * so a module that declares one wrongly fails on the first screen or command
 * that reads the kinds, not when the container is compiled. A module that is
 * not active declares nothing: its contents stay in the database and show
 * nowhere.
 */
final readonly class ContentKinds
{
    /** What `cms_page.content_kind` (32 characters) holds. */
    private const string CODE_PATTERN = '/^[a-z0-9_]{1,32}$/D';

    /** The value of `cms_page.model` of a hidden page, the header zone and every content of a kind. */
    public const int MODEL = 2;

    /** @var array<string, ContentKind> */
    private array $kinds;

    /**
     * @param iterable<ContentKind> $kinds
     *
     * @throws InvalidContentKindException when a code is invalid or declared twice
     */
    public function __construct(
        #[AutowireIterator(ContentKind::TAG)]
        iterable $kinds,
    ) {
        $byCode = [];

        foreach ($kinds as $kind) {
            $code = $kind->code();

            if (1 !== preg_match(self::CODE_PATTERN, $code)) {
                throw InvalidContentKindException::code($code, $kind::class);
            }

            if (isset($byCode[$code])) {
                throw InvalidContentKindException::duplicate($code, $byCode[$code]::class, $kind::class);
            }

            $byCode[$code] = $kind;
        }

        $this->kinds = $byCode;
    }

    /**
     * The kind of a hidden page; null for a page, the model of a type, and a
     * content whose kind no active module declares.
     */
    public function of(CmsPage $page): ?ContentKind
    {
        if (self::MODEL !== $page->getModel()) {
            return null;
        }

        $code = $page->getContentKind();

        return null === $code ? null : ($this->kinds[$code] ?? null);
    }

    /**
     * @throws UnknownContentKindException when no active module declares the code
     */
    public function get(string $code): ContentKind
    {
        return $this->kinds[$code] ?? throw UnknownContentKindException::code($code);
    }

    /**
     * A hidden content whose kind no active module declares: it stays in the
     * database and is shown, written and published nowhere.
     */
    public function isOrphan(CmsPage $page): bool
    {
        return self::MODEL === $page->getModel() && null === $this->of($page);
    }

    public function has(string $code): bool
    {
        return isset($this->kinds[$code]);
    }
}
