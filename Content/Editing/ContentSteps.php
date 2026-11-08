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

namespace TheliaCMS\Content\Editing;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\AdminQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Model\CmsContentStep;
use TheliaCMS\Model\CmsContentStepQuery;

/**
 * Undo and Redo on the content screen: each change made from the screen keeps
 * the draft before and after it, one stack per content and language, shared
 * by whoever edits it. A step is played back only on the draft it left: when
 * anything else wrote the draft since (another tab, another editor, an
 * import), it is refused and nothing is written.
 *
 * Only the screen records steps; imports and commands write none.
 */
final readonly class ContentSteps
{
    /** Steps kept per content and language; the oldest go first. */
    public const int KEPT = 30;

    public function __construct(
        private ContentWriter $writer,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * Runs a change of the draft and keeps it as a step when it changed the
     * draft. A failed change, or one that left the draft as it was, keeps
     * nothing. A new step drops the steps Redo could have played again.
     *
     * @template T
     *
     * @param string        $action  what the change was, for the label of the buttons (`ContentScreen`)
     * @param string|null   $section the id of the section it was about, when it was about one
     * @param \Closure(): T $change
     *
     * @return T what the change returned
     */
    public function record(ContentTarget $target, string $action, ?string $section, \Closure $change): mixed
    {
        $row = $target->row();
        $before = $row->draftDocument();
        $provenanceBefore = $row->draftProvenance();

        $outcome = $change();

        $row = $target->row();
        $after = $row->draftDocument();

        if ($after === $before || null === $after) {
            return $outcome;
        }

        $key = $target->key();
        $this->query($key)->filterByUndone(1)->delete();

        (new CmsContentStep())
            ->setContentKind($key->kind)
            ->setContentId($key->id)
            ->setLocale($key->locale)
            ->setAction($action)
            ->setSection(null === $section || '' === $section ? null : mb_substr($section, 0, 120))
            ->setDocumentBefore($before)
            ->setProvenanceBefore($provenanceBefore)
            ->setDocumentAfter($after)
            ->setProvenanceAfter($row->draftProvenance())
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId())
            ->save();

        $this->prune($key);

        return $outcome;
    }

    /**
     * Puts back the draft as it was before the latest step.
     *
     * @throws InvalidDocumentException when the draft before no longer passes the catalogue
     */
    public function undo(ContentTarget $target): StepOutcome
    {
        $step = $this->query($target->key())->filterByUndone(0)->orderById(Criteria::DESC)->findOne();

        if (null === $step) {
            return StepOutcome::Nothing;
        }

        if ($target->row()->draftDocument() !== $step->getDocumentAfter()) {
            return StepOutcome::DraftChanged;
        }

        // The draft as stored is what Redo compares with: the writer may
        // normalise a document kept by an older version of the module.
        $this->write($target, $step->getDocumentBefore(), $step->getProvenanceBefore(), static fn (string $stored) => $step->setDocumentBefore($stored)->setUndone(1)->save());

        return StepOutcome::Done;
    }

    /**
     * Plays again the step undone last.
     *
     * @throws InvalidDocumentException when the draft after no longer passes the catalogue
     */
    public function redo(ContentTarget $target): StepOutcome
    {
        $step = $this->query($target->key())->filterByUndone(1)->orderById(Criteria::ASC)->findOne();

        if (null === $step) {
            return StepOutcome::Nothing;
        }

        if ($target->row()->draftDocument() !== $step->getDocumentBefore()) {
            return StepOutcome::DraftChanged;
        }

        $this->write($target, $step->getDocumentAfter(), $step->getProvenanceAfter(), static fn (string $stored) => $step->setDocumentAfter($stored)->setUndone(0)->save());

        return StepOutcome::Done;
    }

    /**
     * What Undo and Redo would play now, for the buttons of the screen.
     *
     * @return array{undo: StepView|null, redo: StepView|null}
     */
    public function state(ContentKey $key): array
    {
        return [
            'undo' => $this->view($this->query($key)->filterByUndone(0)->orderById(Criteria::DESC)->findOne()),
            'redo' => $this->view($this->query($key)->filterByUndone(1)->orderById(Criteria::ASC)->findOne()),
        ];
    }

    /**
     * Writes a draft of a step back, its provenance mark with it, and marks
     * the step in the same transaction with the draft as stored.
     *
     * @param \Closure(string): mixed $mark
     */
    private function write(ContentTarget $target, ?string $document, ?string $provenance, \Closure $mark): void
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            // A content holding nothing before its first section gets an empty
            // draft back: the screen shows it as blank, like a draft emptied by hand.
            $this->writer->saveContent($target, (null === $document ? null : Document::fromJson($document)) ?? new Document([]));
            $row = $target->row();
            $row->replaceDraftProvenance($provenance);
            $mark((string) $row->draftDocument());
            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    private function view(?CmsContentStep $step): ?StepView
    {
        if (null === $step) {
            return null;
        }

        $admin = null === $step->getCreatedBy() ? null : AdminQuery::create()->findPk($step->getCreatedBy());
        $name = null === $admin ? null : trim($admin->getFirstname().' '.$admin->getLastname());

        return new StepView(
            (string) $step->getAction(),
            $step->getSection(),
            null === $admin ? null : ('' !== $name ? $name : $admin->getLogin()),
            $step->getCreatedAt(),
        );
    }

    private function prune(ContentKey $key): void
    {
        $kept = $this->query($key)->orderById(Criteria::DESC)->limit(self::KEPT)->select(['Id'])->find()->getData();

        if (\count($kept) < self::KEPT) {
            return;
        }

        $this->query($key)->filterById(min($kept), Criteria::LESS_THAN)->delete();
    }

    private function query(ContentKey $key): CmsContentStepQuery
    {
        return CmsContentStepQuery::create()
            ->filterByContentKind($key->kind)
            ->filterByContentId($key->id)
            ->filterByLocale($key->locale);
    }
}
