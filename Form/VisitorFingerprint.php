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

namespace TheliaCMS\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the address a request came from into something that can be stored.
 *
 * An IP address is personal data, and a form that keeps one for a year keeps a
 * year of personal data it never needed. What the site actually needs is to
 * recognise the same sender twice, which a keyed hash does.
 *
 * Keyed, not plain: a bare SHA-256 of an IPv4 address is reversed by hashing
 * the four billion of them, which is minutes of work.
 */
final readonly class VisitorFingerprint
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $applicationSecret,
    ) {
    }

    /**
     * Stored with a submission.
     */
    public function of(?string $ipAddress): string
    {
        return hash_hmac(
            'sha256',
            (string) $ipAddress,
            hash_hmac('sha256', 'thelia-cms.visitor', $this->applicationSecret),
        );
    }
}
