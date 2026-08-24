<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\changelogify_ai\AiOperationManager;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executes bounded asynchronous complete-draft requests.
 *
 * @QueueWorker(
 *   id = "changelogify_ai_draft",
 *   title = @Translation("Changelogify AI draft"),
 *   cron = {"time" = 30}
 * )
 */
final class AiDraftWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected AiOperationManager $operations, protected QueueFactory $queueFactory) {
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
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)
      || !($data['request'] ?? NULL) instanceof SummarizationRequest
      || !is_array($data['source_ids'] ?? NULL)) {
      throw new \UnexpectedValueException('Invalid Changelogify AI queue payload.');
    }
    $operation = $this->operations->get($data['request']->idempotencyKey);
    if (($operation['status'] ?? NULL) === 'cancelled') {
      return;
    }
    try {
      $this->operations->execute($data['request'], $data['source_ids'], $data['release_id'] ?? NULL, $data['revision_id'] ?? NULL);
    }
    catch (TransientSummarizationException $exception) {
      $attempt = ((int) ($data['attempt'] ?? 0)) + 1;
      if ($attempt >= 3) {
        throw $exception;
      }
      $data['attempt'] = $attempt;
      $this->queueFactory->get('changelogify_ai_draft')->createItem($data);
    }
  }

}
