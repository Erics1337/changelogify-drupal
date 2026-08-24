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
    [, $item] = $this->findItem($release, $itemId);
    $hasEvidence = ($item['event_ids'] ?? []) !== [];
    $allowManual = (bool) $this->configFactory->get('changelogify_ai.settings')->get('policy.allow_manual_humanization');
    return $this->operations->isAvailable() && ($hasEvidence || $allowManual);
  }

  /**
   * Requests an unpersisted rewrite for one evidence-backed release item.
   */
  public function suggest(ChangelogifyReleaseInterface $release, string $itemId, string $profile, int $attempt = 0): SummarizationResult {
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
        (string) max(0, $attempt),
      ])),
    );
    return $this->operations->execute($request, [$itemId], (int) $release->id(), (int) $release->getRevisionId());
  }

  /**
   * Accepts one previously reviewed suggestion and creates a new revision.
   */
  public function accept(ChangelogifyReleaseInterface $release, string $itemId, string $text, string $operationId): void {
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
    $transaction = $this->database->startTransaction();
    try {
      $release->setNewRevision(TRUE);
      $release->setRevisionUserId((int) $this->currentUser->id());
      $release->setRevisionCreationTime($this->time->getRequestTime());
      $release->setRevisionLogMessage('Accepted AI humanize suggestion for release item ' . $itemId . '.');
      $release->setSections($sections);
      $release->save();
      $this->operations->recordDisposition($operationId, 'accepted', (int) $release->getRevisionId());
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Records rejection of an unpersisted suggestion.
   */
  public function reject(string $operationId): void {
    $this->operations->recordDisposition($operationId, 'rejected');
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
