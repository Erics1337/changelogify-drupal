<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\ChangeSet\ChangeSetAggregatorInterface;
use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify\EventReleaseUsage;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays safe diagnostic details for a captured event.
 */
final class EventExplorerController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $changelogifyEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ChangeSetAggregatorInterface $changeSetAggregator,
    private readonly EventReleaseUsage $releaseUsage,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get(ChangeSetAggregatorInterface::class),
      $container->get(EventReleaseUsage::class),
    );
  }

  /**
   * Displays an event and its correlated change-set membership.
   */
  public function view(ChangelogifyEventInterface $changelogify_event): array {
    $related = [$changelogify_event];
    if ($correlationId = $changelogify_event->getCorrelationId()) {
      $storage = $this->changelogifyEntityTypeManager->getStorage('changelogify_event');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('correlation_id', $correlationId)
        ->sort('timestamp', 'ASC')
        ->sort('id', 'ASC')
        ->range(0, 500)
        ->execute();
      $related = array_values($storage->loadMultiple($ids));
    }
    $changeSet = NULL;
    foreach ($this->changeSetAggregator->aggregate($related)->changeSets as $candidate) {
      if (in_array((int) $changelogify_event->id(), $candidate->sourceEventIds, TRUE)) {
        $changeSet = $candidate;
        break;
      }
    }
    $usage = $this->releaseUsage->getUsage()[(int) $changelogify_event->id()] ?? [];
    $entityDescriptor = trim(
      ($changelogify_event->getRelatedEntityTypeId() ?? '') . ':'
      . ($changelogify_event->getRelatedEntityId() ?? ''),
      ':',
    );
    $rows = [
      [$this->t('Time'), $this->dateFormatter->format($changelogify_event->getTimestamp(), 'long')],
      [$this->t('Source'), $changelogify_event->getSource()],
      [$this->t('Event type'), $changelogify_event->getEventType()],
      [$this->t('Message'), $changelogify_event->getMessage()],
      [$this->t('Actor user ID'), (string) ($changelogify_event->get('user_id')->target_id ?? '-')],
      [$this->t('Entity'), $entityDescriptor ?: '-'],
      [$this->t('Bundle'), $changelogify_event->getRelatedBundle() ?? '-'],
      [$this->t('Section'), $changelogify_event->getSectionHint() ?? '-'],
      [$this->t('Correlation ID'), $changelogify_event->getCorrelationId() ?? '-'],
      [$this->t('Change set'), $changeSet?->id ?? '-'],
      [$this->t('Release use'), $usage === [] ? $this->t('Not included') : implode(', ', $usage)],
    ];
    return [
      'summary' => [
        '#type' => 'table',
        '#rows' => $rows,
        '#header' => [$this->t('Property'), $this->t('Value')],
      ],
      'metadata_title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Normalized metadata'),
      ],
      'metadata' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#plain_text' => json_encode(
          $this->redact($changelogify_event->getMetadata()),
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ),
      ],
      'related_title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Correlated events'),
      ],
      'related' => [
        '#theme' => 'item_list',
        '#items' => array_map(
          static fn (ChangelogifyEventInterface $event): string => sprintf(
            '#%d — %s',
            $event->id(),
            $event->getMessage(),
          ),
          $related,
        ),
        '#empty' => $this->t('No correlated events.'),
      ],
    ];
  }

  /**
   * Redacts credential-like metadata keys defensively at render time.
   */
  private function redact(array $metadata): array {
    foreach ($metadata as $key => &$value) {
      if (preg_match('/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key)/i', (string) $key)) {
        $value = '[redacted]';
      }
      elseif (is_array($value)) {
        $value = $this->redact($value);
      }
    }
    unset($value);
    return $metadata;
  }

}
