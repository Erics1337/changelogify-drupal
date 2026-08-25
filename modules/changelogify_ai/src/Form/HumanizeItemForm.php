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
    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instructions for this rewrite (optional)'),
      '#description' => $this->t('Temporary for this generation attempt and not saved as configuration. For example: “Focus on customer benefit” or “Use less technical language.”'),
      '#maxlength' => 1000,
      '#rows' => 3,
      '#default_value' => $form_state->getValue('instructions', ''),
    ];
    $suggestion = $form_state->get('suggestion');
    if (is_array($suggestion)) {
      $form['original'] = [
        '#type' => 'item',
        '#title' => $this->t('Current wording'),
        '#plain_text' => $suggestion['original'],
      ];
      $form['suggested'] = [
        '#type' => 'item',
        '#title' => $this->t('Suggested wording'),
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
        '#title' => $this->t('Why this note is eligible'),
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
        '#value' => $this->t('Use suggestion'),
        '#submit' => ['::acceptSubmit'],
      ];
      $form['actions']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Try again'),
        '#submit' => ['::generateSubmit'],
      ];
      $form['actions']['reject'] = [
        '#type' => 'submit',
        '#value' => $this->t('Dismiss suggestion'),
        '#submit' => ['::rejectSubmit'],
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
    if (!is_array($suggestion)) {
      $form['actions']['close'] = [
        '#type' => 'link',
        '#title' => $this->t('Close'),
        '#url' => Url::fromRoute('entity.changelogify_release.edit_form', [
          'changelogify_release' => $changelogify_release->id(),
        ]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $form;
  }

  /**
   * Requests a proposal without changing the release.
   */
  public function generateSubmit(array &$form, FormStateInterface $form_state): void {
    try {
      $release = $this->loadUnchangedRelease($form_state);
      $previous = $form_state->get('suggestion');
      $attempt = max(0, (int) $form_state->get('generation_attempt'));
      $result = $this->suggestions->suggest(
        $release,
        (string) $form_state->get('item_id'),
        (string) $form_state->getValue('profile'),
        $attempt,
        (string) $form_state->getValue('instructions'),
      );
      $form_state->set('generation_attempt', $attempt + 1);
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
    try {
      $stagedPublished = $this->suggestions->accept($release, (string) $form_state->get('item_id'), (string) $suggestion['text'], (string) $suggestion['operation_id']);
    }
    catch (\UnexpectedValueException) {
      $form_state->setErrorByName('suggestion', $this->t('This suggestion is no longer eligible for acceptance. Generate a new suggestion and try again.'));
      return;
    }
    if ($stagedPublished) {
      $this->messenger()->addStatus($this->t('The suggestion was saved in a non-public review revision. The published release is unchanged.'));
      $form_state->setRedirectUrl(Url::fromRoute('entity.changelogify_release.version_history', ['changelogify_release' => $release->id()]));
    }
    else {
      $this->messenger()->addStatus($this->t('The suggestion was used in a new release revision.'));
      $form_state->setRedirectUrl(Url::fromRoute('entity.changelogify_release.edit_form', ['changelogify_release' => $release->id()]));
    }
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
    $this->messenger()->addStatus($this->t('The suggestion was dismissed. The release was not changed.'));
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
          return $eventIds === []
            ? (string) $this->t('Manual note: no tracked change is attached.')
            : (string) $this->t('Based on @count trusted tracked change(s) already attached to this release note. Technical identifiers remain available in the release evidence details.', [
              '@count' => count($eventIds),
            ]);
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
