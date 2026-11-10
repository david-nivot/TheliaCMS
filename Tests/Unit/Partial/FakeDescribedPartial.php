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

namespace TheliaCMS\Tests\Unit\Partial;

use TheliaCMS\Partial\DescribedPartialInterface;
use TheliaCMS\Partial\PartialDefinitionInterface;

/**
 * A {@see FakePartial} that also says what it does, in the words it is given.
 */
final readonly class FakeDescribedPartial implements PartialDefinitionInterface, DescribedPartialInterface
{
    public function __construct(
        private FakePartial $partial,
        private string $description,
    ) {
    }

    public function description(): string
    {
        return $this->description;
    }

    public function name(): string
    {
        return $this->partial->name();
    }

    public function label(): string
    {
        return $this->partial->label();
    }

    public function themeTemplate(): string
    {
        return $this->partial->themeTemplate();
    }

    public function fallbackTemplate(): string
    {
        return $this->partial->fallbackTemplate();
    }

    public function props(): array
    {
        return $this->partial->props();
    }

    public function context(array $props, string $locale): array
    {
        return $this->partial->context($props, $locale);
    }

    public function cacheTtl(): ?int
    {
        return $this->partial->cacheTtl();
    }
}
