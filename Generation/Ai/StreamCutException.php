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

use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use TheliaCMS\Generation\GenerationException;

/**
 * A stream that was cut half way: the provider failed after the first event
 * and may be tried again (a retryable failure), or the stream ended without
 * its last event. The core has retried by itself a failure that came before
 * any event, so that one is never this exception.
 *
 * It is a GenerationException with the message the stream always had, so a
 * caller that does not take the cut up fails as before.
 */
final class StreamCutException extends GenerationException
{
    /** The row of the usage log of the core written for the cut request, when the core named one. */
    public ?int $coreLogId = null;

    /** The failure of the provider, null when the stream just ended. */
    public ?AiProviderUnavailableException $unavailable = null;

    public static function after(AiProviderUnavailableException $unavailable): self
    {
        $cut = self::because($unavailable->getMessage(), $unavailable);
        $cut->coreLogId = $unavailable->logId;
        $cut->unavailable = $unavailable;

        return $cut;
    }

    public static function endedEarly(): self
    {
        return self::because('The AI gateway closed the stream before answering.');
    }
}
