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

namespace TheliaCMS\Generation\Ai;

/**
 * Why a usage of the module cannot run now, as the AI layer of Thelia says it.
 */
enum AiUnavailability: string
{
    /** The master switch of the AI layer is off. */
    case Disabled = 'disabled';

    /** The usage is turned off in its own settings. */
    case UsageOff = 'usage_off';

    /** No active connection with a model serves the usage. */
    case NoModel = 'no_model';

    /** The key of the connection cannot be decrypted. */
    case KeyUnreadable = 'key_unreadable';

    /** The shop only allows providers in the European Union, and the connection of the usage is not one. */
    case Jurisdiction = 'jurisdiction';

    /** The usage is not declared: the module and the core do not agree on its identifier. */
    case Unknown = 'unknown';
}
