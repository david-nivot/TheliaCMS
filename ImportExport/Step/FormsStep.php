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

namespace TheliaCMS\ImportExport\Step;

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Form\FieldChoices;
use TheliaCMS\Form\FormCode;
use TheliaCMS\Form\Recipients;
use TheliaCMS\Form\RetentionPolicy;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Model\CmsFormFieldQuery;
use TheliaCMS\Model\CmsFormQuery;

/**
 * The forms of a file, with their fields.
 */
final readonly class FormsStep
{
    /**
     * @param array<string, int> $pageIds
     */
    public function import(
        SiteDocument $document,
        ImportOptions $options,
        ImportReport $report,
        array $pageIds,
        ConnectionInterface $connection,
    ): void {
        foreach ($document->forms() as $form) {
            $code = trim((string) ($form['code'] ?? ''));

            if ('' === $code) {
                $report->skipped('forms');

                continue;
            }

            // A page prints the code in the script that reports a sent form.
            if (!FormCode::isValid($code)) {
                $report->warn(\sprintf('A form whose code "%s" is not lowercase letters, digits and dashes was left out.', mb_substr($code, 0, FormCode::MAX_LENGTH)));
                $report->skipped('forms');

                continue;
            }

            $existing = CmsFormQuery::create()->findOneByCode($code);

            if (null !== $existing && !$options->replace) {
                $report->skipped('forms');

                continue;
            }

            $model = $existing ?? (new CmsForm())->setCode($code);
            $privacyUid = $form['privacy_policy_page'] ?? null;

            $model
                ->setActive(($form['active'] ?? true) ? 1 : 0)
                ->setRecipients(self::recipients($code, $form['recipients'] ?? null, $report))
                ->setStoreSubmissions(($form['store_submissions'] ?? true) ? 1 : 0)
                ->setRetentionDays(self::retentionDays($code, $form['retention_days'] ?? null, $report))
                ->setSendReceipt(($form['send_receipt'] ?? false) ? 1 : 0)
                ->setLeadEvent(($form['lead_event'] ?? true) ? 1 : 0)
                ->setPrivacyPolicyPageId(null !== $privacyUid ? ($pageIds[(string) $privacyUid] ?? null) : null)
                ->setDeletedAt(null);

            foreach ($form['translations'] ?? [] as $locale => $translation) {
                $model->setLocale((string) $locale)
                    ->setTitle((string) ($translation['title'] ?? $code))
                    ->setDescription($translation['description'] ?? null)
                    ->setSubmitLabel($translation['submit_label'] ?? null)
                    ->setSuccessMessage($translation['success_message'] ?? null)
                    ->setLegalNotice($translation['legal_notice'] ?? null)
                    ->setReceiptSubject($translation['receipt_subject'] ?? null)
                    ->setReceiptBody($translation['receipt_body'] ?? null);
            }

            $model->save($connection);

            // Same reasoning as the menu entries: the fields of a form are its
            // shape, and half of an old shape mixed with half of a new one is
            // not a form anyone designed.
            CmsFormFieldQuery::create()->filterByFormId($model->getId())->delete($connection);

            foreach ($form['fields'] ?? [] as $field) {
                $fieldModel = (new CmsFormField())
                    ->setFormId($model->getId())
                    ->setName((string) ($field['name'] ?? ''))
                    ->setType((string) ($field['type'] ?? 'text'))
                    ->setPosition((int) ($field['position'] ?? 0))
                    ->setRequired(($field['required'] ?? false) ? 1 : 0)
                    ->setOptions($field['options'] ?? null);

                foreach ($field['translations'] ?? [] as $locale => $translation) {
                    $fieldModel->setLocale((string) $locale)
                        ->setLabel($translation['label'] ?? null)
                        ->setPlaceholder($translation['placeholder'] ?? null)
                        ->setHelp($translation['help'] ?? null)
                        ->setChoices(FieldChoices::fromExport($translation['choices'] ?? null));
                }

                $fieldModel->save($connection);
            }

            null !== $existing ? $report->replaced('forms') : $report->created('forms');
        }
    }

    /**
     * The addresses of the file that are addresses: the back office refuses
     * the others, and a form sending to them sends nowhere.
     */
    private static function recipients(string $code, mixed $stored, ImportReport $report): ?string
    {
        if (!\is_string($stored)) {
            return null;
        }

        $rejected = Recipients::rejected($stored);

        if ([] !== $rejected) {
            $report->warn(\sprintf('Form "%s" was imported without the recipients that are not an email address: %s', $code, implode(', ', $rejected)));
        }

        $addresses = Recipients::parse($stored);

        return [] === $addresses ? null : Recipients::toStorage($addresses);
    }

    /**
     * Read as the form screen reads it: a negative duration, or one that is
     * not a number at all, would keep the answers forever.
     */
    private static function retentionDays(string $code, mixed $stored, ImportReport $report): int
    {
        $days = \is_int($stored) || (\is_string($stored) && 1 === preg_match('/^-?\d+$/', $stored)) ? (int) $stored : null;
        $kept = RetentionPolicy::normalize($days);

        if (null !== $stored && $kept !== $days) {
            $report->warn(\sprintf('The answers of form "%s" are kept %d days: the duration of the file is not one the form screen accepts.', $code, $kept));
        }

        return $kept;
    }
}
