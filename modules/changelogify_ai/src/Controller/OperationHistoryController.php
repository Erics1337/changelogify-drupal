<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\changelogify_ai\AiOperationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays privileged, privacy-bounded AI operation history.
 */
final class OperationHistoryController extends ControllerBase {

  public function __construct(protected AiOperationManager $operations, protected DateFormatterInterface $dateFormatter) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AiOperationManager::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders non-secret operation correlations and provider diagnostics.
   */
  public function view(): array {
    $rows = [];
    foreach ($this->operations->all() as $operation) {
      $rows[] = [
        'id' => substr((string) $operation['id'], 0, 16),
        'status' => $operation['status'] ?? 'unknown',
        'type' => $operation['type'] ?? 'unknown',
        'release' => $operation['release_id'] ?? '-',
        'revision' => $operation['accepted_revision_id'] ?? $operation['revision_id'] ?? '-',
        'provider' => $operation['provider_id'] ?? 'unavailable',
        'model' => $operation['model_id'] ?? 'unavailable',
        'usage' => ($operation['input_tokens'] ?? 'unavailable') . ' / ' . ($operation['output_tokens'] ?? 'unavailable'),
        'created' => isset($operation['created']) ? $this->dateFormatter->format((int) $operation['created'], 'short') : '-',
        'failure' => $operation['error_code'] ?? '-',
        'actions' => ($operation['status'] ?? NULL) === 'queued'
          ? Link::fromTextAndUrl($this->t('Cancel'), Url::fromRoute('changelogify_ai.cancel_operation', ['operation_id' => $operation['id']]))
          : '',
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Operation'), $this->t('Status'), $this->t('Type'), $this->t('Release'), $this->t('Revision'),
        $this->t('Provider'), $this->t('Model'), $this->t('Input / output tokens'), $this->t('Created'), $this->t('Failure category'), $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No retained AI operations are available.'),
      '#cache' => ['max-age' => 0],
    ];
  }

}
