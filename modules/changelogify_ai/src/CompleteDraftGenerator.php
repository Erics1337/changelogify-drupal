<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify\ChangeSet\ChangeSet;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\ReleaseGeneratorInterface;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\Core\Database\Connection;

/**
 * Generates an unpublished release draft from selected change sets.
 */
final class CompleteDraftGenerator {

  /**
   * Bounded editor-facing details from the synchronous provider result.
   *
   * @var array{omitted_source_ids: string[], warnings: string[]}
   */
  private array $lastReport = ['omitted_source_ids' => [], 'warnings' => []];

  public function __construct(
    private readonly OutboundPayloadBuilder $payloadBuilder,
    private readonly AiOperationManager $operations,
    private readonly ReleaseGeneratorInterface $releaseGenerator,
    private readonly Connection $database,
  ) {}

  /**
   * Validates an AI result before creating and rewriting a selected draft.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Current, selected change sets.
   * @param \DateTimeInterface $start
   *   Preview start time.
   * @param \DateTimeInterface $end
   *   Preview end time.
   * @param array<string, string> $selection
   *   Selected change-set IDs keyed to release sections.
   * @param array<string, mixed> $options
   *   Release-title and version options.
   * @param string $profile
   *   Selected editorial profile.
   * @param bool $allowEmpty
   *   Whether an explicitly confirmed empty draft is allowed.
   * @param bool $allowEvidenceReuse
   *   Whether explicitly confirmed source reuse is allowed.
   */
  public function generate(
    array $changeSets,
    \DateTimeInterface $start,
    \DateTimeInterface $end,
    array $selection,
    array $options,
    string $profile,
    bool $allowEmpty,
    bool $allowEvidenceReuse,
  ): ChangelogifyReleaseInterface {
    $selected = array_values(array_filter(
      $changeSets,
      static fn (ChangeSet $changeSet): bool => isset($selection[$changeSet->id]),
    ));
    if ($selected === []) {
      throw new \UnexpectedValueException('Select at least one change to create a draft release.');
    }
    $payload = $this->payloadBuilder->build($selected);
    if ($payload === []) {
      throw new \UnexpectedValueException('No selected change sets are eligible for AI processing.');
    }
    $request = new SummarizationRequest(
      'complete_draft',
      $profile,
      $payload,
      PromptTemplateRegistry::VERSION,
      $this->payloadBuilder->policyVersion(),
      hash('sha256', json_encode([$start->getTimestamp(), $end->getTimestamp(), $selection, $payload], JSON_THROW_ON_ERROR)),
    );
    $result = $this->operations->execute($request, array_keys($payload));
    $this->lastReport = [
      'omitted_source_ids' => $result->omittedSourceIds,
      'warnings' => $result->warnings,
    ];
    if ($result->status !== 'completed') {
      throw new \UnexpectedValueException('The provider did not complete the draft.');
    }
    if ($result->items === []) {
      throw new \UnexpectedValueException('The provider did not return any release items.');
    }

    $transaction = $this->database->startTransaction();
    try {
      // Revalidate current source evidence immediately before persistence.
      $release = $this->releaseGenerator->generateReleaseFromSelection(
        $start,
        $end,
        $selection,
        $options,
        $allowEmpty,
        $allowEvidenceReuse,
      );
      $release->setUnpublished();
      $this->applyResult($release, $result->items, $selected);
      return $release;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Returns provider omissions and warnings from the synchronous generation.
   *
   * @return array{omitted_source_ids: string[], warnings: string[]}
   *   Bounded editor-facing report data without outbound payload contents.
   */
  public function lastReport(): array {
    return $this->lastReport;
  }

  /**
   * Queues a large draft without credentials or a mutable release payload.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Current preview change sets.
   * @param \DateTimeInterface $start
   *   Preview start time.
   * @param \DateTimeInterface $end
   *   Preview end time.
   * @param array<string, string> $selection
   *   Selected change-set IDs keyed to release sections.
   * @param array<string, mixed> $options
   *   Release-title and version options.
   * @param string $profile
   *   Selected editorial profile.
   * @param bool $allowEmpty
   *   Whether an explicitly confirmed empty draft is allowed.
   * @param bool $allowEvidenceReuse
   *   Whether explicitly confirmed source reuse is allowed.
   *
   * @return string
   *   Stable queued operation ID.
   */
  public function queue(array $changeSets, \DateTimeInterface $start, \DateTimeInterface $end, array $selection, array $options, string $profile, bool $allowEmpty, bool $allowEvidenceReuse): string {
    $selected = array_values(array_filter(
      $changeSets,
      static fn (ChangeSet $changeSet): bool => isset($selection[$changeSet->id]),
    ));
    if ($selected === []) {
      throw new \UnexpectedValueException('Select at least one change to create a draft release.');
    }
    $request = $this->request($selected, $start, $end, $selection, $profile);
    $this->operations->enqueue(
      $request,
      array_keys($request->evidence),
      NULL,
      NULL,
      'changelogify_ai_complete_draft',
      [
        'start' => $start->getTimestamp(),
        'end' => $end->getTimestamp(),
        'selection' => $selection,
        'options' => $options,
        'allow_empty' => $allowEmpty,
        'allow_evidence_reuse' => $allowEvidenceReuse,
      ],
    );
    return $request->idempotencyKey;
  }

  /**
   * Revalidates a queued operation's evidence then creates its draft.
   *
   * @param \Drupal\changelogify_ai\Summarization\SummarizationResult $result
   *   Validated queued provider result.
   * @param \DateTimeInterface $start
   *   Preview start time.
   * @param \DateTimeInterface $end
   *   Preview end time.
   * @param array<string, string> $selection
   *   Selected change-set IDs keyed to release sections.
   * @param array<string, mixed> $options
   *   Release-title and version options.
   * @param bool $allowEmpty
   *   Whether an explicitly confirmed empty draft is allowed.
   * @param bool $allowEvidenceReuse
   *   Whether explicitly confirmed source reuse is allowed.
   */
  public function finalizeQueued(SummarizationResult $result, \DateTimeInterface $start, \DateTimeInterface $end, array $selection, array $options, bool $allowEmpty, bool $allowEvidenceReuse): ChangelogifyReleaseInterface {
    if ($result->items === []) {
      throw new \UnexpectedValueException('The provider did not return any release items.');
    }
    $preview = $this->releaseGenerator->previewRange($start, $end);
    $selected = array_values(array_filter(
      $preview->changeSets,
      static fn (ChangeSet $changeSet): bool => isset($selection[$changeSet->id]),
    ));
    $transaction = $this->database->startTransaction();
    try {
      $release = $this->releaseGenerator->generateReleaseFromSelection(
        $start,
        $end,
        $selection,
        $options,
        $allowEmpty,
        $allowEvidenceReuse,
      );
      $release->setUnpublished();
      $this->applyResult($release, $result->items, $selected);
      return $release;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Builds a stable request from selected change sets.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $selected
   *   Selected change sets.
   * @param \DateTimeInterface $start
   *   Preview start time.
   * @param \DateTimeInterface $end
   *   Preview end time.
   * @param array<string, string> $selection
   *   Selected change-set IDs keyed to release sections.
   * @param string $profile
   *   Selected editorial profile.
   */
  private function request(array $selected, \DateTimeInterface $start, \DateTimeInterface $end, array $selection, string $profile): SummarizationRequest {
    $payload = $this->payloadBuilder->build($selected);
    if ($payload === []) {
      throw new \UnexpectedValueException('No selected change sets are eligible for AI processing.');
    }
    return new SummarizationRequest(
      'complete_draft',
      $profile,
      $payload,
      PromptTemplateRegistry::VERSION,
      $this->payloadBuilder->policyVersion(),
      hash('sha256', json_encode([$start->getTimestamp(), $end->getTimestamp(), $selection, $payload], JSON_THROW_ON_ERROR)),
    );
  }

  /**
   * Replaces deterministic draft text only after validation.
   *
   * @param \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release
   *   Newly created unpublished release.
   * @param \Drupal\changelogify_ai\Summarization\SummarizationItem[] $items
   *   Validated generated items.
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Selected source change sets.
   */
  private function applyResult(ChangelogifyReleaseInterface $release, array $items, array $changeSets): void {
    $bySource = [];
    foreach ($changeSets as $changeSet) {
      $bySource[$changeSet->id] = $changeSet;
    }
    $sections = array_fill_keys(['added', 'changed', 'fixed', 'removed', 'security', 'other'], []);
    $provenance = ['version' => 1, 'items' => []];
    foreach ($items as $item) {
      $eventIds = [];
      $events = [];
      $eventCount = 0;
      $evidenceStatuses = [];
      foreach ($item->sourceIds as $sourceId) {
        if (!isset($bySource[$sourceId])) {
          throw new \UnexpectedValueException('The source evidence for a generated item is no longer available.');
        }
        $source = $bySource[$sourceId];
        $eventIds = array_merge($eventIds, $source->sourceEventIds);
        $eventCount += (int) ($source->provenance['event_count'] ?? count($source->sourceEventIds));
        $evidenceStatuses[] = (string) ($source->provenance['evidence_status'] ?? 'available');
        foreach (($source->provenance['events'] ?? []) as $event) {
          if (is_array($event)) {
            $eventKey = (string) ($event['event_id'] ?? $event['event_uuid'] ?? hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)));
            $events[$eventKey] = $event;
          }
        }
      }
      $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
      if (!array_key_exists($item->section, $sections)) {
        throw new \UnexpectedValueException('The generated item uses an unsupported release section.');
      }
      $sections[$item->section][] = [
        'id' => $item->id,
        'text' => $item->text,
        'event_ids' => $eventIds,
      ];
      $provenance['items'][$item->id] = [
        'change_set_ids' => $item->sourceIds,
        'kind' => count($item->sourceIds) === 1 ? $bySource[$item->sourceIds[0]]->kind : 'ai_combined',
        'section' => $item->section,
        'event_ids' => $eventIds,
        'event_count' => $eventCount,
        'evidence_status' => count(array_unique($evidenceStatuses)) === 1 ? $evidenceStatuses[0] : 'partial',
        'events' => array_values($events),
      ];
    }
    $release->setNewRevision(TRUE);
    $release->setSections($sections);
    $release->setProvenance($provenance);
    $release->setRevisionLogMessage('AI draft generated from selected change sets.');
    $release->save();
  }

}
