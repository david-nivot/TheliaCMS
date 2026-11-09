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

use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\Admin\SubmissionFilters;
use TheliaCMS\Form\Admin\SubmissionRepository;
use TheliaCMS\Form\Admin\SubmissionSummary;
use TheliaCMS\Form\SubmissionStatus;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The answers counted: over time, by status and by the value of each question
 * with choices, for the list the filters keep.
 */
final class SubmissionSummaryTest extends AdminScreenTestCase
{
    public function testCountsByDayAcrossTheEndOfAMonthTheEmptyDaysIncluded(): void
    {
        $form = $this->createForm('summary-days');
        $this->submission($form, '2026-01-30 23:59:59');
        $this->submission($form, '2026-01-31 08:00:00');
        $this->submission($form, '2026-02-01 00:00:00');
        $this->submission($form, '2026-02-01 18:30:00');
        $this->submission($form, '2026-02-03 09:00:00');
        $this->submission($form, '2026-02-04 09:00:00', status: 'archived');

        $summary = $this->summaryOf($form, new SubmissionFilters(from: new \DateTimeImmutable('2026-01-30'), to: new \DateTimeImmutable('2026-02-03')));

        self::assertSame(SubmissionSummary::BY_DAY, $summary['by']);
        self::assertSame(['2026-01-30' => 1, '2026-01-31' => 1, '2026-02-01' => 2, '2026-02-02' => 0, '2026-02-03' => 1], $this->periods($summary));
        self::assertSame(5, $summary['total']);
        self::assertSame(2, $summary['most']);
    }

    public function testALongerPeriodIsCountedByWeekFromMondayOrByMonth(): void
    {
        $form = $this->createForm('summary-weeks');
        $this->submission($form, '2026-01-31 10:00:00');
        $this->submission($form, '2026-02-01 10:00:00');
        $this->submission($form, '2026-02-02 10:00:00');

        $weeks = $this->summaryOf($form, new SubmissionFilters(from: new \DateTimeImmutable('2026-01-01'), to: new \DateTimeImmutable('2026-03-31')));
        self::assertSame(SubmissionSummary::BY_WEEK, $weeks['by']);
        self::assertSame('2025-12-29', array_key_first($this->periods($weeks)), 'The first week starts on the Monday before the first day.');
        self::assertSame(2, $this->periods($weeks)['2026-01-26'], 'Saturday and Sunday close the week of Monday the 26th.');
        self::assertSame(1, $this->periods($weeks)['2026-02-02']);
        self::assertSame(3, array_sum($this->periods($weeks)));

        $months = $this->summaryOf($form, new SubmissionFilters(from: new \DateTimeImmutable('2026-01-01'), to: new \DateTimeImmutable('2026-12-31')));
        self::assertSame(SubmissionSummary::BY_MONTH, $months['by']);
        self::assertCount(12, $months['periods']);
        self::assertSame(['2026-01-01' => 1, '2026-02-01' => 2], \array_slice($this->periods($months), 0, 2));
        self::assertSame('janvier 2026', $this->summaryOf($form, new SubmissionFilters(from: new \DateTimeImmutable('2026-01-01'), to: new \DateTimeImmutable('2026-12-31')), 'fr_FR')['periods'][0]['label']);
    }

    public function testSplitsEachQuestionWithChoicesTheValuesMostGivenFirst(): void
    {
        $form = $this->createForm('summary-split');
        $this->submission($form, '2026-03-01 10:00:00', ['topic' => 'Support', 'country' => 'France', 'message' => 'Hello']);
        $this->submission($form, '2026-03-02 10:00:00', ['topic' => 'Sales', 'country' => 'Belgique']);
        $this->submission($form, '2026-03-03 10:00:00', ['topic' => 'Sales']);
        $this->submission($form, '2026-03-04 10:00:00', ['message' => 'No topic']);

        $questions = $this->summaryOf($form, new SubmissionFilters())['questions'];

        self::assertSame(['topic', 'country'], array_column($questions, 'name'), 'A text answer is not counted as a choice.');
        self::assertSame(3, $questions[0]['answered'], 'An answer without the question does not count for it.');
        self::assertSame([['value' => 'Sales', 'count' => 2], ['value' => 'Support', 'count' => 1]], $questions[0]['values']);
        self::assertSame([['value' => 'Belgique', 'count' => 1], ['value' => 'France', 'count' => 1]], $questions[1]['values'], 'Equal counts in the order of the values.');
    }

