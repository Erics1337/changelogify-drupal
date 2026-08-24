<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify_ai\ReleaseSuggestionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets an editor explicitly review and accept one AI item suggestion.
 */
final class HumanizeItemForm extends FormBase {

  public function __construct(
    protected ReleaseSuggestionManager $suggestions,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ReleaseSuggestionManager::class),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'changelogify_ai_humanize_item_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Every actionable button supplies its own submit handler.
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ChangelogifyReleaseInterface $changelogify_release = NULL, string $item_id = ''): array {
    if ($changelogify_release === NULL) {
      throw new \InvalidArgumentException('A release is required.');
    }
    if (!$this->suggestions->canSuggest($changelogify_release, $item_id)) {
      throw new AccessDeniedHttpException();
    }
    $form_state->set('release_id', (int) $changelogify_release->id());
    $form_state->set('release_revision_id', (int) $changelogify_release->getRevisionId());
    $form_state->set('item_id', $item_id);
    $form['profile'] = [
      '#type' => 'select',
      '#title' => $this->t('Editorial profile'),
      '#options' => [
        'public_product' => $this->t('Public product'),
        'client_report' => $this->t('Client report'),
        'internal_technical' => $this->t('Internal technical'),
        'concise' => $this->t('Concise'),
      ],
      '#default_value' => $form_state->getValue('profile', 'public_product'),
    ];
    $suggestion = $form_state->get('suggestion');
    if (is_array($suggestion)) {
      $form['original'] = [
        '#type' => 'item',
        '#title' => $this->t('Original'),
        '#plain_text' => $suggestion['original'],
      ];
      $form['suggested'] = [
        '#type' => 'item',
        '#title' => $this->t('Suggestion'),
        '#plain_text' => $suggestion['text'],
      ];
      $form['provider_details'] = [
        '#type' => 'item',
        '#title' => $this->t('Generation details'),
        '#plain_text' => $this->t('Provider: @provider; model: @model.', [
          '@provider' => $suggestion['provider'] ?? $this->t('Unavailable'),
          '@model' => $suggestion['model'] ?? $this->t('Unavailable'),
        ]),
      ];
      $form['supporting_evidence'] = [
        '#type' => 'item',
        '#title' => $this->t('Supporting evidence'),
        '#plain_text' => $this->evidenceSummary($changelogify_release, (string) $form_state->get('item_id')),
      ];
      if (($suggestion['warnings'] ?? []) !== []) {
        $form['warnings'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('Provider warnings'),
          '#items' => $suggestion['warnings'],
        ];
      }
      $form['actions']['accept'] = [
        '#type' => 'submit',
        '#value' => $this->t('Accept suggestion'),
        '#submit' => ['::acceptSubmit'],
      ];
      $form['actions']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Regenerate'),
        '#submit' => ['::generateSubmit'],
      ];
      $form['actions']['reject'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reject suggestion'),
        '#submit' => ['::rejectSubmit'],
      ];
      $form['actions']['restore_original'] = [
        '#type' => 'link',
        '#title' => $this->t('Restore original from revision history'),
        '#url' => Url::fromRoute('entity.changelogify_release.version_history', [
          'changelogify_release' => $changelogify_release->id(),
        ]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    else {
      $form['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate suggestion'),
        '#button_type' => 'primary',
        '#submit' => ['::generateSubmit'],
      ];
    }
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.changelogify_release.edit_form', [
        'changelogify_release' => $changelogify_release->id(),
      ]),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * Requests a proposal without changing the release.
   */
  public function generateSubmit(array &$form, FormStateInterface $form_state): void {
    $release = $this->loadUnchangedRelease($form_state);
    try {
      $previous = $form_state->get('suggestion');
      $result = $this->suggestions->suggest($release, (string) $form_state->get('item_id'), (string) $form_state->getValue('profile'));
      if ($result->status !== 'completed' || count($result->items) !== 1) {
        $this->messenger()->addError($this->t('The provider did not return one usable suggestion.'));
        return;
      }
      if (is_array($previous) && !empty($previous['operation_id'])) {
        $this->suggestions->reject((string) $previous['operation_id']);
      }
      $form_state->set('suggestion', [
        'original' => $this->currentText($release, (string) $form_state->get('item_id')),
        'text' => $result->items[0]->text,
        'provider' => $result->providerId,
        'model' => $result->modelId,
        'warnings' => $result->warnings,
        'operation_id' => $result->operationId,
      ])->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('No suggestion was created. The release was not changed.'));
    }
  }

  /**
   * Persists a reviewed suggestion in a normal entity revision.
   */
  public function acceptSubmit(array &$form, FormStateInterface $form_state): void {
    $suggestion = $form_state->get('suggestion');
    if (!is_array($suggestion)) {
      $form_state->setErrorByName('suggestion', $this->t('Generate a suggestion before accepting it.'));
      return;
    }
    try {
      $release = $this->loadUnchangedRelease($form_state);
    }
    catch (\UnexpectedValueException) {
      $form_state->setErrorByName('suggestion', $this->t('The release changed while this suggestion was being reviewed. Generate a new suggestion before accepting it.'));
      return;
    }
    $this->suggestions->accept($release, (string) $form_state->get('item_id'), (string) $suggestion['text'], (string) $suggestion['operation_id']);
    $this->messenger()->addStatus($this->t('The suggestion was accepted in a new release revision.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.changelogify_release.edit_form', ['changelogify_release' => $release->id()]));
  }

  /**
   * Discards an unpersisted suggestion without changing the release.
   */
  public function rejectSubmit(array &$form, FormStateInterface $form_state): void {
    $suggestion = $form_state->get('suggestion');
    if (is_array($suggestion) && !empty($suggestion['operation_id'])) {
      $this->suggestions->reject((string) $suggestion['operation_id']);
    }
    $form_state->set('suggestion', NULL)->setRebuild();
    $this->messenger()->addStatus($this->t('The suggestion was rejected. The release was not changed.'));
  }

  /**
   * Returns the original text from the trusted release entity.
   */
  private function currentText(ChangelogifyReleaseInterface $release, string $itemId): string {
    foreach ($release->getSections() as $items) {
      foreach ($items as $item) {
        if (($item['id'] ?? '') === $itemId) {
          return (string) $item['text'];
        }
      }
    }
    throw new \UnexpectedValueException('The requested release item no longer exists.');
  }

  /**
   * Summarizes trusted provenance without exposing an outbound payload.
   */
  private function evidenceSummary(ChangelogifyReleaseInterface $release, string $itemId): string {
    foreach ($release->getSections() as $items) {
      foreach ($items as $item) {
        if (($item['id'] ?? '') === $itemId) {
          $eventIds = $item['event_ids'] ?? [];
          return $eventIds === [] ? (string) $this->t('Manual item: no automatic evidence is attached.') : (string) $this->t('Source event IDs: @ids', ['@ids' => implode(', ', $eventIds)]);
        }
      }
    }
    throw new \UnexpectedValueException('The requested release item no longer exists.');
  }

  /**
   * Reloads the release and rejects suggestions based on a stale revision.
   */
  private function loadUnchangedRelease(FormStateInterface $formState): ChangelogifyReleaseInterface {
    $release = $this->entityTypeManager
      ->getStorage('changelogify_release')
      ->load((int) $formState->get('release_id'));
    if (!$release instanceof ChangelogifyReleaseInterface
      || (int) $release->getRevisionId() !== (int) $formState->get('release_revision_id')) {
      throw new \UnexpectedValueException('The release changed while the suggestion was being reviewed.');
    }
    return $release;
  }

}
