<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\changelogify_ai\AiOperationManager;
use Drupal\changelogify_ai\CompleteDraftGenerator;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finalizes queued complete drafts only after current-evidence revalidation.
 *
 * @QueueWorker(
 *   id = "changelogify_ai_complete_draft",
 *   title = @Translation("Changelogify AI complete draft"),
 *   cron = {"time" = 30}
 * )
 */
final class CompleteDraftWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected AiOperationManager $operations, protected CompleteDraftGenerator $drafts, protected QueueFactory $queueFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(AiOperationManager::class),
      $container->get(CompleteDraftGenerator::class),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)
      || !($data['request'] ?? NULL) instanceof SummarizationRequest
      || !is_array($data['source_ids'] ?? NULL)
      || !is_numeric($data['start'] ?? NULL)
      || !is_numeric($data['end'] ?? NULL)
      || !is_array($data['selection'] ?? NULL)) {
      throw new \UnexpectedValueException('Invalid queued complete-draft payload.');
    }
    if (($this->operations->get($data['request']->idempotencyKey)['status'] ?? NULL) === 'cancelled') {
      return;
    }
    try {
      $result = $this->operations->execute($data['request'], $data['source_ids']);
      if ($result->status !== 'completed') {
        return;
      }
      $this->drafts->finalizeQueued(
        $result,
        new \DateTimeImmutable('@' . $data['start']),
        new \DateTimeImmutable('@' . $data['end']),
        $data['selection'],
        $data['options'] ?? [],
        !empty($data['allow_empty']),
        !empty($data['allow_evidence_reuse']),
      );
    }
    catch (TransientSummarizationException $exception) {
      $attempt = ((int) ($data['attempt'] ?? 0)) + 1;
      if ($attempt >= 3) {
        throw $exception;
      }
      $data['attempt'] = $attempt;
      $this->queueFactory->get('changelogify_ai_complete_draft')->createItem($data);
    }
  }

}
