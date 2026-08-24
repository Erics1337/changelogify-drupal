<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\changelogify_ai\AiOperationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms cancellation of work that has not committed a release.
 */
final class CancelOperationForm extends ConfirmFormBase {

  public function __construct(protected AiOperationManager $operations) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(AiOperationManager::class));
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
    return $this->t('Cancel this queued AI operation?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('changelogify_ai.operation_history');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Cancellation prevents queued work from starting. It cannot undo a completed release change.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $operation_id = ''): array {
    $operation = $this->operations->get($operation_id);
    if (!is_array($operation) || ($operation['status'] ?? NULL) !== 'queued') {
      throw new NotFoundHttpException();
    }
    $form_state->set('operation_id', $operation_id);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->operations->cancel((string) $form_state->get('operation_id'));
      $this->messenger()->addStatus($this->t('The queued AI operation was cancelled.'));
    }
    catch (\UnexpectedValueException) {
      $this->messenger()->addWarning($this->t('The operation already started and cannot be cancelled.'));
    }
    $form_state->setRedirect('changelogify_ai.operation_history');
  }

}
