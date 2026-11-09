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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\AdminLogQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\SubmissionPurger;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Model\CmsFormSubmissionQuery;
use TheliaCMS\Model\Map\CmsFormSubmissionTableMap;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * An answer is new, handled or archived: the team sees what is still to call
 * back, changes one or many at once, and the list leaves the archived ones a
 * tab away.
 */
final class SubmissionStatusTest extends AdminScreenTestCase
{
    public function testTheListShowsTheCurrentAnswersAndTheArchivedOnesATabAway(): void
    {
        $form = $this->createForm('status-tabs');
        $new = $this->submission($form, 'new', 'ann@example.test');
        $handled = $this->submission($form, 'handled', 'bob@example.test');
        $archived = $this->submission($form, 'archived', 'ann@example.test');
        $archivedElsewhere = $this->submission($form, 'archived', 'bob@example.test');

        $screen = $this->screen($this->answers($form));
        self::assertSame([$new, $handled], $this->idsOf($screen), 'The default list leaves the archived answers out.');
        self::assertSame('New', trim($screen->filter('[data-testid="cms-submission-status-'.$new.'"]')->text()));
        self::assertSame('2', $this->countOn($screen, 'current'));
        self::assertSame('2', $this->countOn($screen, 'archived'));
        self::assertSame('4', $this->countOn($screen, 'all'));

        self::assertSame([$archived, $archivedElsewhere], $this->idsOf($this->screen($this->answers($form).'?status=archived')));
        self::assertSame([$new, $handled, $archived, $archivedElsewhere], $this->idsOf($this->screen($this->answers($form).'?status=all')));

        $searched = $this->screen($this->answers($form).'?email=ann');
        self::assertSame([$new], $this->idsOf($searched));
        self::assertSame('1', $this->countOn($searched, 'archived'), 'The tabs count for the address searched: its archived answer is not out of sight.');
    }

    public function testTheExportTakesTheStatusShown(): void
    {
        $form = $this->createForm('status-export');
        $new = $this->submission($form, 'new');
        $archived = $this->submission($form, 'archived');

        self::assertSame([$new], $this->exportedIds($form, ''));
        self::assertSame([$archived], $this->exportedIds($form, '&status=archived'));
        self::assertSame([$new, $archived], $this->exportedIds($form, '&status=all'));
        self::assertStringContainsString('status=archived', (string) $this->screen($this->answers($form).'?status=archived')->filter('a[href*="/export.csv"]')->attr('href'));
    }

    public function testOneAnswerChangesStatusAndTheLogNamesNoAddress(): void
    {
        $form = $this->createForm('status-one');
        $answer = $this->submission($form, 'new', 'carol@example.test');
        $screen = $this->screen($this->answers($form).'?email=carol');
        $url = $this->answers($form).'/'.$answer.'/status';

        self::assertSame(302, $this->send('POST', $url, ['_token' => $this->tokenOfTheForm($screen, $url), 'to' => 'handled', 'email' => 'carol']));
        self::assertSame('handled', $this->statusOf($answer));
        self::assertStringContainsString('email=carol', (string) $this->client->getResponse()->headers->get('Location'), 'Back to the list it was changed on.');

        $log = AdminLogQuery::create()->filterByResource(CmsResources::FORM_ANSWERS)->filterByAction('UPDATE')->orderById('desc')->findOne();
        self::assertNotNull($log);
        self::assertStringContainsString('marked as handled', (string) $log->getMessage());
        self::assertStringNotContainsString('carol', (string) $log->getMessage());

        self::assertSame(404, $this->send('POST', $url, ['_token' => $this->tokenOfTheForm($screen, $url), 'to' => 'forgotten']), 'An unknown status is refused.');
    }

