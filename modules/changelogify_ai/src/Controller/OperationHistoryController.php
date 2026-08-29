<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify_ai\AiOperationHistoryRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Displays indexed, privacy-bounded AI operation history.
 */
final class OperationHistoryController extends ControllerBase {

  public function __construct(
    private readonly AiOperationHistoryRepository $history,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $releaseEntityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AiOperationHistoryRepository::class),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Renders the filtered operation history.
   */
  public function view(): array {
    $query = $this->requestStack->getCurrentRequest()?->query;
    $status = is_string($query?->get('status')) ? (string) $query->get('status') : '';
    $type = is_string($query?->get('type')) ? (string) $query->get('type') : '';
    $date = is_string($query?->get('date')) ? (string) $query->get('date') : '';
    $since = $date !== '' ? strtotime($date . ' 00:00:00') : FALSE;
    $operations = $this->history->page([
      'status' => $status,
      'type' => $type,
      'since' => is_int($since) ? $since : 0,
    ]);
    $releaseIds = array_values(array_unique(array_filter(array_map(
      static fn (array $operation): int => max(0, (int) ($operation['release_id'] ?? 0)),
      $operations,
    ))));
    $releases = $releaseIds === []
      ? []
      : $this->releaseEntityTypeManager->getStorage('changelogify_release')->loadMultiple($releaseIds);
    $rows = [];
    foreach ($operations as $operation) {
      $releaseId = (int) ($operation['release_id'] ?? 0);
      $release = $releases[$releaseId] ?? NULL;
      $rows[] = $this->row(
        $operation,
        $release instanceof ChangelogifyReleaseInterface ? $release : NULL,
      );
    }

    return [
      'filters' => [
        '#type' => 'inline_template',
        '#template' => '<form method="get" class="changelogify-ai-history-filter">'
        . '<label>{{ "Status"|t }}<select name="status"><option value="">{{ "All statuses"|t }}</option>'
        . '{% for value, label in statuses %}<option value="{{ value }}"{{ value == selected_status ? " selected" : "" }}>{{ label }}</option>{% endfor %}'
        . '</select></label><label>{{ "Type"|t }}<select name="type"><option value="">{{ "All types"|t }}</option>'
        . '{% for value, label in types %}<option value="{{ value }}"{{ value == selected_type ? " selected" : "" }}>{{ label }}</option>{% endfor %}'
        . '</select></label><label>{{ "Created on or after"|t }}<input type="date" name="date" value="{{ date }}"></label>'
        . '<button class="button" type="submit">{{ "Filter"|t }}</button><a class="button" href="{{ reset_url }}">{{ "Reset"|t }}</a></form>',
        '#context' => [
          'statuses' => $this->statusOptions(),
          'types' => $this->typeOptions(),
          'selected_status' => $status,
          'selected_type' => $type,
          'date' => $date,
          'reset_url' => Url::fromRoute('changelogify_ai.operation_history')->toString(),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['changelogify-ai-history']],
        '#header' => [
          $this->t('Created'), $this->t('Operation'), $this->t('Status'),
          $this->t('Progress'), $this->t('Result'), $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No retained AI operations match these filters.'),
      ],
      'ordering_help' => [
        '#type' => 'item',
        '#markup' => $this->t('Operations still processing or awaiting editorial review are shown first; terminal operations follow in newest-first order.'),
        '#weight' => -1,
      ],
      'pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['changelogify_ai/history']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds one responsive table row.
   */
  private function row(array $operation, ?ChangelogifyReleaseInterface $release): array {
    $id = (string) $operation['operation_id'];
    $operationType = (string) ($operation['operation_type'] ?? '');
    $isSynthesis = $operationType === 'synthesize_release';
    $status = (string) ($operation['status'] ?? 'unknown');
    $progress = $isSynthesis ? match ($status) {
      'prepared' => $this->t('Request prepared'),
      'running', 'completed', 'finalized' => $this->t('One request sent'),
      default => (int) ($operation['attempt_count'] ?? 0) > 0
        ? $this->t('One request sent')
        : $this->t('Request not sent'),
    } : $this->t('Not applicable');
    $result = match (TRUE) {
      in_array($status, ['prepared', 'running'], TRUE) => $this->t('In progress'),
      $status === 'completed' && !$isSynthesis => $this->t('Suggestion generated'),
      default => $this->t('No draft'),
    };
    if ($release !== NULL && $release->access('update')) {
      $result = Link::fromTextAndUrl(
        $release->isPublished() ? $this->t('Open release') : $this->t('Review draft'),
        Url::fromRoute('entity.changelogify_release.edit_form', [
          'changelogify_release' => (int) $release->id(),
        ]),
      );
    }
    elseif ((int) ($operation['release_id'] ?? 0) > 0) {
      $result = $release === NULL
        ? $this->t('Release no longer available')
        : $this->t('Release access unavailable');
    }
    $actions = [];
    if ($isSynthesis) {
      $actions[] = Link::fromTextAndUrl($this->t('View details'), Url::fromRoute('changelogify_ai.synthesis_job', ['job_id' => $id]));
    }
    if (in_array($status, ['prepared', 'running'], TRUE)) {
      $actions[] = Link::fromTextAndUrl($this->t('Cancel'), Url::fromRoute('changelogify_ai.cancel_operation', ['operation_id' => $id]));
    }
    $label = [
      'label' => ['#plain_text' => $this->typeLabel((string) $operation['operation_type'])],
      'reference' => ['#markup' => '<br><small>' . substr($id, 0, 16) . '</small>'],
    ];
    return [
      [
        'data' => $this->dateFormatter->format((int) $operation['created'], 'short'),
        'data-label' => $this->t('Created'),
      ],
      ['data' => $label, 'data-label' => $this->t('Operation')],
      [
        'data' => $this->statusLabel($status, $operationType, (string) ($operation['disposition'] ?? '')),
        'data-label' => $this->t('Status'),
      ],
      ['data' => $progress, 'data-label' => $this->t('Progress')],
      ['data' => $result, 'data-label' => $this->t('Result')],
      [
        'data' => [
          '#theme' => 'item_list',
          '#items' => $actions,
          '#attributes' => ['class' => ['links', 'inline']],
        ],
        'data-label' => $this->t('Actions'),
      ],
    ];
  }

  /**
   * Returns translated status filter options.
   */
  private function statusOptions(): array {
    return [
      'prepared' => $this->t('Prepared'),
      'running' => $this->t('Processing'),
      'completed' => $this->t('Completed'),
      'finalized' => $this->t('Draft ready'),
      'failed' => $this->t('Failed'),
      'cancelled' => $this->t('Cancelled'),
    ];
  }

  /**
   * Returns translated operation type filter options.
   */
  private function typeOptions(): array {
    return [
      'synthesize_release' => $this->t('Release synthesis'),
      'humanize_item' => $this->t('Release-note rewrite'),
      'humanize_release' => $this->t('Whole-release rewrite'),
      'complete_draft' => $this->t('Draft completion'),
    ];
  }

  /**
   * Converts an internal status to an editorial label.
   */
  private function statusLabel(string $status, string $operationType, string $disposition): string {
    return (string) match ($status) {
      'prepared' => $this->t('Prepared'), 'running' => $this->t('Processing'),
      'completed' => match (TRUE) {
        $operationType === 'synthesize_release' => $this->t('Creating draft'),
        $disposition === 'accepted' => $this->t('Applied'),
        $disposition === 'rejected' => $this->t('Dismissed'),
        default => $this->t('Suggestion ready'),
      },
      'finalized' => $this->t('Draft ready'),
      'failed' => $this->t('Failed'), 'cancelled' => $this->t('Cancelled'),
      default => $this->t('Unavailable'),
    };
  }

  /**
   * Converts an internal type to an editorial label.
   */
  private function typeLabel(string $type): string {
    return (string) ($this->typeOptions()[$type] ?? $this->t('AI operation'));
  }

}
