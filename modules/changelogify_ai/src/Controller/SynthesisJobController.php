<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\changelogify_ai\SynthesisOperationAccess;
use Drupal\changelogify_ai\SynthesisStatusBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays and reports one owner-aware synthesis job.
 */
final class SynthesisJobController extends ControllerBase {

  public function __construct(private readonly SynthesisJobManager $jobs, private readonly SynthesisStatusBuilder $statusBuilder, private readonly SynthesisOperationAccess $access, private readonly AccountProxyInterface $account) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SynthesisJobManager::class),
      $container->get(SynthesisStatusBuilder::class),
      $container->get(SynthesisOperationAccess::class),
      $container->get('current_user'),
    );
  }

  /**
   * Displays a synthesis job for its creator or a privileged viewer.
   */
  public function view(string $job_id): array {
    $job = $this->job($job_id);
    $canCancel = $this->access->cancel($this->account, $job_id)->isAllowed();
    $canConfigureProcessing = $this->account->hasPermission('administer changelogify ai');
    return [
      '#theme' => 'changelogify_ai_job',
      '#job' => $this->statusBuilder->build($job, $canCancel, $canConfigureProcessing),
      '#attached' => [
        'library' => ['changelogify_ai/progress'],
        'drupalSettings' => [
          'changelogifyAiJob' => [
            'statusUrl' => Url::fromRoute('changelogify_ai.synthesis_job_status', [
              'job_id' => $job_id,
            ])->toString(),
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Returns a no-store, privacy-bounded status response.
   */
  public function status(string $job_id): JsonResponse {
    $job = $this->job($job_id);
    $canCancel = $this->access->cancel($this->account, $job_id)->isAllowed();
    $canConfigureProcessing = $this->account->hasPermission('administer changelogify ai');
    $response = new JsonResponse($this->statusBuilder->build($job, $canCancel, $canConfigureProcessing));
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Loads a trusted job or raises a not-found response.
   */
  private function job(string $jobId): array {
    $job = $this->jobs->get($jobId);
    if (!is_array($job)) {
      throw new NotFoundHttpException();
    }
    return $job;
  }

}
