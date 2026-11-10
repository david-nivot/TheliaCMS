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

namespace TheliaCMS\Tests\Support;

use Thelia\Domain\Ai\AiResponse;

/**
 * The answers of the generator of components that are not a component: the
 * site already has it (`existing`), or it is a variant of one of the site
 * (`copy`), every field of the component left empty as the schema asks.
 */
final class ComponentOutcome
{
    public static function existing(string $name, string $reason): AiResponse
    {
        return self::answer('existing', $name, $reason);
    }

    public static function copy(string $name, string $reason): AiResponse
    {
        return self::answer('copy', $name, $reason);
    }

    private static function answer(string $outcome, string $name, string $reason): AiResponse
    {
        return FakeAiGateway::response((string) json_encode([
            'outcome' => $outcome,
            'existing' => $name,
            'reason' => $reason,
            'code' => '',
            'label' => '',
            'description' => '',
            'props' => '',
            'sample' => '',
            'template' => '',
            'css' => '',
        ], \JSON_THROW_ON_ERROR));
    }
}
