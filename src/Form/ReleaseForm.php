<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\changelogify\PublicReleaseBuilder;
use Drupal\changelogify\ReleaseItemNormalizer;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
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
    protected PublicReleaseBuilder $publicReleaseBuilder,
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
          $container->get(PublicReleaseBuilder::class),
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
    $form['#attached']['library'][] = 'changelogify/public';
    $form['#cache'] = [
      'contexts' => ['user.permissions'],
      'max-age' => 0,
    ];
    $previewFirst = $this->getRequest()->query->get('display') === 'preview';
    $form['#attributes']['data-changelogify-release-editor'] = '';
    $form['#attributes']['data-changelogify-release-mode'] = $previewFirst ? 'preview' : 'edit';

    // Sections are edited through the structured textareas below. Never expose
    // the JSON storage field as a second, conflicting form widget.
    unset($form['sections'], $form['status'], $form['scheduled_at'], $form['scheduled_revision_id']);

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $this->entity;
    if ((int) $release->get('date_start')->value === 0) {
      unset($form['date_start']);
      $form['date_start_unbounded'] = [
        '#type' => 'item',
        '#title' => $this->t('Date Start'),
        '#markup' => $this->t('Beginning of recorded history'),
        '#description' => $this->t('This legacy boundary represents all recorded history before the release end date.'),
        '#weight' => 2,
      ];
    }
    if ($form_state->get('original_editorial_state') === NULL) {
      $form_state->set('original_editorial_state', $release->getEditorialState());
    }

    // Add item-level editing without exposing provenance as client input.
    $form['sections_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Release notes'),
      '#description' => $this->t('Edit the public-facing wording first. Section, ordering, removal, and source details remain available as secondary controls.'),
      '#open' => TRUE,
      '#weight' => 5,
      '#tree' => TRUE,
      '#attributes' => ['class' => ['changelogify-release-edit-only']],
      '#prefix' => '<div id="changelogify-release-items-editor">',
      '#suffix' => '</div>',
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
    $presentation = $this->publicReleaseBuilder->build($release, array_keys($section_labels));
    $form['release_view_controls'] = [
      '#type' => 'container',
      '#weight' => 3,
      '#attributes' => [
        'class' => ['changelogify-release-view-controls'],
        'role' => 'group',
        'aria-label' => $this->t('Release-note view'),
      ],
      'preview' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Preview changelog'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button'],
          'data-changelogify-release-view' => 'preview',
          'aria-controls' => 'changelogify-release-preview',
        ],
      ],
      'edit' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Edit summary notes'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button'],
          'data-changelogify-release-view' => 'edit',
          'aria-controls' => 'changelogify-release-items-editor',
        ],
      ],
    ];
    $form['release_preview'] = [
      '#type' => 'container',
      '#weight' => 4,
      '#attributes' => [
        'id' => 'changelogify-release-preview',
        'class' => ['changelogify-release-preview'],
        'data-changelogify-release-preview' => '',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => Html::escape($presentation['title']),
        '#attributes' => ['class' => ['changelogify-release-preview__title']],
      ],
      'release' => [
        '#theme' => 'changelogify_release',
        '#title' => $presentation['title'],
        '#date' => $presentation['date'],
        '#date_iso' => $presentation['date_iso'],
        '#version' => $presentation['version'],
        '#sections' => $presentation['sections'],
        '#translation_fallback' => $presentation['translation_fallback'],
        '#language_name' => $presentation['language_name'],
      ],
      'no_js' => [
        '#type' => 'inline_template',
        '#template' => '<noscript><p>{{ message }}</p></noscript>',
        '#context' => [
          'message' => $this->t('This preview shows the saved release. Save your edits to refresh it.'),
        ],
      ],
    ];
    $form['#attached']['drupalSettings']['changelogifyReleaseEditor']['sectionLabels'] = array_map(
      static fn (mixed $label): string => (string) $label,
      $section_labels,
    );
    if (isset($resolvedProvenance['synthesis']) && is_array($resolvedProvenance['coverage'] ?? NULL)) {
      $noteCount = array_sum(array_map('count', $sections));
      $evidenceCount = max(0, (int) ($resolvedProvenance['coverage']['evidence_considered'] ?? 0));
      $noteLabel = $this->formatPlural($noteCount, '1 summary note', '@count summary notes');
      $evidenceLabel = $this->formatPlural($evidenceCount, '1 eligible change group', '@count eligible change groups');
      $form['synthesis_overview'] = [
        '#type' => 'item',
        '#weight' => 3,
        '#markup' => $this->t('@notes synthesized from @evidence.', [
          '@notes' => $noteLabel,
          '@evidence' => $evidenceLabel,
        ]),
        '#wrapper_attributes' => ['class' => ['changelogify-synthesis-overview']],
      ];
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
          $item['event_ids'] === [] ? $this->t('Manual summary note') : $this->t('Summary note'),
          TRUE,
          $resolvedProvenance['items'][$id] ?? NULL,
        );
        $position++;
      }
    }
    $manualCount = max(0, (int) $form_state->get('manual_item_count'));
    for ($manual = 0; $manual < $manualCount; $manual++) {
      $form['sections_wrapper']['items']['manual_' . $manual] = $this->itemElement(
        '',
        '',
        'other',
        $position + $manual,
        $sectionOptions,
        $this->t('Manual summary note @number', ['@number' => $manual + 1]),
        FALSE,
      );
    }
    $form['sections_wrapper']['add_manual'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add manual note'),
      '#submit' => ['::addManualSubmit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::refreshItems',
        'wrapper' => 'changelogify-release-items-editor',
      ],
      '#attributes' => ['class' => ['button', 'button--small']],
    ];

    if ($this->currentUser->hasPermission('publish changelogify releases')) {
      $scheduledAt = $release->getScheduledPublicationTime();
      $defaultDate = NULL;
      if ($scheduledAt > 0) {
        $defaultDate = new DrupalDateTime('@' . $scheduledAt);
        $defaultDate->setTimezone(new \DateTimeZone(date_default_timezone_get()));
      }
      $form['publication_schedule'] = [
        '#type' => 'details',
        '#title' => $this->t('Scheduled publication'),
        '#description' => $this->t('Save a release as Ready for review before scheduling it. Drupal cron will publish the exact reviewed content at or after this time.'),
        '#open' => $scheduledAt > 0,
        '#weight' => 8,
      ];
      if ($scheduledAt > 0) {
        $form['publication_schedule']['schedule_status'] = [
          '#type' => 'item',
          '#title' => $this->t('Current schedule'),
          '#markup' => $this->t('@date (approved revision @revision)', [
            '@date' => $this->dateFormatter->format($scheduledAt, 'long'),
            '@revision' => $release->getScheduledRevisionId() ?? '-',
          ]),
        ];
      }
      $form['publication_schedule']['publish_at'] = [
        '#type' => 'datetime',
        '#title' => $scheduledAt > 0 ? $this->t('Reschedule for') : $this->t('Publish at'),
        '#default_value' => $defaultDate,
        '#date_increment' => 1,
        '#description' => $this->t('Shown in your Drupal timezone and stored as a canonical timestamp. Leave blank to keep the current schedule unchanged.'),
      ];
      if ($scheduledAt > 0) {
        $form['publication_schedule']['cancel_schedule'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Cancel scheduled publication'),
        ];
      }
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
      '#attributes' => [
        'class' => ['changelogify-release-item'],
        'data-changelogify-item' => $id,
      ],
    ];
    $element['id'] = ['#type' => 'hidden', '#value' => $id];
    $element['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Release note'),
      '#default_value' => $text,
      '#maxlength' => 2048,
      '#rows' => 2,
      '#attributes' => ['class' => ['changelogify-release-item__text']],
    ];
    $element['section'] = [
      '#type' => 'select',
      '#title' => $this->t('Section'),
      '#options' => $sectionOptions,
      '#default_value' => $section,
      '#wrapper_attributes' => ['class' => ['changelogify-release-item__section']],
    ];
    $element['order'] = [
      '#type' => 'number',
      '#title' => $this->t('Order'),
      '#default_value' => $order,
      '#step' => 1,
      '#min' => 0,
      '#description' => $this->t('No-JavaScript ordering fallback. With JavaScript, use the compact move controls or drag handle.'),
      '#wrapper_attributes' => ['class' => ['changelogify-release-item__order-fallback']],
    ];
    if ($existing) {
      $element['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove this note when changes are saved'),
        '#wrapper_attributes' => ['class' => ['changelogify-release-item__remove-fallback']],
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
        '#title' => $this->t('Why this note is here'),
        '#markup' => $this->t('Manual note — no tracked change is attached.'),
      ];
    }
    $status = (string) ($evidence['evidence_status'] ?? 'unknown');
    $eventCount = (int) ($evidence['event_count'] ?? count($evidence['events'] ?? []));
    $panel = [
      '#type' => 'details',
      '#title' => $this->formatPlural(
        $eventCount,
        'Supporting evidence — 1 tracked change',
        'Supporting evidence — @count tracked changes',
      ),
      '#open' => FALSE,
    ];
    $panel['availability'] = [
      '#type' => 'item',
      '#title' => $this->t('Evidence availability'),
      '#plain_text' => $this->evidenceStatusLabel($status),
    ];
    $panel['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('These retained, privacy-bounded details explain which recorded site changes support this release note.'),
    ];
    $editorialItems = [];
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
      $object = implode(' · ', array_filter([
        $event['bundle'] ?? NULL,
        $event['entity_type_id'] ?? NULL,
      ], static fn (mixed $value): bool => $value !== NULL && $value !== ''));
      $editorialItems[] = $this->t('@change@object · @date · @status', [
        '@change' => ucfirst(str_replace('_', ' ', (string) ($event['event_type'] ?? 'Recorded change'))),
        '@object' => $object === '' ? '' : ' · ' . $object,
        '@date' => isset($event['timestamp'])
          ? $this->dateFormatter->format((int) $event['timestamp'], 'short')
          : $this->t('Date unavailable'),
        '@status' => $this->evidenceStatusLabel((string) ($event['evidence_status'] ?? 'unknown')),
      ]);
      $rows[] = [
        'event' => ['data' => $eventLabel],
        'status' => $event['evidence_status'] ?? 'unknown',
        'source' => $event['source'] ?? '-',
        'type' => $event['event_type'] ?? '-',
        'time' => isset($event['timestamp'])
          ? $this->dateFormatter->format((int) $event['timestamp'], 'short')
          : '-',
        'schema' => $event['schema_version'] ?? '-',
        'descriptor' => $descriptor ?: '-',
      ];
    }
    $panel['editorial_details'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Tracked changes supporting this note'),
      '#items' => $editorialItems,
      '#empty' => $this->t('No retained tracked-change details are available.'),
    ];
    $reuseReleaseIds = array_values(array_filter(array_map(
      'intval',
      $evidence['evidence_reuse']['release_ids'] ?? [],
    )));
    if ($reuseReleaseIds !== []) {
      $panel['reuse'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Also used by'),
        '#items' => array_map(fn (int $releaseId): array => Link::fromTextAndUrl(
          $this->t('Release @id', ['@id' => $releaseId]),
          Url::fromRoute('entity.changelogify_release.canonical', [
            'changelogify_release' => $releaseId,
          ]),
        )->toRenderable(), $reuseReleaseIds),
      ];
    }
    $panel['technical'] = [
      '#type' => 'details',
      '#title' => $this->t('Technical details'),
      '#open' => FALSE,
    ];
    $changeSetIds = array_values(array_filter(array_map(
      'strval',
      $evidence['change_set_ids'] ?? [$evidence['change_set_id'] ?? ''],
    )));
    $panel['technical']['change_sets'] = [
      '#type' => 'item',
      '#title' => $this->t('Change-set identifiers'),
      '#plain_text' => $changeSetIds === [] ? '-' : implode(', ', $changeSetIds),
    ];
    $panel['technical']['events'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Event'),
        $this->t('Availability'),
        $this->t('Source'),
        $this->t('Type'),
        $this->t('Time'),
        $this->t('Schema'),
        $this->t('Technical descriptor'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No retained evidence details are available.'),
    ];
    if (!empty($evidence['snapshots_truncated'])) {
      $panel['truncated'] = [
        '#type' => 'item',
        '#markup' => $this->t('Only bounded retained evidence details are shown here; the tracked-change count remains complete.'),
      ];
    }
    $releaseId = $this->entity->id();
    if (is_numeric($releaseId)) {
      $panel['provenance_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Review full release provenance'),
        '#url' => Url::fromRoute('changelogify.release_provenance', [
          'changelogify_release' => (int) $releaseId,
        ]),
      ];
    }
    return $panel;
  }

  /**
   * Returns an editor-facing label for an evidence lifecycle state.
   */
  private function evidenceStatusLabel(string $status): mixed {
    return match ($status) {
      'available' => $this->t('Evidence available'),
      'partial' => $this->t('Some evidence unavailable'),
      'expired' => $this->t('Evidence expired'),
      'missing' => $this->t('Evidence missing'),
      'removed' => $this->t('Evidence details removed'),
      default => $this->t('Evidence status unknown'),
    };
  }

  /**
   * Adds one explicit blank manual-note editor and preserves unsaved values.
   */
  public function addManualSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('manual_item_count', max(0, (int) $form_state->get('manual_item_count')) + 1);
    $form_state->setRebuild();
  }

  /**
   * Returns the rebuilt release-item workspace for AJAX requests.
   */
  public function refreshItems(array &$form, FormStateInterface $form_state): array {
    return $form['sections_wrapper'];
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
    $cancelSchedule = (bool) $form_state->getValue('cancel_schedule', FALSE);
    $scheduledDate = $form_state->getValue('publish_at');
    $timestamp = $this->scheduleTimestamp($scheduledDate);
    if (!$cancelSchedule && $timestamp > 0) {
      /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
      $release = $this->entity;
      $isChanged = $timestamp !== $release->getScheduledPublicationTime();
      if ($isChanged && $targetState !== 'review') {
        $form_state->setErrorByName('publish_at', $this->t('Only a release in Ready for review can be scheduled.'));
        return;
      }
      if ($isChanged && $timestamp <= $this->time->getRequestTime()) {
        $form_state->setErrorByName('publish_at', $this->t('Choose a future publication time.'));
        return;
      }
    }
    try {
      /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
      $release = $this->entity;
      $normalized = $this->itemNormalizer->fromStructured(
        $form_state->getValue(['sections_wrapper', 'items'], []),
        $release->getSections(),
      );
      if ($release->isNew() && array_sum(array_map('count', $normalized)) === 0) {
        $form_state->setErrorByName('sections_wrapper', $this->t('Add at least one release note before creating a release.'));
        return;
      }
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
    $cancelSchedule = (bool) $form_state->getValue('cancel_schedule', FALSE);
    $requestedTimestamp = $this->scheduleTimestamp($form_state->getValue('publish_at'));
    $existingTimestamp = $release->getScheduledPublicationTime();

    $sections = $form_state->get('normalized_release_sections');
    if (!is_array($sections)) {
      $submittedItems = $form_state->getValue(['sections_wrapper', 'items']);
      $sections = is_array($submittedItems)
        ? $this->itemNormalizer->fromStructured($submittedItems, $release->getSections())
        : $release->getSections();
    }
    $originalState = (string) $form_state->get('original_editorial_state');
    $targetState = (string) $form_state->getValue(['editorial_state', 0, 'value']);
    $shouldCancelSchedule = $existingTimestamp > 0
      && ($cancelSchedule || $targetState !== 'review');
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

    if ($shouldCancelSchedule) {
      $release->setPublicationSchedule();
      $release->setNewRevision(TRUE);
      $release->setRevisionLogMessage('Scheduled publication canceled by an editor.');
      $release->save();
      $this->messenger()->addStatus($this->t('Scheduled publication has been canceled.'));
    }
    elseif ($requestedTimestamp > 0 && $requestedTimestamp !== $existingTimestamp) {
      $approvedRevisionId = (int) $release->getRevisionId();
      $release->setPublicationSchedule($requestedTimestamp, $approvedRevisionId);
      $release->setNewRevision(TRUE);
      $release->setRevisionLogMessage($existingTimestamp > 0
        ? 'Scheduled publication rescheduled by an editor.'
        : 'Scheduled publication approved by an editor.');
      $release->save();
      $this->messenger()->addStatus($this->t('Publication is scheduled for @date.', [
        '@date' => $this->dateFormatter->format($requestedTimestamp, 'long'),
      ]));
    }

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Release "@title" has been created.', ['@title' => $release->getTitle()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Release "@title" has been updated.', ['@title' => $release->getTitle()]));
    }

    $form_state->setRedirectUrl($release->toUrl('collection'));

    return $status;
  }

  /**
   * Normalizes a processed or raw Drupal datetime form value.
   */
  private function scheduleTimestamp(mixed $value): int {
    if (is_object($value) && method_exists($value, 'getTimestamp')) {
      return (int) $value->getTimestamp();
    }
    if (is_object($value) && method_exists($value, 'format')) {
      return (int) $value->format('U');
    }
    if (is_array($value) && !empty($value['date'])) {
      try {
        return (new DrupalDateTime(
          trim((string) $value['date'] . ' ' . (string) ($value['time'] ?? '00:00:00')),
          new \DateTimeZone(date_default_timezone_get()),
        ))->getTimestamp();
      }
      catch (\Exception) {
        return 0;
      }
    }
    return 0;
  }

}
