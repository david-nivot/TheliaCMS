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

use Thelia\Domain\Ai\AiProvenance;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Generation\Usage;

/**
 * What the model answered to a request for a component, with what the call
 * cost: a component the site checked (`component`, also after a `copy`, which
 * then names its source in `basedOn`), or the name of a component the site
 * already has (`existing`), in which case there is no component. Nothing is
 * kept yet: the editor looks at it first.
 */
final readonly class ComponentResult
{
    public const string COMPONENT = 'component';

    public const string EXISTING = 'existing';

    public const string COPY = 'copy';

    /** What the answer may say; `copy` leads to a second answer, which is a component. */
    public const array OUTCOMES_ASKED = [self::COMPONENT, self::EXISTING, self::COPY];

    /**
     * @param string|null $existing the name of the component that already does what is asked, when the outcome is `existing`
     * @param string|null $basedOn  the `Site:` name of the component copied, after a copy
     */
    public function __construct(
        public ?SiteComponent $component,
        public string $model,
        public ?string $requestId,
        public Usage $usage,
        public float $costUsd,
        public int $durationMs,
        public int $turns,
        public int $logId,
        public ?AiProvenance $provenance = null,
        public string $outcome = self::COMPONENT,
        public ?string $existing = null,
        public ?string $basedOn = null,
        public string $reason = '',
    ) {
    }
}
