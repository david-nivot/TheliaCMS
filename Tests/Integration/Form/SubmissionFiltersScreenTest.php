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
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FieldData;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\Admin\SubmissionRepository;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The answers narrowed by the day they arrived and by the value given to a
 * question with choices: the screen, its tabs, its pages, its gestures and the
 * export all read the same filters, so what is exported is what was shown.
 */
final class SubmissionFiltersScreenTest extends AdminScreenTestCase
{
    public function testEachFilterAloneAndTogetherOnTheScreenAndInTheExport(): void
    {
        $form = $this->createForm('filters-each');
        $this->choiceField($form, 'topic', FieldType::Select, "Sales\nSupport");
        $marchSales = $this->submission($form, '2026-03-10 09:00:00', ['topic' => 'Sales'], 'ann@example.test');
        $marchSupport = $this->submission($form, '2026-03-31 23:59:59', ['topic' => 'Support']);
        $aprilSales = $this->submission($form, '2026-04-01 00:00:00', ['topic' => 'Sales']);
        $aprilSalesArchived = $this->submission($form, '2026-04-02 10:00:00', ['topic' => 'Sales'], status: 'archived');
        $aprilSupport = $this->submission($form, '2026-04-03 10:00:00', ['topic' => 'Support']);

        $cases = [
            'the period, both days included' => ['?from=2026-03-01&to=2026-03-31', [$marchSupport, $marchSales]],
            'from a day on' => ['?from=2026-04-01', [$aprilSupport, $aprilSales]],
            'up to a day' => ['?to=2026-03-31', [$marchSupport, $marchSales]],
            'the value of a question' => ['?answer[topic]=Sales', [$aprilSales, $marchSales]],
            'the value and the period' => ['?answer[topic]=Sales&from=2026-03-01&to=2026-03-31', [$marchSales]],
            'the value and a status' => ['?answer[topic]=Sales&status=archived', [$aprilSalesArchived]],
            'the value and the address' => ['?answer[topic]=Sales&email=ann', [$marchSales]],
            'a value nobody gave' => ['?answer[topic]=Billing', []],
        ];

        foreach ($cases as $case => [$query, $expected]) {
            self::assertSame($expected, $this->idsOf($this->screen($this->answers($form).$query)), 'Screen, '.$case.'.');
            self::assertSame($expected, $this->exportedIds($form, $query), 'Export, '.$case.'.');
        }

        $screen = $this->screen($this->answers($form).'?answer[topic]=Sales&from=2026-04-01');
        self::assertSame('1', $this->countOn($screen, 'current'), 'The tabs count for the other filters: the answer of April about support is left out.');
        self::assertSame('1', $this->countOn($screen, 'archived'));
        self::assertStringContainsString('answer%5Btopic%5D=Sales', (string) $screen->filter('a[href*="/export.csv"]')->attr('href'));
        self::assertStringContainsString('from=2026-04-01', (string) $screen->filter('[data-testid="cms-submissions-status-archived"]')->attr('href'));
        self::assertSame(2, $screen->filter('[data-testid="cms-submissions-active-filters"] a.badge')->count(), 'One chip per filter.');
        self::assertSame('Sales', $screen->filter('[data-testid="cms-submissions-answer-topic"] option[selected]')->attr('value'));
    }

    public function testARenamedChoiceSaysItNoLongerFindsItsOlderAnswers(): void
    {
        $form = $this->createForm('filters-renamed');
        $field = $this->choiceField($form, 'topic', FieldType::Radio, "Sales\nSupport");
        $old = $this->submission($form, '2026-03-10 09:00:00', ['topic' => 'Sales']);
        $this->choiceField($form, 'topic', FieldType::Radio, "Sales team\nSupport", $field);
        $new = $this->submission($form, '2026-03-11 09:00:00', ['topic' => 'Sales team']);

        self::assertSame([$new], $this->idsOf($this->screen($this->answers($form).'?answer[topic]=Sales%20team')), 'The new wording does not find the old answers.');
        self::assertSame([$old], $this->idsOf($this->screen($this->answers($form).'?answer[topic]=Sales')));

        $options = $this->screen($this->answers($form))->filter('[data-testid="cms-submissions-answer-topic"] option')
            ->each(static fn (Crawler $option): string => trim($option->text()));
        self::assertSame(['Any answer', 'Sales (no longer offered)', 'Sales team'], $options);
    }

    public function testTheFiltersAreKeptByThePagesAndByTheGestures(): void
    {
        $form = $this->createForm('filters-pages');
        $this->choiceField($form, 'topic', FieldType::Select, "Sales\nSupport");

        for ($i = 0; $i <= SubmissionRepository::PER_PAGE; ++$i) {
            $this->submission($form, \sprintf('2026-03-01 10:%02d:00', $i), ['topic' => 'Sales']);
        }

        $answer = $this->submission($form, '2026-02-01 10:00:00', ['topic' => 'Support']);

        $query = '?answer[topic]=Sales&from=2026-03-01';
        $screen = $this->screen($this->answers($form).$query);
        $second = (string) $screen->filter('.pagination a.page-link')->last()->attr('href');
        self::assertStringContainsString('answer%5Btopic%5D=Sales', $second);
        self::assertStringContainsString('from=2026-03-01', $second);
        self::assertCount(1, $this->idsOf($this->screen($second)), 'The second page holds the 26th answer of the filtered list.');

        $url = $this->answers($form).'/'.$answer.'/status';
        self::assertSame(302, $this->send('POST', $url, [
            '_token' => $this->tokenOfTheForm($screen, $this->answers($form).'/bulk'),
            'to' => 'handled',
            'answer' => ['topic' => 'Sales'],
            'from' => '2026-03-01',
        ]));
        $location = urldecode((string) $this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('answer[topic]=Sales', $location, 'Back to the list it was changed on.');
        self::assertStringContainsString('from=2026-03-01', $location);

        self::assertSame(1, $screen->filter('#cms-submissions-bulk input[type="hidden"][name="answer[topic]"][value="Sales"]')->count(), 'The bulk form carries the answer asked for.');
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
        self::assertSame(200, $this->send('GET', $this->answers($form).'/export.json'.$query));
        $rows = json_decode((string) $this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
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

    private function choiceField(CmsForm $form, string $name, FieldType $type, string $choices, ?CmsFormField $field = null): CmsFormField
    {
        return $this->getService(CmsFormWriter::class)->saveField($form, $field, $this->locale(), new FieldData(
            type: $type,
            name: $name,
            label: ucfirst($name),
            required: false,
            placeholder: '',
            help: '',
            choices: $choices,
            rows: 0,
        ));
    }

    /**
     * @param array<string, string> $values by name of the question
     */
    private function submission(CmsForm $form, string $createdAt, array $values, ?string $email = null, string $status = 'new'): int
    {
        $answers = [];

        foreach ($values as $name => $value) {
            $answers[] = ['name' => $name, 'type' => FieldType::Select->value, 'label' => ucfirst($name), 'value' => $value];
        }

        $submission = (new CmsFormSubmission())
            ->setFormId($form->getId())
            ->setLocale($this->locale())
            ->setEmail($email)
            ->setData(json_encode($answers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE))
            ->setStatus($status)
            ->setCreatedAt($createdAt);
        $submission->save();

        return (int) $submission->getId();
    }
}
