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

namespace TheliaCMS\Webhook;

use Propel\Runtime\Map\TableMap;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Thelia\Api\Bridge\Propel\Attribute\Column;
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\PropelResourceTrait;
use TheliaCMS\Http\CoreUrls;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\PublicAddress;

/**
 * What a webhook says of a page that went online or offline in a language.
 *
 * The webhooks of the core serialize a resource; this one has no route of its
 * own (no `#[ApiResource]`): it exists for the payload only. The page reaches
 * it at the locale published (`CmsPage::setLocale()`), which gives the title
 * and the address of that language.
 */
final class PagePayload implements PropelResourceInterface
{
    use PropelResourceTrait;

    public const string GROUP = 'theliacms:webhook:page';

    #[Groups([self::GROUP])]
    public ?int $id = null;

    #[Groups([self::GROUP])]
    public ?string $locale = null;

    #[Groups([self::GROUP])]
    public ?string $title = null;

    #[Column(propelFieldName: 'pageType')]
    #[Groups([self::GROUP])]
    public ?string $type = null;

    /**
     * Not `$url`: the core would fill it from `CmsPage::getUrl()`, which needs
     * a request and knows nothing of the home page answering on "/".
     */
    #[Groups([self::GROUP])]
    #[SerializedName('url')]
    public ?string $address = null;

    #[Groups([self::GROUP])]
    public ?\DateTimeInterface $publishedAt = null;

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function afterModelToResource(array $context): void
    {
        $page = $this->getPropelModel();

        if (!$page instanceof CmsPage || null === $this->locale) {
            return;
        }

        $urls = CoreUrls::instance();
        $this->address = null === $urls ? null : PublicAddress::of($urls, (int) $page->getId(), $this->locale);
        $this->publishedAt = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($this->locale)
            ->findOne()
            ?->getPublishedAt();
    }

    public static function getPropelRelatedTableMap(): TableMap
    {
        return new CmsPageTableMap();
    }
}
