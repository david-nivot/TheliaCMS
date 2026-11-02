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

namespace TheliaCMS\Content;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Every component a document may use, by name.
 *
 * The definitions registered one by one come first, then what the providers
 * bring. The first definition claiming a name wins: a module that ships a
 * component under a name this module already uses does not replace it, which
 * is the same rule the partial registry applies. A provider may also put
 * names away (`SectionHidingInterface`): the theme that opens its own
 * component for something hides the module's.
 *
 * Two readings. `all()` is every component a stored page may name, what
 * renders and validates a document. `offered()` leaves out what the site put
 * away (SectionAvailability): what the generator reads and may write.
 */
final class SectionCatalog implements ResetInterface
{
    public const string TAG = 'thelia_cms.section';

    public const string PROVIDER_TAG = 'thelia_cms.section_provider';

    /** @var array<string, SectionDefinitionInterface>|null */
    private ?array $byName = null;

    /**
     * @param iterable<SectionDefinitionInterface> $definitions
     * @param iterable<SectionProviderInterface>   $providers
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        private readonly iterable $definitions,
        #[AutowireIterator(self::PROVIDER_TAG)]
        private readonly iterable $providers = [],
        private readonly ?SectionAvailability $availability = null,
    ) {
    }

    /**
     * Forgets the catalogue built for a request: a component kept in the
     * back office belongs to the next one, in a worker that serves many.
     */
    public function reset(): void
    {
        $this->byName = null;
    }

    public function find(string $name): ?SectionDefinitionInterface
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return list<string>
     */
    public function offeredNames(): array
    {
        return array_keys($this->offered());
    }

    /**
     * Read again at every call, unlike the catalogue itself: a component
     * put away in the back office is left out from the next request on. A
     * prototype (a component of the site made from a page and not kept for
     * the whole site yet) is left out too: the pages that hold it render and
     * edit it, nobody else is offered it.
     *
     * @return array<string, SectionDefinitionInterface>
     */
    public function offered(): array
    {
        $offered = array_filter(
            $this->all(),
            static fn (SectionDefinitionInterface $definition): bool => !($definition instanceof Site\SiteComponentSection && $definition->prototype),
        );

        if (null === $this->availability) {
            return $offered;
        }

        $disabled = array_filter($this->availability->disabled(), $this->availability->canBeDisabled(...));

        return array_diff_key($offered, array_flip($disabled));
    }

    /**
     * Whether a section of this component may be duplicated by hand: the
     * site offers the component, or it is a prototype, which the pages
     * holding it edit like any section. A component the site put away is
     * not copied, as it is not added.
     */
    public function canBeDuplicated(string $name): bool
    {
        if (isset($this->offered()[$name])) {
            return true;
        }

        $definition = $this->find($name);

        return $definition instanceof Site\SiteComponentSection && $definition->prototype;
    }

    /**
     * @return array<string, SectionDefinitionInterface>
     */
    public function all(): array
    {
        if (null !== $this->byName) {
            return $this->byName;
        }

        $byName = [];
        $hidden = [];

        foreach ($this->definitions as $definition) {
            $byName[$definition->name()] ??= $definition;
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->sections() as $definition) {
                $byName[$definition->name()] ??= $definition;
            }

            if ($provider instanceof SectionHidingInterface) {
                $hidden = [...$hidden, ...$provider->hidden()];
            }
        }

        // What a provider puts away goes, unless it is what the provider
        // itself brought: a theme hides the module's row of products, never
        // its own component.
        foreach ($hidden as $name) {
            if (isset($byName[$name]) && !$byName[$name] instanceof Theme\ThemeComponentSection) {
                unset($byName[$name]);
            }
        }

        return $this->byName = $byName;
    }
}
