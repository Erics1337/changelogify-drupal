<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;

/**
 * Creates and accepts evidence-backed, editor-controlled item suggestions.
 */
final class ReleaseSuggestionManager {

  public function __construct(
    private readonly AiOperationManager $operations,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
  ) {}

  /**
   * Determines whether the trusted item can request a suggestion.
   */
  public function canSuggest(ChangelogifyReleaseInterface $release, string $itemId): bool {
    if ($release->getEditorialState() === 'archived') {
      return FALSE;
    }
    [, $item] = $this->findItem($release, $itemId);
    $config = $this->configFactory->get('changelogify_ai.settings');
    $hasEvidence = ($item['event_ids'] ?? []) !== [];
    $provenance = $release->getProvenance()['items'][$itemId] ?? NULL;
    $evidenceEligible = $hasEvidence && (!is_array($provenance)
      || in_array($provenance['evidence_status'] ?? '', ['available', 'partial'], TRUE));
    $allowManual = (bool) $config->get('policy.allow_manual_humanization');
    return (bool) $config->get('consent_external_processing')
      && $this->operations->isAvailable()
      && ($evidenceEligible || (!$hasEvidence && $allowManual));
  }

  /**
   * Requests an unpersisted rewrite for one evidence-backed release item.
   */
  public function suggest(ChangelogifyReleaseInterface $release, string $itemId, string $profile, int $attempt = 0, string $instructions = ''): SummarizationResult {
    [$section, $item] = $this->findItem($release, $itemId);
    if (!$this->canSuggest($release, $itemId)) {
      throw new \UnexpectedValueException('AI drafting is unavailable or this item is not eligible for humanization.');
    }
    $request = new SummarizationRequest(
      'humanize_item',
      $profile,
      [
        $itemId => [
          'id' => $itemId,
          'section' => $section,
          'summary' => $item['text'],
        ],
      ],
      PromptTemplateRegistry::VERSION,
      '1',
      hash('sha256', implode(':', [
        $release->uuid(),
        $release->getRevisionId(),
        $itemId,
        $item['text'],
        $profile,
        $this->boundedInstructions($instructions),
        (string) max(0, $attempt),
      ])),
      $this->boundedInstructions($instructions),
    );
    return $this->operations->execute($request, [$itemId], (int) $release->id(), (int) $release->getRevisionId());
  }

  /**
   * Requests a staged rewrite for trusted current items in one release.
   *
   * @param \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release
   *   Trusted current release revision.
   * @param string[] $itemIds
   *   Trusted item IDs selected by the server-rendered editor.
   * @param string $profile
   *   Built-in editorial profile.
   * @param string $instructions
   *   Temporary instructions for this attempt.
   * @param int $attempt
   *   Explicit regeneration attempt number.
   */
  public function suggestRelease(ChangelogifyReleaseInterface $release, array $itemIds, string $profile, string $instructions = '', int $attempt = 0): SummarizationResult {
    if ($release->getEditorialState() === 'archived') {
      throw new \UnexpectedValueException('Archived releases must be restored to draft before AI rewriting.');
    }
    $requested = array_fill_keys(array_values(array_unique($itemIds)), TRUE);
    $evidence = [];
    foreach ($release->getSections() as $section => $items) {
      foreach ($items as $item) {
        $itemId = (string) ($item['id'] ?? '');
        if (!isset($requested[$itemId]) || !$this->canSuggest($release, $itemId)) {
          continue;
        }
        $evidence[$itemId] = [
          'id' => $itemId,
          'section' => $section,
          'summary' => (string) $item['text'],
        ];
      }
    }
    if ($evidence === [] || array_diff_key($requested, $evidence) !== []) {
      throw new \UnexpectedValueException('One or more selected release items are stale or ineligible.');
    }
    $boundedInstructions = $this->boundedInstructions($instructions);
    $request = new SummarizationRequest(
      'humanize_release',
      $profile,
      $evidence,
      PromptTemplateRegistry::VERSION,
      '1',
      hash('sha256', implode(':', [
        $release->uuid(),
        $release->getRevisionId(),
        implode(',', array_keys($evidence)),
        hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
        $profile,
        $boundedInstructions,
        (string) max(0, $attempt),
      ])),
      $boundedInstructions,
    );
    return $this->operations->execute(
      $request,
      array_keys($evidence),
      (int) $release->id(),
      (int) $release->getRevisionId(),
    );
  }

