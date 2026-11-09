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

use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Thelia\Model\AdminLogQuery;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\Admin\SubmissionFilters;
use TheliaCMS\Form\Admin\SubmissionRepository;
use TheliaCMS\Form\SubmissionStatus;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Model\Map\CmsFormSubmissionTableMap;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The file a team opens in Excel: split on the separator of the language of
 * the screen, narrowed by the filters of the list, written a batch at a time.
 */
final class SubmissionExportFileTest extends AdminScreenTestCase
{
    use CountsStatements;

    public function testTheCsvIsSplitTheWayExcelReadsItInTheLanguageOfTheScreen(): void
    {
        $form = $this->createForm('export-excel');
        $sales = $this->submission($form, '2026-03-10 09:00:00', ['topic' => 'Sales', 'message' => '=HYPERLINK("http://evil.test")']);
        $this->submission($form, '2026-03-11 09:00:00', ['topic' => 'Support', 'message' => 'Hello, world']);

        $english = $this->csv($form, '?answer[topic]=Sales');
        self::assertStringStartsWith("\u{FEFF}", $english[0], 'The mark a spreadsheet needs to read accents is kept.');
        self::assertSame('Reference,"Received on","Email address",Language,Topic,Message', $english[1]);
        self::assertSame(2, \count($english) - 1, 'One heading and the one answer about sales: the filters apply.');
        self::assertStringStartsWith($sales.',', $english[2]);
        self::assertStringContainsString('"\'=HYPERLINK(""http://evil.test"")"', $english[2], 'A formula is still defused.');

        $french = $this->csv($form, '?lang=fr_FR');
        self::assertSame('Référence;"Reçue le";"Adresse e-mail";Langue;Topic;Message', $french[1]);
        self::assertStringEndsWith(';Support;"Hello, world"', $french[2], 'A comma inside an answer is left alone, the semicolon splits.');

        $cache = $this->client->getResponse()->headers;
        self::assertTrue($cache->hasCacheControlDirective('no-store') && $cache->hasCacheControlDirective('private'), 'Personal data, never kept by a cache.');
        $log = AdminLogQuery::create()->filterByResource(CmsResources::FORM_ANSWERS)->filterByAction('EXPORT')->orderById('desc')->findOne();
        self::assertNotNull($log);
        self::assertStringContainsString('2 submission(s) exported as csv', (string) $log->getMessage());
    }

    public function testTheExportReadsTheAnswersABatchAtATime(): void
    {
        $form = $this->createForm('export-batches');
        $total = 1201;
        $values = [];

        for ($i = 0; $i < $total; ++$i) {
            $values[] = \sprintf("(%d, 'en_US', '[]', 'new', '%s', '%s')", $form->getId(), $at = date('Y-m-d H:i:s', 1767225600 + $i * 60), $at);
        }

        $connection = Propel::getConnection(CmsFormSubmissionTableMap::DATABASE_NAME);
        $connection->exec('INSERT INTO cms_form_submission (form_id, locale, data, status, created_at, updated_at) VALUES '.implode(',', $values));

        $repository = $this->getService(SubmissionRepository::class);
        $search = $repository->search($form, new SubmissionFilters(everyStatus: true));
        $rows = [];

        $statements = $this->statementsFor(static function () use ($repository, $search, &$rows): void {
            foreach ($repository->each($search) as $row) {
                $rows[] = $row;
            }
        });

        self::assertCount($total, $rows);
        self::assertSame(1 + 3, $statements, 'The ids, then three batches of at most 500 rows.');
        self::assertSame(date('Y-m-d H:i:s', 1767225600 + ($total - 1) * 60), $rows[0]['created_at'], 'Newest first, as on the screen.');
        self::assertSame(date('Y-m-d H:i:s', 1767225600), $rows[$total - 1]['created_at']);
        self::assertSame(SubmissionStatus::New, $rows[0]['status']);
        self::assertSame($total, \count(array_unique(array_column($rows, 'id'))), 'No row twice.');
    }

    /**
     * @return list<string> the file, then its lines
     */
    private function csv(CmsForm $form, string $query): array
    {
        self::assertSame(200, $this->send('GET', '/admin/cms/forms/'.$form->getId().'/submissions/export.csv'.$query));
        $file = (string) $this->client->getInternalResponse()->getContent();

        return [$file, ...array_values(array_filter(explode("\n", str_replace(["\u{FEFF}", "\r"], '', $file))))];
    }

    private function countingConnection(): ConnectionWrapper
    {
        $connection = Propel::getConnection(CmsFormSubmissionTableMap::DATABASE_NAME);
        \assert($connection instanceof ConnectionWrapper);

        return $connection;
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
     * @param array<string, string> $values by name of the question
     */
    private function submission(CmsForm $form, string $createdAt, array $values): int
    {
        $answers = [];

        foreach ($values as $name => $value) {
            $answers[] = ['name' => $name, 'type' => 'topic' === $name ? 'select' : 'textarea', 'label' => ucfirst($name), 'value' => $value];
        }

        $submission = (new CmsFormSubmission())
            ->setFormId($form->getId())
            ->setLocale($this->locale())
            ->setData(json_encode($answers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE))
            ->setStatus('new')
            ->setCreatedAt($createdAt);
        $submission->save();

        return (int) $submission->getId();
    }
}
