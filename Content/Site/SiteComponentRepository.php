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

namespace TheliaCMS\Content\Site;

use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Model\CmsComponent;
use TheliaCMS\Model\CmsComponentQuery;

/**
 * The components of the site, as `cms_component` holds them.
 *
 * A component is saved as the validator gave it back; the repository checks
 * nothing but that its code is free. Only the `ready` ones are components of
 * the catalogue; a `draft` is the one an editor is still looking at.
 */
final readonly class SiteComponentRepository
{
    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_READY = 'ready';

    public function __construct(
        private ?SecurityContext $securityContext = null,
    ) {
    }

    /**
     * @return list<SiteComponent> the components kept for the whole site, by code
     */
    public function ready(): array
    {
        return array_map(self::fromModel(...), iterator_to_array(
            CmsComponentQuery::create()->filterByStatus(self::STATUS_READY)->orderByCode()->find(),
            false,
        ));
    }

    /**
     * Every component of the site, the prototypes included: what a stored
     * document may name, what renders and what is styled.
     *
     * @return list<SiteComponent> by code
     */
    public function all(): array
    {
        return array_map(self::fromModel(...), iterator_to_array(
            CmsComponentQuery::create()->orderByCode()->find(),
            false,
        ));
    }

    /**
     * The codes of the prototypes: components made from a page or by the
     * generator writing one, kept in `draft` until someone keeps them for
     * the whole site. A prototype renders on the pages that hold it and is
     * edited by hand there; it is offered to nobody else.
     *
     * @return list<string>
     */
    public function prototypes(): array
    {
        return array_map(
            static fn (CmsComponent $model): string => (string) $model->getCode(),
            iterator_to_array(CmsComponentQuery::create()->filterByStatus(self::STATUS_DRAFT)->orderByCode()->find(), false),
        );
    }

    /**
     * A prototype kept for the whole site: from then on it is offered to the
     * generator and on the content screen of every page.
     */
    public function promote(string $code): void
    {
        $model = CmsComponentQuery::create()->findOneByCode($code);

        if (null === $model) {
            throw new \InvalidArgumentException(\sprintf('The site has no component "%s".', $code));
        }

        $model->setStatus(self::STATUS_READY)
            ->setUpdatedBy($this->securityContext?->getAdminUser()?->getId())
            ->save();
    }

    public function find(string $code): ?SiteComponent
    {
        $model = CmsComponentQuery::create()->findOneByCode($code);

        return null === $model ? null : self::fromModel($model);
    }

    /**
     * The code of the component a row of the ledger points at, or null when
     * the row is gone.
     */
    public function codeOf(int $id): ?string
    {
        return CmsComponentQuery::create()->findPk($id)?->getCode();
    }

    /**
     * The id of the row of a component, 0 for a code the site does not hold.
     */
    public function idOf(string $code): int
    {
        return (int) CmsComponentQuery::create()->findOneByCode($code)?->getId();
    }

    public function status(string $code): ?string
    {
        return CmsComponentQuery::create()->findOneByCode($code)?->getStatus();
    }

    /**
     * Creates the component, or replaces what its code holds.
     *
     * @param string|null $replacing the code of the component this one replaces; null for a new one, whose code must be free
     *
     * @throws InvalidSiteComponentException when a new component takes a code already used
     */
    public function save(SiteComponent $component, string $status, ?string $replacing = null): void
    {
        if (!\in_array($status, [self::STATUS_DRAFT, self::STATUS_READY], true)) {
            throw new \InvalidArgumentException(\sprintf('A component is "draft" or "ready", not "%s".', $status));
        }

        $model = CmsComponentQuery::create()->findOneByCode($replacing ?? $component->code);

        if (null === $replacing && null !== $model) {
            throw InvalidSiteComponentException::because([\sprintf('The code "%s" is already the one of a component of the site.', $component->code)]);
        }

        if (null !== $replacing && $replacing !== $component->code) {
            throw InvalidSiteComponentException::because([\sprintf('The code of a component never changes: "%s" stays "%s".', $replacing, $component->code)]);
        }

        $adminId = $this->securityContext?->getAdminUser()?->getId();

        if (null === $model) {
            $model = (new CmsComponent())->setCode($component->code)->setCreatedBy($adminId);
        }

        $model->setLabel($component->label)
            ->setDescription($component->description)
            ->setProps(json_encode($component->props, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
            ->setSample(json_encode((object) $component->sample, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
            ->setTemplate($component->template)
            ->setCss($component->css)
            ->setStatus($status)
            // A component without a mark leaves the mark it has: nothing a
            // person does to it here takes the mark of a generated one away.
            ->setProvenance($component->provenance ?? $model->getProvenance())
            ->setUpdatedBy($adminId)
            ->save();
    }

    public function delete(string $code): void
    {
        CmsComponentQuery::create()->filterByCode($code)->delete();
    }

    private static function fromModel(CmsComponent $model): SiteComponent
    {
        return new SiteComponent(
            (string) $model->getCode(),
            (string) $model->getLabel(),
            (string) $model->getDescription(),
            self::list($model->getProps()),
            self::object($model->getSample()),
            (string) $model->getTemplate(),
            (string) $model->getCss(),
            $model->getProvenance(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(?string $json): array
    {
        $value = json_decode((string) $json, true);

        return \is_array($value) && array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(?string $json): array
    {
        $value = json_decode((string) $json, true);

        return \is_array($value) ? $value : [];
    }
}
