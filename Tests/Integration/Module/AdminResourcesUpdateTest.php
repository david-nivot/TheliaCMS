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

namespace TheliaCMS\Tests\Integration\Module;

use Thelia\Core\Security\AccessManager;
use Thelia\Model\Profile;
use Thelia\Model\ProfileResourceQuery;
use Thelia\Model\ResourceQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * A right added by a version of the module reaches a site that updates to it.
 *
 * The rows of `resource` were only written on activation: a site that had the
 * module active before a right existed, and only ran `module:refresh` since,
 * never got the row, and the back office denied that right to every profile
 * but the super administrator, with no way to grant it.
 */
final class AdminResourcesUpdateTest extends CmsIntegrationTestCase
{
    public function testAnUpdateSeedsTheRightsAddedSinceTheActivation(): void
    {
        $resource = ResourceQuery::create()->findOneByCode(CmsResources::GENERATE);

        if (null !== $resource) {
            ProfileResourceQuery::create()->filterByResourceId($resource->getId())->delete();
            $resource->delete();
        }

        $migrations = TheliaCMS::everyMigration();
        $version = basename((string) end($migrations), '.sql');

        // From a version to itself: no migration runs, the rights are still seeded.
        (new TheliaCMS())->update($version, $version, $this->getPropelConnection());

        $seeded = ResourceQuery::create()->findOneByCode(CmsResources::GENERATE);

        self::assertNotNull($seeded, 'The right to generate is missing after an update.');
        self::assertSame('CMS: page generation', $seeded->setLocale('en_US')->getTitle());
        self::assertSame(
            0,
            ProfileResourceQuery::create()->filterByResourceId($seeded->getId())->filterByAccess(0, '>')->count(),
            'An existing profile starts with no access to it.',
        );

        foreach (CmsResources::all() as $code) {
            self::assertSame(1, ResourceQuery::create()->filterByCode($code)->count(), \sprintf('"%s" is seeded once, whatever the number of updates.', $code));
        }
    }

    public function testARightSplitOffAnOlderOneKeepsWhatEachProfileCouldDo(): void
    {
        $fixtures = new FixtureFactory($this->getPropelConnection());
        $builder = $fixtures->profile();
        $fixtures->profileResource($builder, CmsResources::FORM, [AccessManager::VIEW, AccessManager::DELETE]);
        $stranger = $fixtures->profile();

        foreach (array_keys(CmsResources::splitFrom()) as $code) {
            $resource = ResourceQuery::create()->findOneByCode($code);

            if (null !== $resource) {
                ProfileResourceQuery::create()->filterByResourceId($resource->getId())->delete();
                $resource->delete();
            }
        }

        $migrations = TheliaCMS::everyMigration();
        $version = basename((string) end($migrations), '.sql');
        (new TheliaCMS())->update($version, $version, $this->getPropelConnection());

        $before = $this->accessOf($builder, CmsResources::FORM);
        self::assertGreaterThan(0, $before);

        foreach (array_keys(CmsResources::splitFrom()) as $code) {
            self::assertSame($before, $this->accessOf($builder, $code), \sprintf('A profile that could open the forms keeps "%s".', $code));
            self::assertSame(0, $this->accessOf($stranger, $code), \sprintf('A profile that could not gets no "%s".', $code));
        }
    }

    private function accessOf(Profile $profile, string $code): int
    {
        $resource = ResourceQuery::create()->findOneByCode($code);
        self::assertNotNull($resource, \sprintf('"%s" is seeded.', $code));

        $granted = ProfileResourceQuery::create()
            ->filterByProfileId($profile->getId())
            ->filterByResourceId($resource->getId())
            ->findOne();

        return null === $granted ? 0 : (int) $granted->getAccess();
    }
}
