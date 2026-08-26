<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\changelogify_ai\SynthesisDraftFinalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executes credential-free references to durable synthesis batches.
 *
 * @QueueWorker(
 *   id = "changelogify_ai_synthesis",
 *   title = @Translation("Changelogify AI hierarchical synthesis"),
 *   cron = {"time" = 30}
 * )
 */
final class SynthesisJobWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected SynthesisJobManager $jobs, protected SynthesisDraftFinalizer $finalizer) {
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
      $container->get(SynthesisJobManager::class),
      $container->get(SynthesisDraftFinalizer::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)
      || !is_string($data['job_id'] ?? NULL)
      || !is_string($data['batch_id'] ?? NULL)
      || count($data) !== 2) {
      throw new \UnexpectedValueException('Invalid Changelogify synthesis queue reference.');
    }
    $this->jobs->process($data['job_id'], $data['batch_id']);
    $this->finalizer->finalizeIfReady($data['job_id']);
  }

}
