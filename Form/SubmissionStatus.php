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

/**
 * Where an answer to a form stands for the team that reads it: still to answer,
 * answered, or put away. A status never holds an answer back from the purge:
 * an archived answer ages like the others.
 */
enum SubmissionStatus: string
{
    case New = 'new';
    case Handled = 'handled';
    case Archived = 'archived';

    /** The English label, translated in the module's domain. */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Handled => 'Handled',
            self::Archived => 'Archived',
        };
    }

    /** The class of its badge in the back office. */
    public function badge(): string
    {
        return match ($this) {
            self::New => 'text-bg-primary',
            self::Handled => 'text-bg-success',
            self::Archived => 'text-bg-secondary',
        };
    }
}
