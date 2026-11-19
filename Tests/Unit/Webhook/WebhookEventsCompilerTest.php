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

namespace TheliaCMS\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TheliaCMS\Webhook\PagePayload;
use TheliaCMS\Webhook\SubmissionPayload;
use TheliaCMS\Webhook\WebhookEvents;
use TheliaCMS\Webhook\WebhookEventsCompiler;

final class WebhookEventsCompilerTest extends TestCase
{
    public function testTheEventsOfAnotherModuleAreKept(): void
    {
        $other = ['shop_review.created' => ['resource' => \stdClass::class, 'groups' => ['review:read']]];
        $container = new ContainerBuilder();
        $container->setParameter(WebhookEventsCompiler::PARAMETER, $other);

        (new WebhookEventsCompiler())->process($container);

        self::assertSame(
            $other + WebhookEvents::definitions(),
            $container->getParameter(WebhookEventsCompiler::PARAMETER),
        );
    }

    public function testTheEventsAreDeclaredWhenNothingWasBefore(): void
    {
        $container = new ContainerBuilder();

        (new WebhookEventsCompiler())->process($container);

        self::assertSame(WebhookEvents::definitions(), $container->getParameter(WebhookEventsCompiler::PARAMETER));
    }

    public function testEachEventNamesItsPayload(): void
    {
        $page = ['resource' => PagePayload::class, 'groups' => [PagePayload::GROUP]];

        self::assertSame(
            [
                'cms_page.published' => $page,
                'cms_page.unpublished' => $page,
                'cms_form.submitted' => ['resource' => SubmissionPayload::class, 'groups' => [SubmissionPayload::GROUP]],
            ],
            WebhookEvents::definitions(),
        );
    }
}
