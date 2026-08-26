<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\changelogify_ai\SynthesisJobManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms cancellation of work that has not committed a release.
 */
final class CancelOperationForm extends ConfirmFormBase {

  /**
   * Current synthesis job ID when the confirmation targets synthesis.
   */
  protected ?string $synthesisJobId = NULL;

  public function __construct(protected SynthesisJobManager $synthesisJobs) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SynthesisJobManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'changelogify_ai_cancel_operation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Cancel this AI operation?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('changelogify_ai.synthesis_job', ['job_id' => $this->synthesisJobId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Cancellation prevents a prepared request from starting or discards an in-flight result. It cannot undo a completed release change.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $operation_id = ''): array {
    $synthesisJob = $this->synthesisJobs->get($operation_id);
    $synthesisCanCancel = is_array($synthesisJob)
      && in_array($synthesisJob['status'] ?? NULL, ['prepared', 'running'], TRUE);
    if (!$synthesisCanCancel) {
      throw new NotFoundHttpException();
    }
    $form_state->set('operation_id', $operation_id);
    $this->synthesisJobId = $operation_id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->synthesisJobs->cancel((string) $form_state->get('operation_id'));
      $this->messenger()->addStatus($this->t('The AI synthesis was cancelled.'));
    }
    catch (\UnexpectedValueException) {
      $this->messenger()->addWarning($this->t('The operation already started and cannot be cancelled.'));
    }
    $form_state->setRedirect('changelogify_ai.synthesis_job', [
      'job_id' => (string) $form_state->get('operation_id'),
    ]);
  }

}
