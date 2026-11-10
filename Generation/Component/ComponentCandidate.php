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

namespace TheliaCMS\Generation\Component;

use TheliaCMS\Content\Site\SiteComponent;

/**
 * One call of the generator of components, as the ledger keeps it: what was
 * asked, how it went, and the component it wrote when it wrote one. The
 * editor looks at a candidate before keeping it; nothing else holds it.
 */
final readonly class ComponentCandidate
{
    /**
     * @param int $componentId the row of the component it changes; 0 for a new one
     */
    public function __construct(
        public int $logId,
        public string $kind,
        public string $status,
        public string $request,
        public int $componentId,
        public string $locale,
        public ?SiteComponent $component,
        public ?string $error,
        public float $costUsd,
        public int $turns,
        public int $durationMs,
        public ?\DateTimeInterface $createdAt,
        public string $outcome = ComponentResult::COMPONENT,
        public ?string $existing = null,
        public ?string $basedOn = null,
        public string $reason = '',
    ) {
    }

    /**
     * A call that found the site already has what was asked: nothing to keep.
     */
    public function isExisting(): bool
    {
        return ComponentResult::EXISTING === $this->outcome;
    }

    /**
     * The code of the component of the site the call named, whether it is
     * the one that already does what was asked or the one it was copied from;
     * null for a component of the module or of the theme, which has no screen.
     */
    public static function siteCodeOf(?string $name): ?string
    {
        return null !== $name && str_starts_with($name, SiteComponent::PREFIX) ? substr($name, \strlen(SiteComponent::PREFIX)) : null;
    }

    public function isChange(): bool
    {
        return ComponentRequest::KIND_CHANGE === $this->kind;
    }
}
