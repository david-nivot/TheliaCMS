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

namespace TheliaCMS\Tests\Unit\Content;

use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\Theme\ComponentPropsReader;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use Twig\Environment;

/**
 * An active front theme of the name given, with the `config/theliacms.yaml`
 * given or none, for the time of a test.
 */
trait InTheme
{
    /**
     * @param \Closure(ThemeComponentSections): void $test
     */
    private function inTheme(string $name, ?string $declaration, \Closure $test): void
    {
        $directory = sys_get_temp_dir().'/theliacms-heading-'.bin2hex(random_bytes(4)).'/'.$name;
        mkdir($directory.'/config', 0o777, true);

        try {
            if (null !== $declaration) {
                file_put_contents($directory.'/config/theliacms.yaml', $declaration);
            }

            $theme = $this->createStub(TemplateDefinition::class);
            $theme->method('getAbsolutePath')->willReturn($directory);
            $theme->method('getParentList')->willReturn([]);

            $templates = $this->createStub(TemplateHelperInterface::class);
            $templates->method('getActiveFrontTemplate')->willReturn($theme);

            $test(new ThemeComponentSections(
                $templates,
                new ComponentPropsReader($this->createStub(Environment::class), $this->createStub(UrlGeneratorInterface::class)),
                $this->createStub(ComponentRendererInterface::class),
                new NullLogger(),
            ));
        } finally {
            @unlink($directory.'/config/theliacms.yaml');
            @rmdir($directory.'/config');
            @rmdir($directory);
            @rmdir(\dirname($directory));
        }
    }
}
