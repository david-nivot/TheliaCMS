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

namespace TheliaCMS\Form\Admin;

use Thelia\Core\Security\AccessManager;
use TheliaCMS\Form\SubmissionStatus;

/**
 * What can be done to a selection of answers: give them a status, or delete
 * them. The values of the statuses are those of `SubmissionStatus`.
 */
enum SubmissionBulkAction: string
{
    case MarkNew = 'new';
    case MarkHandled = 'handled';
    case Archive = 'archived';
    case Delete = 'delete';

    /** The right on the answers it takes. */
    public function access(): string
    {
        return self::Delete === $this ? AccessManager::DELETE : AccessManager::UPDATE;
    }

    /** The status it gives, none for a deletion. */
    public function status(): ?SubmissionStatus
    {
        return SubmissionStatus::tryFrom($this->value);
    }
}