    public function testASelectionIsHandledArchivedOrDeletedInOneGesture(): void
    {
        $form = $this->createForm('status-bulk');
        $first = $this->submission($form, 'new');
        $second = $this->submission($form, 'new');
        $left = $this->submission($form, 'new');
        $other = $this->submission($this->createForm('status-bulk-other'), 'new');
        $bulk = $this->answers($form).'/bulk';
        $token = $this->tokenOfTheForm($this->screen($this->answers($form)), $bulk);

        self::assertSame(302, $this->send('POST', $bulk, ['_token' => $token, 'action' => 'handled', 'submissions' => [$first, $second, $other]]));
        self::assertSame(['handled', 'handled', 'new', 'new'], [$this->statusOf($first), $this->statusOf($second), $this->statusOf($left), $this->statusOf($other)], 'An answer of another form is out of reach.');

        self::assertSame(302, $this->send('POST', $bulk, ['_token' => $token, 'action' => 'archived', 'submissions' => [$first]]));
        self::assertSame('archived', $this->statusOf($first));

        $before = AdminLogQuery::create()->filterByResource(CmsResources::FORM_ANSWERS)->count();
        self::assertSame(302, $this->send('POST', $bulk, ['_token' => $token, 'action' => 'delete', 'submissions' => [$first, $second]]));
        self::assertNull($this->statusOf($first));
        self::assertNull($this->statusOf($second));
        self::assertSame('new', $this->statusOf($left));
        self::assertSame($before + 1, AdminLogQuery::create()->filterByResource(CmsResources::FORM_ANSWERS)->count(), 'One line for the gesture, not one per answer.');
    }

    public function testEachGestureTakesItsRightOnTheAnswers(): void
    {
        $form = $this->createForm('status-rights');
        $answer = $this->submission($form, 'new');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::FORM_ANSWERS => [AccessManager::VIEW, AccessManager::DELETE],
        ]));

        $screen = $this->screen($this->answers($form));
        self::assertSame(0, $screen->filter('form[action$="/status"]')->count(), 'No status button without the right to change the answers.');
        self::assertSame(0, $screen->filter('[data-testid="cms-submissions-bulk-action"] option[value="handled"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-submissions-bulk-action"] option[value="delete"]')->count());

        $token = $this->tokenOfTheScripts($screen);
        self::assertSame(403, $this->send('POST', $this->answers($form).'/'.$answer.'/status', ['_token' => $token, 'to' => 'handled']));
        self::assertSame(403, $this->send('POST', $this->answers($form).'/bulk', ['_token' => $token, 'action' => 'archived', 'submissions' => [$answer]]));
        self::assertSame('new', $this->statusOf($answer));

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::FORM_ANSWERS => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));
        self::assertSame(403, $this->send('POST', $this->answers($form).'/bulk', ['_token' => $token, 'action' => 'delete', 'submissions' => [$answer]]));
        self::assertSame('new', $this->statusOf($answer));
    }

    public function testAnArchivedAnswerAgesLikeTheOthers(): void
    {
        $form = $this->createForm('status-purge', retentionDays: 30);
        $old = $this->submission($form, 'archived', createdAt: new \DateTimeImmutable('-40 days'));
        $recent = $this->submission($form, 'archived');

        $this->getService(SubmissionPurger::class)->purge();

        self::assertNull($this->statusOf($old));
        self::assertSame('archived', $this->statusOf($recent));
    }

    private function answers(CmsForm $form): string
    {
        return '/admin/cms/forms/'.$form->getId().'/submissions';
    }

    /**
     * @return list<int>
     */
    private function idsOf(Crawler $screen): array
    {
        return $screen->filter('[data-testid^="cms-submission-"][id^="cms-submission-"]')->each(
            static fn (Crawler $card): int => (int) substr((string) $card->attr('id'), \strlen('cms-submission-')),
        );
    }

    private function countOn(Crawler $screen, string $tab): string
    {
        return trim($screen->filter('[data-testid="cms-submissions-status-'.$tab.'"] .badge')->text());
    }

    /**
     * @return list<int>
     */
    private function exportedIds(CmsForm $form, string $query): array
    {
        self::assertSame(200, $this->send('GET', $this->answers($form).'/export.json?x=1'.$query));
        $rows = json_decode((string) $this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function statusOf(int $id): ?string
    {
        CmsFormSubmissionTableMap::clearInstancePool();

        return CmsFormSubmissionQuery::create()->findPk($id)?->getStatus();
    }

    private function createForm(string $code, int $retentionDays = 0): CmsForm
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

    /**
     * Each one a second older than the one before, so the list, newest first,
     * shows them in the order they were made.
     */
    private function submission(CmsForm $form, string $status, ?string $email = null, ?\DateTimeImmutable $createdAt = null): int
    {
        static $offset = 0;

        $submission = (new CmsFormSubmission())
            ->setFormId($form->getId())
            ->setLocale($this->locale())
            ->setEmail($email)
            ->setData('[]')
            ->setStatus($status)
            ->setCreatedAt(($createdAt ?? new \DateTimeImmutable(\sprintf('-%d seconds', ++$offset)))->format('Y-m-d H:i:s'));
        $submission->save();

        return (int) $submission->getId();
    }
}
