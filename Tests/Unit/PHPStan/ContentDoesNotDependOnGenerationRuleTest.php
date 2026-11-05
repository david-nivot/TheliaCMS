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

namespace TheliaCMS\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use TheliaCMS\Tests\PHPStan\ContentDoesNotDependOnGenerationRule;

/**
 * @extends RuleTestCase<ContentDoesNotDependOnGenerationRule>
 */
final class ContentDoesNotDependOnGenerationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ContentDoesNotDependOnGenerationRule();
    }

    public function testAnImportAndAFullNameOfGenerationAreRefused(): void
    {
        $this->analyse([__DIR__.'/../../PHPStan/data/content-uses-generation.php'], [
            ['TheliaCMS\Generation\GenerationLog is a class of Generation: Content does not depend on Generation (ADR 12).', 17],
            ['TheliaCMS\Generation\GenerationLog is a class of Generation: Content does not depend on Generation (ADR 12).', 23],
            ['TheliaCMS\Generation\GenerationException is a class of Generation: Content does not depend on Generation (ADR 12).', 28],
        ]);
    }

    public function testAFileOfContentThatLeavesGenerationAloneIsAccepted(): void
    {
        $this->analyse([__DIR__.'/../../PHPStan/data/content-clean.php'], []);
    }

    public function testAFileOutsideContentMayUseGeneration(): void
    {
        $this->analyse([__DIR__.'/../../PHPStan/data/outside-content-uses-generation.php'], []);
    }
}
