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
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\PropelResourceTrait;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Form\SubmissionData;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Model\Map\CmsFormSubmissionTableMap;

/**
 * What a webhook says of a message a visitor sent through a form: the answers
 * as they were kept, as the core gives the addresses of an order. A receiver
 * that wants none of them subscribes to the identifiers only.
 *
 * Only a kept message has one: a form that keeps no answers publishes nothing.
 * No route serves this class: it exists for the payload only.
 */
final class SubmissionPayload implements PropelResourceInterface
{
    use PropelResourceTrait;

    public const string GROUP = 'theliacms:webhook:submission';

    #[Groups([self::GROUP])]
    public ?int $id = null;

    /** The code of the form. */
    #[Groups([self::GROUP])]
    public ?string $form = null;

    #[Groups([self::GROUP])]
    public ?string $locale = null;

    #[Groups([self::GROUP])]
    public ?string $email = null;

    /** Whether every agreement the form asked for was given; true without any. */
    #[Groups([self::GROUP])]
    public bool $consent = true;

    /**
     * @var list<array{name: string, type: string, label: string, value: string|bool, granted_at?: string}>
     */
    #[Groups([self::GROUP])]
    public array $answers = [];

    #[Groups([self::GROUP])]
    public ?\DateTimeInterface $createdAt = null;

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

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function afterModelToResource(array $context): void
    {
        $submission = $this->getPropelModel();

        if (!$submission instanceof CmsFormSubmission) {
            return;
        }

        $this->form = CmsFormQuery::create()->findPk($submission->getFormId())?->getCode();
        $this->answers = SubmissionData::decode($submission->getData());

        foreach ($this->answers as $answer) {
            if (FieldType::Consent->value === $answer['type'] && true !== $answer['value']) {
                $this->consent = false;
            }
        }
    }

    public static function getPropelRelatedTableMap(): TableMap
    {
        return new CmsFormSubmissionTableMap();
    }
}
