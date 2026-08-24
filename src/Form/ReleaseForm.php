<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\changelogify\ReleaseItemNormalizer;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing releases.
 */
class ReleaseForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entityRepository,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    TimeInterface $time,
    protected ReleaseItemNormalizer $itemNormalizer,
    protected ReleaseProvenanceManagerInterface $provenanceManager,
    protected DateFormatterInterface $dateFormatter,
    protected AccountProxyInterface $currentUser,
  ) {
    parent::__construct($entityRepository, $entityTypeBundleInfo, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('entity.repository'),
          $container->get('entity_type.bundle.info'),
          $container->get('datetime.time'),
          $container->get(ReleaseItemNormalizer::class),
          $container->get(ReleaseProvenanceManagerInterface::class),
          $container->get('date.formatter'),
          $container->get('current_user'),
      );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'changelogify/editor';
    $form['#cache'] = [
      'contexts' => ['user.permissions'],
      'max-age' => 0,
    ];

    // Sections are edited through the structured textareas below. Never expose
    // the JSON storage field as a second, conflicting form widget.
    unset($form['sections'], $form['status']);

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $this->entity;
    if ($form_state->get('original_editorial_state') === NULL) {
      $form_state->set('original_editorial_state', $release->getEditorialState());
    }

    // Add item-level editing without exposing provenance as client input.
    $form['sections_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Release items'),
      '#open' => TRUE,
      '#weight' => 5,
      '#tree' => TRUE,
    ];

    $sections = $release->getSections();
    $resolvedProvenance = $this->provenanceManager->getResolvedProvenance($release);
    $section_labels = [
      'added' => $this->t('Added'),
      'changed' => $this->t('Changed'),
      'fixed' => $this->t('Fixed'),
      'removed' => $this->t('Removed'),
      'security' => $this->t('Security'),
      'other' => $this->t('Other'),
    ];

    $sectionOptions = [];
    foreach ($section_labels as $key => $label) {
      $sectionOptions[$key] = $label;
    }
    $form['sections_wrapper']['items'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['changelogify-release-items']],
    ];
    $position = 0;
    foreach ($sections as $section => $items) {
      foreach ($items as $item) {
        $id = (string) $item['id'];
        $form['sections_wrapper']['items']['existing_' . $position] = $this->itemElement(
          $id,
          (string) $item['text'],
          $section,
          $position,
          $sectionOptions,
          $item['event_ids'] === [] ? $this->t('Manual item') : $this->t('Evidence-backed item'),
          TRUE,
          $resolvedProvenance['items'][$id] ?? NULL,
        );
        $position++;
      }
    }
    $form['sections_wrapper']['manual_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Add manual items'),
    ];
    for ($manual = 0; $manual < 3; $manual++) {
      $form['sections_wrapper']['items']['manual_' . $manual] = $this->itemElement(
        '',
        '',
        'other',
        $position + $manual,
        $sectionOptions,
        $this->t('New manual item @number', ['@number' => $manual + 1]),
        FALSE,
      );
    }

    return $form;
  }

  /**
   * Converts items array to text.
   */
  private function itemElement(
    string $id,
    string $text,
    string $section,
    int $order,
    array $sectionOptions,
    mixed $label,
    bool $existing = TRUE,
    ?array $evidence = NULL,
  ): array {
    $element = [
      '#type' => 'fieldset',
      '#title' => $label,
      '#attributes' => ['class' => ['changelogify-release-item']],
    ];
    $element['id'] = ['#type' => 'hidden', '#value' => $id];
    $element['text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Item text'),
      '#default_value' => $text,
      '#maxlength' => 2048,
    ];
    $element['section'] = [
      '#type' => 'select',
      '#title' => $this->t('Section'),
      '#options' => $sectionOptions,
      '#default_value' => $section,
    ];
    $element['order'] = [
      '#type' => 'number',
      '#title' => $this->t('Order'),
      '#default_value' => $order,
      '#step' => 1,
    ];
    if ($existing) {
      $element['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove this item'),
      ];
      $element['evidence'] = $this->evidenceElement($evidence);
    }
    return $element;
  }

  /**
   * Builds a privacy-bounded inline evidence panel.
   */
  private function evidenceElement(?array $evidence): array {
    if ($evidence === NULL) {
      return [
        '#type' => 'item',
        '#title' => $this->t('Source evidence'),
        '#markup' => $this->t('Editorial item — no automatic evidence.'),
      ];
    }
    $panel = [
      '#type' => 'details',
      '#title' => $this->t('Source evidence: @status', [
        '@status' => $evidence['evidence_status'] ?? 'unknown',
      ]),
      '#open' => FALSE,
    ];
    $panel['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('@kind change set with @count evidence record(s).', [
        '@kind' => $evidence['kind'] ?? 'unknown',
        '@count' => $evidence['event_count'] ?? count($evidence['events'] ?? []),
      ]),
    ];
    $rows = [];
    foreach ($evidence['events'] ?? [] as $event) {
      $eventId = (int) ($event['event_id'] ?? 0);
      $descriptor = implode(':', array_filter([
        $event['entity_type_id'] ?? NULL,
        $event['entity_id'] ?? NULL,
        $event['bundle'] ?? NULL,
      ], static fn (mixed $value): bool => $value !== NULL && $value !== ''));
      $eventLabel = $eventId > 0 ? '#' . $eventId : '-';
      if ($eventId > 0
        && ($event['evidence_status'] ?? NULL) === 'available'
        && $this->currentUser->hasPermission('administer changelogify')) {
        $eventLabel = Link::fromTextAndUrl(
          $eventLabel,
          Url::fromRoute('changelogify.event_detail', ['changelogify_event' => $eventId]),
        )->toRenderable();
      }
      $rows[] = [
        'event' => ['data' => $eventLabel],
        'status' => $event['evidence_status'] ?? 'unknown',
        'source' => $event['source'] ?? '-',
        'type' => $event['event_type'] ?? '-',
        'time' => isset($event['timestamp'])
          ? $this->dateFormatter->format((int) $event['timestamp'], 'short')
          : '-',
        'descriptor' => $descriptor ?: '-',
      ];
    }
    $panel['events'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Event'),
        $this->t('Availability'),
        $this->t('Source'),
        $this->t('Type'),
        $this->t('Time'),
        $this->t('Technical descriptor'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No retained evidence details are available.'),
    ];
    return $panel;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }
    $originalState = (string) $form_state->get('original_editorial_state');
    $targetState = (string) $form_state->getValue(['editorial_state', 0, 'value']);
    $allowed = [
      'draft' => ['draft', 'review', 'published', 'archived'],
      'review' => ['draft', 'review', 'published', 'archived'],
      'published' => ['draft', 'published', 'archived'],
      'archived' => ['draft', 'archived'],
    ];
    if (!in_array($targetState, $allowed[$originalState] ?? [], TRUE)) {
      $form_state->setErrorByName('editorial_state', $this->t('That editorial state transition is not allowed.'));
      return;
    }
    $permission = match (TRUE) {
      $targetState === 'published' || $originalState === 'published' => 'publish changelogify releases',
      $targetState === 'archived' || $originalState === 'archived' => 'archive changelogify releases',
      $targetState === 'review' || $originalState === 'review' => 'submit changelogify releases for review',
      default => NULL,
    };
    if ($permission !== NULL && !$this->currentUser->hasPermission($permission)) {
      $form_state->setErrorByName('editorial_state', $this->t('You do not have permission for that editorial state transition.'));
      return;
    }
    try {
      /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
      $release = $this->entity;
      $normalized = $this->itemNormalizer->fromStructured(
        $form_state->getValue(['sections_wrapper', 'items'], []),
        $release->getSections(),
      );
      $form_state->set('normalized_release_sections', $normalized);
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('sections_wrapper', $exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $this->entity;

    $sections = $form_state->get('normalized_release_sections');
    if (!is_array($sections)) {
      $submittedItems = $form_state->getValue(['sections_wrapper', 'items']);
      $sections = is_array($submittedItems)
        ? $this->itemNormalizer->fromStructured($submittedItems, $release->getSections())
        : $release->getSections();
    }
    $originalState = (string) $form_state->get('original_editorial_state');
    $targetState = (string) $form_state->getValue(['editorial_state', 0, 'value']);
    $release->setEditorialState($targetState);
    $release->setNewRevision(TRUE);
    $release->setRevisionUserId((int) $this->currentUser->id());
    $release->setRevisionCreationTime($this->time->getRequestTime());
    if (trim((string) $release->getRevisionLogMessage()) === '') {
      $release->setRevisionLogMessage($originalState === $targetState
        ? 'Release edited.'
        : sprintf('Editorial state changed from %s to %s.', $originalState, $targetState));
    }
    $release->setSections($sections);
    $provenance = $release->getProvenance();
    $retainedIds = [];
    foreach ($sections as $section => $items) {
      foreach ($items as $item) {
        $itemId = $item['id'];
        if (isset($provenance['items'][$itemId])) {
          $provenance['items'][$itemId]['section'] = $section;
          $retainedIds[$itemId] = TRUE;
        }
      }
    }
    $provenance['items'] = array_intersect_key($provenance['items'], $retainedIds);
    $release->setProvenance($provenance);

    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Release "@title" has been created.', ['@title' => $release->getTitle()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Release "@title" has been updated.', ['@title' => $release->getTitle()]));
    }

    $form_state->setRedirectUrl($release->toUrl('collection'));

    return $status;
  }

}
