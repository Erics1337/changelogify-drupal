<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\changelogify\ReleaseSlugManager;
use Drupal\changelogify\Event\ReleasePublishedEvent;
use Drupal\changelogify\PublicReleaseBuilder;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Changelogify Release entity.
 *
 * @ContentEntityType(
 *   id = "changelogify_release",
 *   label = @Translation("Release"),
 *   label_collection = @Translation("Releases"),
 *   label_singular = @Translation("release"),
 *   label_plural = @Translation("releases"),
 *   handlers = {
 *     "view_builder" = "Drupal\changelogify\ChangelogifyReleaseViewBuilder",
 *     "list_builder" = "Drupal\changelogify\ReleaseListBuilder",
 *     "access" = "Drupal\changelogify\Access\ChangelogifyReleaseAccessControlHandler",
 *     "storage_schema" = "Drupal\changelogify\Entity\ChangelogifyReleaseStorageSchema",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "form" = {
 *       "default" = "Drupal\changelogify\Form\ReleaseForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "revision-revert" = "Drupal\Core\Entity\Form\RevisionRevertForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *       "revision" = "Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "changelogify_release",
 *   data_table = "changelogify_release_field_data",
 *   revision_table = "changelogify_release_revision",
 *   revision_data_table = "changelogify_release_field_revision",
 *   translatable = TRUE,
 *   show_revision_ui = TRUE,
 *   admin_permission = "manage changelogify releases",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "owner" = "uid",
 *     "published" = "status",
 *     "langcode" = "langcode"
 *   },
 *   links = {
 *     "canonical" = "/admin/content/changelogify/releases/{changelogify_release}/view",
 *     "add-form" = "/admin/content/changelogify/releases/add",
 *     "edit-form" = "/admin/content/changelogify/releases/{changelogify_release}/edit",
 *     "delete-form" = "/admin/content/changelogify/releases/{changelogify_release}/delete",
 *     "collection" = "/admin/content/changelogify/releases",
 *     "revision" = "/admin/content/changelogify/releases/{changelogify_release}/revisions/{changelogify_release_revision}/view",
 *     "revision-revert-form" = "/admin/content/changelogify/releases/{changelogify_release}/revisions/{changelogify_release_revision}/revert",
 *     "version-history" = "/admin/content/changelogify/releases/{changelogify_release}/revisions",
 *     "drupal:content-translation-overview" = "/admin/content/changelogify/releases/{changelogify_release}/translations",
 *     "drupal:content-translation-add" = "/admin/content/changelogify/releases/{changelogify_release}/translations/add/{source}/{target}",
 *     "drupal:content-translation-edit" = "/admin/content/changelogify/releases/{changelogify_release}/translations/edit/{language}",
 *     "drupal:content-translation-delete" = "/admin/content/changelogify/releases/{changelogify_release}/translations/delete/{language}"
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message"
 *   }
 * )
 */
#[ContentEntityType(
  id: "changelogify_release",
  label: new TranslatableMarkup("Release"),
  label_collection: new TranslatableMarkup("Releases"),
  label_singular: new TranslatableMarkup("release"),
  label_plural: new TranslatableMarkup("releases"),
  handlers: [
    "view_builder" => "Drupal\changelogify\ChangelogifyReleaseViewBuilder",
    "list_builder" => "Drupal\changelogify\ReleaseListBuilder",
    "access" => "Drupal\changelogify\Access\ChangelogifyReleaseAccessControlHandler",
    "storage_schema" => "Drupal\changelogify\Entity\ChangelogifyReleaseStorageSchema",
    "translation" => "Drupal\content_translation\ContentTranslationHandler",
    "form" => [
      "default" => "Drupal\changelogify\Form\ReleaseForm",
      "delete" => "Drupal\Core\Entity\ContentEntityDeleteForm",
      "revision-revert" => "Drupal\Core\Entity\Form\RevisionRevertForm",
    ],
    "route_provider" => [
      "html" => "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
      "revision" => "Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider",
    ],
  ],
  base_table: "changelogify_release",
  data_table: "changelogify_release_field_data",
  revision_table: "changelogify_release_revision",
  revision_data_table: "changelogify_release_field_revision",
  translatable: TRUE,
  show_revision_ui: TRUE,
  admin_permission: "manage changelogify releases",
  entity_keys: [
    "id" => "id",
    "revision" => "revision_id",
    "uuid" => "uuid",
    "label" => "title",
    "owner" => "uid",
    "published" => "status",
    "langcode" => "langcode",
  ],
  links: [
    "canonical" => "/admin/content/changelogify/releases/{changelogify_release}/view",
    "add-form" => "/admin/content/changelogify/releases/add",
    "edit-form" => "/admin/content/changelogify/releases/{changelogify_release}/edit",
    "delete-form" => "/admin/content/changelogify/releases/{changelogify_release}/delete",
    "collection" => "/admin/content/changelogify/releases",
    "revision" => "/admin/content/changelogify/releases/{changelogify_release}/revisions/{changelogify_release_revision}/view",
    "revision-revert-form" => "/admin/content/changelogify/releases/{changelogify_release}/revisions/{changelogify_release_revision}/revert",
    "version-history" => "/admin/content/changelogify/releases/{changelogify_release}/revisions",
    "drupal:content-translation-overview" => "/admin/content/changelogify/releases/{changelogify_release}/translations",
    "drupal:content-translation-add" => "/admin/content/changelogify/releases/{changelogify_release}/translations/add/{source}/{target}",
    "drupal:content-translation-edit" => "/admin/content/changelogify/releases/{changelogify_release}/translations/edit/{language}",
    "drupal:content-translation-delete" => "/admin/content/changelogify/releases/{changelogify_release}/translations/delete/{language}",
  ],
  revision_metadata_keys: [
    "revision_user" => "revision_user",
    "revision_created" => "revision_created",
    "revision_log_message" => "revision_log_message",
  ],
)]
class ChangelogifyRelease extends ContentEntityBase implements ChangelogifyReleaseInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);
    $fields['uid']->setRevisionable(TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of the release, e.g. "October 2025 Release" or "v1.2.0".'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['label_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Label Type'))
      ->setDescription(t('The type of label for this release.'))
      ->setSetting('allowed_values', [
        'date_range' => 'Date Range',
        'custom' => 'Custom',
        'semantic_version' => 'Semantic Version',
      ])
      ->setDefaultValue('custom')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['version'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Version'))
      ->setDescription(t('Semantic version number, e.g. "1.2.0".'))
      ->setSetting('max_length', 50)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['slug'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Public slug'))
      ->setDescription(t('Stable public URL segment. Leave empty to generate it from the title.'))
      ->setSetting('max_length', 128)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -7,
      ]);

    $fields['slug_history'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Historical public slugs'))
      ->setSetting('max_length', 128)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);

    $fields['release_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Release Date'))
      ->setDescription(t('The date of the release.'))
      ->setDefaultValueCallback(static::class . '::getDefaultTimestamp')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => -7,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['date_start'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Date Start'))
      ->setDescription(t('Start of the change window this release covers.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['date_end'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Date End'))
      ->setDescription(t('End of the change window this release covers.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['sections'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Sections'))
      ->setDescription(t('JSON-encoded sections with release items.'))
      ->setDefaultValue('{}')
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['provenance'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Provenance'))
      ->setDescription(t('JSON-encoded privacy-bounded release evidence.'))
      ->setDefaultValue('{"version":1,"items":{}}');
    $fields['provenance']->setRevisionable(TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDescription(t('Whether the release is published.'))
      ->setDefaultValue(FALSE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['editorial_state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Editorial state'))
      ->setDescription(t('The private review and publication workflow state.'))
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'review' => 'Ready for review',
        'published' => 'Published',
        'archived' => 'Archived',
      ])
      ->setDefaultValue('draft')
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 9,
      ]);

    $fields['scheduled_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Scheduled publication'))
      ->setDescription(t('The canonical timestamp when this reviewed release should be published.'))
      ->setDefaultValue(0);

    $fields['scheduled_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Approved revision'))
      ->setDescription(t('The reviewed revision approved for scheduled publication.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the release was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the release was last edited.'))
      ->setRevisionable(TRUE);

    return $fields;
  }

  /**
   * Default value callback for release_date field.
   */
  public static function getDefaultTimestamp(): int {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return $this->get('title')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle(string $title): ChangelogifyReleaseInterface {
    $this->set('title', $title);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished(bool $published = TRUE): ChangelogifyReleaseInterface {
    $this->set('status', $published);
    if ($this->hasField('editorial_state')) {
      $this->set('editorial_state', $published ? 'published' : 'draft');
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setUnpublished(): ChangelogifyReleaseInterface {
    return $this->setPublished(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getEditorialState(): string {
    return $this->get('editorial_state')->value ?? ($this->isPublished() ? 'published' : 'draft');
  }

  /**
   * {@inheritdoc}
   */
  public function setEditorialState(string $state): ChangelogifyReleaseInterface {
    if (!in_array($state, ['draft', 'review', 'published', 'archived'], TRUE)) {
      throw new \InvalidArgumentException('Unknown release editorial state.');
    }
    $this->set('editorial_state', $state);
    $this->set('status', $state === 'published');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlug(): string {
    return (string) $this->get('slug')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlugHistory(): array {
    return array_values(array_map(
      static fn (array $item): string => (string) $item['value'],
      $this->get('slug_history')->getValue(),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function setSlugHistory(array $history): ChangelogifyReleaseInterface {
    foreach ($history as $slug) {
      if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9-]{0,127}$/', $slug)) {
        throw new \InvalidArgumentException('Release slug history contains an invalid slug.');
      }
    }
    $this->set('slug_history', array_values(array_unique($history)));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    \Drupal::service(ReleaseSlugManager::class)->prepare($this);
    if ($this->isNew() && $this->isPublished() && $this->getEditorialState() === 'draft') {
      $this->set('editorial_state', 'published');
    }
    $this->set('status', $this->getEditorialState() === 'published');
    if (!$this->isNew() && !$this->isNewRevision()) {
      $this->setNewRevision(TRUE);
    }
    if ($this->isNewRevision()) {
      $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $this->setRevisionUserId((int) \Drupal::currentUser()->id());
      $original = NULL;
      if (method_exists($this, 'getOriginal')) {
        $original = $this->getOriginal();
      }
      elseif (isset($this->original) && $this->original instanceof ChangelogifyReleaseInterface) {
        $original = $this->original;
      }
      $log = trim((string) $this->getRevisionLogMessage());
      $originalLog = $original === NULL ? '' : trim((string) $original->getRevisionLogMessage());
      if ($log === '' || ($original !== NULL && $log === $originalLog)) {
        $originalState = $original?->getEditorialState();
        $this->setRevisionLogMessage(
          $originalState !== NULL && $originalState !== $this->getEditorialState()
            ? sprintf('Editorial state changed from %s to %s.', $originalState, $this->getEditorialState())
            : 'Release updated.',
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    $original = method_exists($this, 'getOriginal')
      ? $this->getOriginal()
      : ($this->original ?? NULL);
    $wasPublished = $update
      && $original instanceof ChangelogifyReleaseInterface
      && $original->isPublished();
    if ($wasPublished || !$this->isPublished() || !$this->isDefaultRevision()) {
      return;
    }
    $revisionId = (int) $this->getRevisionId();
    $event = new ReleasePublishedEvent(
      $this->uuid(),
      \Drupal::service(PublicReleaseBuilder::class)
        ->releaseUrl($this->getSlug(), ['absolute' => TRUE])
        ->toString(),
      $revisionId,
      $this->language()->getId(),
      \Drupal::time()->getCurrentTime(),
      sprintf('changelogify:publication:%s:%d', $this->uuid(), $revisionId),
    );
    try {
      \Drupal::service('event_dispatcher')->dispatch($event, ReleasePublishedEvent::NAME);
    }
    catch (\Throwable $exception) {
      \Drupal::logger('changelogify')->error('A release publication subscriber failed after release @uuid was saved: @message', [
        '@uuid' => $this->uuid(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSections(): array {
    $value = $this->get('sections')->value;
    if (empty($value)) {
      return $this->getDefaultSections();
    }

    $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new \UnexpectedValueException('Release sections must decode to an array.');
    }

    return $decoded + $this->getDefaultSections();
  }

  /**
   * {@inheritdoc}
   */
  public function setSections(array $sections): ChangelogifyReleaseInterface {
    $normalized = $this->getDefaultSections();
    foreach ($sections as $section => $items) {
      if (!array_key_exists($section, $normalized)) {
        throw new \InvalidArgumentException(sprintf('Unknown release section "%s".', $section));
      }
      if (!is_array($items)) {
        throw new \InvalidArgumentException(sprintf('Release section "%s" must contain an array of items.', $section));
      }

      $normalized[$section] = [];
      foreach ($items as $item) {
        if (!is_array($item)
          || !isset($item['id'], $item['text'])
          || !is_string($item['id'])
          || $item['id'] === ''
          || !is_string($item['text'])
          || trim($item['text']) === ''
          || !is_array($item['event_ids'] ?? [])) {
          throw new \InvalidArgumentException(sprintf('Release section "%s" contains an invalid item.', $section));
        }

        $normalized[$section][] = [
          'id' => $item['id'],
          'text' => trim($item['text']),
          'event_ids' => array_values($item['event_ids'] ?? []),
        ];
      }
    }

    $this->set('sections', json_encode($normalized, JSON_THROW_ON_ERROR));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getProvenance(): array {
    $value = $this->get('provenance')->value;
    if (empty($value)) {
      return ['version' => 1, 'items' => []];
    }
    $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !is_array($decoded['items'] ?? NULL)) {
      throw new \UnexpectedValueException('Release provenance must contain versioned items.');
    }
    return ['version' => (int) ($decoded['version'] ?? 1), 'items' => $decoded['items']];
  }

  /**
   * {@inheritdoc}
   */
  public function setProvenance(array $provenance): ChangelogifyReleaseInterface {
    if (!is_array($provenance['items'] ?? NULL)) {
      throw new \InvalidArgumentException('Release provenance must contain an items array.');
    }
    if (array_diff(array_keys($provenance), ['version', 'items']) !== []) {
      throw new \InvalidArgumentException('Release provenance contains unsupported top-level data.');
    }
    $items = [];
    foreach ($provenance['items'] as $itemId => $item) {
      if (!is_string($itemId) || $itemId === '' || !is_array($item)) {
        throw new \InvalidArgumentException('Release provenance contains an invalid item.');
      }
      $items[$itemId] = $this->normalizeProvenanceItem($item);
    }
    $this->set('provenance', json_encode([
      'version' => (int) ($provenance['version'] ?? 1),
      'items' => $items,
    ], JSON_THROW_ON_ERROR));
    return $this;
  }

  /**
   * Validates one privacy-bounded provenance item.
   */
  private function normalizeProvenanceItem(array $item): array {
    $allowed = [
      'change_set_id',
      'change_set_ids',
      'kind',
      'section',
      'event_ids',
      'event_count',
      'evidence_status',
      'events',
      'evidence_reuse',
    ];
    if (array_diff(array_keys($item), $allowed) !== []) {
      throw new \InvalidArgumentException('Release provenance item contains unsupported data.');
    }
    if (!is_array($item['event_ids'] ?? NULL) || !is_array($item['events'] ?? NULL)) {
      throw new \InvalidArgumentException('Release provenance event references must be arrays.');
    }
    if (isset($item['change_set_ids'])) {
      if (!is_array($item['change_set_ids']) || $item['change_set_ids'] === []) {
        throw new \InvalidArgumentException('Release provenance change-set references must be a non-empty array.');
      }
      foreach ($item['change_set_ids'] as $changeSetId) {
        if (!is_string($changeSetId) || $changeSetId === '') {
          throw new \InvalidArgumentException('Release provenance contains an invalid change-set reference.');
        }
      }
    }
    foreach ($item['events'] as $event) {
      if (!is_array($event)) {
        throw new \InvalidArgumentException('Release provenance contains invalid event evidence.');
      }
      $this->validateProvenanceEvent($event);
    }
    if (isset($item['evidence_reuse'])) {
      $reuse = $item['evidence_reuse'];
      if (!is_array($reuse)
        || array_diff(array_keys($reuse), ['release_ids', 'confirmed_by', 'confirmed_at']) !== []
        || !is_array($reuse['release_ids'] ?? NULL)) {
        throw new \InvalidArgumentException('Release provenance contains invalid evidence reuse attribution.');
      }
      foreach ($reuse['release_ids'] as $releaseId) {
        if (!is_int($releaseId) || $releaseId < 1) {
          throw new \InvalidArgumentException('Release provenance contains an invalid reused release ID.');
        }
      }
      foreach (['confirmed_by', 'confirmed_at'] as $key) {
        if (!is_int($reuse[$key] ?? NULL) || $reuse[$key] < 0) {
          throw new \InvalidArgumentException('Release provenance contains invalid evidence reuse attribution.');
        }
      }
    }
    $this->validateEvidenceStatus($item['evidence_status'] ?? NULL);
    return $item;
  }

  /**
   * Rejects event evidence outside the explicitly redacted schema.
   */
  private function validateProvenanceEvent(array $event): void {
    $allowed = [
      'event_id',
      'event_uuid',
      'event_type',
      'source',
      'timestamp',
      'schema_version',
      'correlation_id',
      'entity_type_id',
      'entity_id',
      'bundle',
      'evidence_status',
    ];
    if (array_diff(array_keys($event), $allowed) !== []) {
      throw new \InvalidArgumentException('Release provenance event contains unsupported data.');
    }
    $this->validateEvidenceStatus($event['evidence_status'] ?? NULL);
  }

  /**
   * Validates an evidence lifecycle status.
   */
  private function validateEvidenceStatus(mixed $status): void {
    if (!is_string($status) || !in_array($status, [
      'available',
      'expired',
      'missing',
      'invalid',
      'partial',
      'removed',
    ], TRUE)) {
      throw new \InvalidArgumentException('Release provenance contains an invalid evidence status.');
    }
  }

  /**
   * Get default sections structure.
   */
  protected function getDefaultSections(): array {
    return [
      'added' => [],
      'changed' => [],
      'fixed' => [],
      'removed' => [],
      'security' => [],
      'other' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReleaseDate(): int {
    return (int) $this->get('release_date')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): ?string {
    return $this->get('version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduledPublicationTime(): int {
    return (int) ($this->get('scheduled_at')->value ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduledRevisionId(): ?int {
    $revisionId = (int) ($this->get('scheduled_revision_id')->value ?? 0);
    return $revisionId > 0 ? $revisionId : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublicationSchedule(int $timestamp = 0, ?int $revisionId = NULL): ChangelogifyReleaseInterface {
    if ($timestamp < 0 || ($timestamp > 0 && ($revisionId ?? 0) < 1)) {
      throw new \InvalidArgumentException('A publication schedule requires a timestamp and approved revision.');
    }
    $this->set('scheduled_at', $timestamp);
    $this->set('scheduled_revision_id', $timestamp > 0 ? $revisionId : NULL);
    return $this;
  }

}