    public function testFollowsTheFiltersAndCountsEveryStatus(): void
    {
        $form = $this->createForm('summary-filters');
        $this->submission($form, '2026-03-01 10:00:00', ['topic' => 'Sales'], 'ann@example.test');
        $this->submission($form, '2026-03-02 10:00:00', ['topic' => 'Sales'], status: 'handled');
        $this->submission($form, '2026-03-03 10:00:00', ['topic' => 'Support']);
        $this->submission($form, '2026-03-04 10:00:00', ['topic' => 'Sales'], status: 'archived');

        $current = $this->summaryOf($form, new SubmissionFilters());
        self::assertSame(3, $current['total'], 'The current tab leaves the archived answer out.');
        self::assertSame(['new' => 2, 'handled' => 1, 'archived' => 1], $current['statuses'], 'The statuses count every answer the other filters keep.');

        $sales = $this->summaryOf($form, new SubmissionFilters(answers: ['topic' => 'Sales']));
        self::assertSame(2, $sales['total']);
        self::assertSame(['new' => 1, 'handled' => 1, 'archived' => 1], $sales['statuses']);
        self::assertSame([['value' => 'Sales', 'count' => 2]], $sales['questions'][0]['values']);

        $archived = $this->summaryOf($form, new SubmissionFilters(status: SubmissionStatus::Archived));
        self::assertSame(['2026-03-04' => 1], $this->periods($archived));

        self::assertSame(1, $this->summaryOf($form, new SubmissionFilters(email: 'ann'))['total']);
        self::assertSame(2, $this->summaryOf($form, new SubmissionFilters(to: new \DateTimeImmutable('2026-03-02')))['total']);
    }

    public function testTheScreenKeepsTheFiltersAndTakesTheRightToReadTheAnswers(): void
    {
        $form = $this->createForm('summary-screen');
        $this->submission($form, '2026-03-01 10:00:00', ['topic' => 'Sales']);
        $this->submission($form, '2026-03-02 10:00:00', ['topic' => 'Support']);
        $this->submission($form, '2026-02-02 10:00:00', ['topic' => 'Support']);
        $summary = $this->answers($form).'/summary';

        $screen = $this->screen($summary.'?from=2026-03-01&lang=fr_FR');
        self::assertSame('2', trim($screen->filter('[data-testid="cms-summary-tile-shown"]')->text()));
        self::assertSame('Réponses par jour', trim($screen->filter('[data-testid="cms-summary-periods"]')->ancestors()->filter('.card')->first()->filter('.card-header')->text()));
        self::assertStringContainsString('/summary', (string) $screen->filter('[data-testid="cms-submissions-filters"]')->attr('action'), 'The filter bar stays on the summary.');
        self::assertStringContainsString('from=2026-03-01', (string) $screen->filter('[data-testid="cms-summary-answers-link"]')->attr('href'));
        $link = urldecode((string) $screen->filter('[data-testid="cms-summary-question-topic"] a')->first()->attr('href'));
        self::assertStringContainsString('answer[topic]=Sales', $link, 'A value leads to its answers.');
        self::assertStringContainsString('from=2026-03-01', $link);

        $list = $this->screen($this->answers($form).'?from=2026-03-01');
        self::assertStringContainsString('from=2026-03-01', (string) $list->filter('[data-testid="cms-submissions-summary-link"]')->attr('href'));

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::FORM => [AccessManager::VIEW],
        ]));
        self::assertSame(403, $this->send('GET', $summary), 'Counting the answers takes the right to read them.');
    }

    /**
     * @return array{total: int, statuses: array<string, int>, by: string, periods: list<array{start: \DateTimeImmutable, label: string, count: int}>, most: int, questions: list<array{name: string, label: string, answered: int, values: list<array{value: string, count: int}>}>}
     */
    private function summaryOf(CmsForm $form, SubmissionFilters $filters, string $locale = 'en_US'): array
    {
        return $this->getService(SubmissionSummary::class)->of($this->getService(SubmissionRepository::class)->search($form, $filters), $locale);
    }

    /**
     * @param array{periods: list<array{start: \DateTimeImmutable, label: string, count: int}>} $summary
     *
     * @return array<string, int> by first day of the period
     */
    private function periods(array $summary): array
    {
        $periods = [];

        foreach ($summary['periods'] as $period) {
            $periods[$period['start']->format('Y-m-d')] = $period['count'];
        }

        return $periods;
    }

    private function answers(CmsForm $form): string
    {
        return '/admin/cms/forms/'.$form->getId().'/submissions';
    }

    private function createForm(string $code): CmsForm
    {
        $form = new CmsForm();

        $this->getService(CmsFormWriter::class)->save($form, $this->locale(), new FormSettings(
            code: $code,
            active: true,
            recipients: '',
            storeSubmissions: true,
            retentionDays: 0,
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
     * @param array<string, string> $values by name of the question: topic a list, country a country, message a text
     */
    private function submission(CmsForm $form, string $createdAt, array $values = [], ?string $email = null, string $status = 'new'): void
    {
        $types = ['topic' => 'select', 'country' => 'country', 'message' => 'textarea'];
        $answers = [];

        foreach ($values as $name => $value) {
            $answers[] = ['name' => $name, 'type' => $types[$name], 'label' => ucfirst($name), 'value' => $value];
        }

        (new CmsFormSubmission())
            ->setFormId($form->getId())
            ->setLocale($this->locale())
            ->setEmail($email)
            ->setData(json_encode($answers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE))
            ->setStatus($status)
            ->setCreatedAt($createdAt)
            ->save();
    }
}