  /**
   * Accepts a reviewed subset from a release-wide operation in one revision.
   *
   * @param \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release
   *   Trusted current release revision.
   * @param array<string, string> $suggestedText
   *   Trusted generated text keyed by stable item ID.
   * @param string[] $acceptedItemIds
   *   Item IDs explicitly accepted by the editor.
   * @param string $operationId
   *   Server-held operation identifier being reviewed.
   *
   * @return bool
   *   TRUE when a published release was staged as a non-public revision.
   */
  public function acceptRelease(ChangelogifyReleaseInterface $release, array $suggestedText, array $acceptedItemIds, string $operationId): bool {
    $this->operations->assertCanRecordDisposition($operationId);
    $accepted = array_fill_keys(array_values(array_unique($acceptedItemIds)), TRUE);
    if ($accepted === [] || array_diff_key($accepted, $suggestedText) !== []) {
      throw new \UnexpectedValueException('Select at least one current suggestion to accept.');
    }
    $sections = $release->getSections();
    $found = [];
    foreach ($sections as &$items) {
      foreach ($items as &$item) {
        $itemId = (string) ($item['id'] ?? '');
        if (isset($accepted[$itemId])) {
          $item['text'] = $suggestedText[$itemId];
          $found[$itemId] = TRUE;
        }
      }
      unset($item);
    }
    unset($items);
    if (array_diff_key($accepted, $found) !== []) {
      throw new \UnexpectedValueException('The release changed while suggestions were being reviewed.');
    }
    return $this->saveAcceptedRevision($release, $sections, $operationId, count($accepted));
  }

  /**
   * Accepts one previously reviewed suggestion and creates a new revision.
   */
  public function accept(ChangelogifyReleaseInterface $release, string $itemId, string $text, string $operationId): bool {
    $this->operations->assertCanRecordDisposition($operationId);
    [$foundSection] = $this->findItem($release, $itemId);
    $sections = $release->getSections();
    foreach ($sections[$foundSection] as &$item) {
      if ($item['id'] === $itemId) {
        $item['text'] = $text;
        break;
      }
    }
    unset($item);
    return $this->saveAcceptedRevision($release, $sections, $operationId, 1);
  }

  /**
   * Records rejection of an unpersisted suggestion.
   */
  public function reject(string $operationId): void {
    $this->operations->recordDisposition($operationId, 'rejected');
  }

  /**
   * Saves accepted text transactionally, staging published edits privately.
   */
  private function saveAcceptedRevision(ChangelogifyReleaseInterface $release, array $sections, string $operationId, int $itemCount): bool {
    $stagePublished = $release->getEditorialState() === 'published';
    $transaction = $this->database->startTransaction();
    try {
      $release->setNewRevision(TRUE);
      if ($stagePublished) {
        $release->isDefaultRevision(FALSE);
        $release->setPublished(FALSE);
        $release->setEditorialState('draft');
      }
      $release->setRevisionUserId((int) $this->currentUser->id());
      $release->setRevisionCreationTime($this->time->getRequestTime());
      $release->setRevisionLogMessage(sprintf(
        'Accepted AI suggestions for %d release item(s)%s.',
        $itemCount,
        $stagePublished ? ' in a non-public review revision' : '',
      ));
      $release->setSections($sections);
      $release->save();
      $this->operations->recordDisposition($operationId, 'accepted', (int) $release->getRevisionId());
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    return $stagePublished;
  }

  /**
   * Sanitizes and bounds instructions that must never become configuration.
   */
  private function boundedInstructions(string $instructions): string {
    $instructions = strip_tags($instructions);
    $instructions = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $instructions) ?? '';
    return mb_substr(trim($instructions), 0, 1000);
  }

  /**
   * Locates an item without relying on client-submitted text or sections.
   *
   * @return array{string, array<string, mixed>}
   *   The trusted section and stored item.
   */
  private function findItem(ChangelogifyReleaseInterface $release, string $itemId): array {
    foreach ($release->getSections() as $section => $items) {
      foreach ($items as $item) {
        if (($item['id'] ?? '') === $itemId) {
          return [$section, $item];
        }
      }
    }
    throw new \UnexpectedValueException('The requested release item no longer exists.');
  }

}
