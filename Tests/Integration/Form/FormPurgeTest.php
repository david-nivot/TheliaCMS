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

namespace TheliaCMS\Tests\Integration\Form;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Maintenance\MaintenancePurgeEvent;
use Thelia\Core\Event\TheliaEvents;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\SubmissionPurger;
use TheliaCMS\Form\Throttle;
use TheliaCMS\Form\ThrottleScope;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Model\CmsFormSubmissionQuery;
use TheliaCMS\Model\Map\CmsFormSubmissionTableMap;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Each form keeps its answers as long as it says and no longer, a form somebody
 * deleted included, and the purge runs with the maintenance of the shop.
 */
final class FormPurgeTest extends CmsIntegrationTestCase
{
    public function testEachFormLosesTheAnswersPastItsRetentionAndKeepsTheRest(): void
    {
        $now = new \DateTimeImmutable('2026-11-04 12:00:00');
        $month = $this->createForm('purge-month', 30);
        $forever = $this->createForm('purge-forever', 0);
        $deleted = $this->createForm('purge-deleted', 30);
        $this->getService(CmsFormWriter::class)->delete($deleted);

        $old = $this->submission($month, $now->modify('-31 days'));
        $recent = $this->submission($month, $now->modify('-29 days'));
        $kept = $this->submission($forever, $now->modify('-3000 days'));
        $orphan = $this->submission($deleted, $now->modify('-31 days'));

        $purged = $this->getService(SubmissionPurger::class)->purge($now);

        self::assertGreaterThanOrEqual(2, $purged);
        self::assertFalse($this->exists($old));
        self::assertTrue($this->exists($recent));
        self::assertTrue($this->exists($kept));
        self::assertFalse($this->exists($orphan), 'Deleting a form is not a way to keep its answers.');
    }

    public function testTheMaintenanceOfTheShopPurgesTheAnswers(): void
    {
        $form = $this->createForm('purge-maintenance', 1);
        $old = $this->submission($form, new \DateTimeImmutable('-2 days'));

        $event = new MaintenancePurgeEvent();
        $this->getService(EventDispatcherInterface::class)->dispatch($event, TheliaEvents::MAINTENANCE_PURGE);

        self::assertFalse($this->exists($old));
    }

    public function testADryRunOfTheMaintenanceDeletesNothing(): void
    {
        $form = $this->createForm('purge-dry-run', 1);
        $old = $this->submission($form, new \DateTimeImmutable('-2 days'));

        $event = new MaintenancePurgeEvent(dryRun: true);
        $this->getService(EventDispatcherInterface::class)->dispatch($event, TheliaEvents::MAINTENANCE_PURGE);

        self::assertTrue($this->exists($old));
        self::assertMatchesRegularExpression('/^<comment>CMS form submissions past their retention:<\/comment> <info>[1-9]\d* to delete<\/info>$/', $event->getResults()[array_key_first(array_filter($event->getResults(), static fn (string $result): bool => str_contains($result, 'CMS form submissions')))] ?? '');
    }

    /**
     * A counter whose window has closed counts from zero again: it is only a
     * row, and the maintenance drops it.
     */
    public function testTheMaintenanceDropsTheCountersWhoseWindowHasClosed(): void
    {
        $this->getPropelConnection()->exec("DELETE FROM cms_form_throttle WHERE scope = 'receipt'");
        $throttle = $this->getService(Throttle::class);
        $throttle->hit(ThrottleScope::Receipt, 'closed@example.com', 3, 60, new \DateTimeImmutable('-2 minutes'));
        $throttle->hit(ThrottleScope::Receipt, 'open@example.com', 3, 3600);

        $this->getService(EventDispatcherInterface::class)->dispatch(new MaintenancePurgeEvent(), TheliaEvents::MAINTENANCE_PURGE);

        self::assertSame(1, (int) $this->getPropelConnection()->query("SELECT COUNT(*) FROM cms_form_throttle WHERE scope = 'receipt'")->fetchColumn());
    }

    private function createForm(string $code, int $retentionDays): CmsForm
    {
        $form = new CmsForm();

        $this->getService(CmsFormWriter::class)->save($form, $this->locale(), new FormSettings(
            code: $code,
            active: true,
            recipients: '',
            storeSubmissions: true,
            retentionDays: $retentionDays,
            sendReceipt: false,
            privacyPolicyPageId: null,
            leadEvent: false,
            title: $code,
            description: '',
            submitLabel: '',
            successMessage: '',
            legalNotice: '',
            receiptSubject: '',
            receiptBody: '',
        ));

        return $form;
    }

    private function submission(CmsForm $form, \DateTimeImmutable $createdAt): int
    {
        $submission = (new CmsFormSubmission())
            ->setFormId($form->getId())
            ->setLocale($this->locale())
            ->setData('{}')
            ->setCreatedAt($createdAt->format('Y-m-d H:i:s'));
        $submission->save();

        return (int) $submission->getId();
    }

    private function exists(int $id): bool
    {
        CmsFormSubmissionTableMap::clearInstancePool();

        return null !== CmsFormSubmissionQuery::create()->findPk($id);
    }
}
