<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\changelogify\EventInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Captures supported content-entity lifecycle events.
 */
final class ContentEventSource implements EventSourceInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventSourceRecorderInterface $recorder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'content';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Track content changes';
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivacyDescription(): string {
    return 'Log events when content is created, updated, or deleted.';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationDefaults(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedEventTypes(): array {
    $types = [];
    foreach (['node', 'media', 'block_content', 'taxonomy_term'] as $entityType) {
      foreach (['created', 'updated', 'deleted', 'published', 'unpublished'] as $action) {
        $types[] = $entityType . '_' . $action;
      }
    }
    return $types;
  }

  /**
   * {@inheritdoc}
   */
  public function getLegacyEnabledSetting(): ?string {
    return 'track_content';
  }

  /**
   * Implements hook_entity_insert().
   */
  public function entityInsert(EntityInterface $entity): void {
    if ($this->shouldTrack($entity)) {
      $this->record($entity, 'created', 'added');
    }
  }

  /**
   * Implements hook_entity_update().
   */
  public function entityUpdate(EntityInterface $entity): void {
    if (!$this->shouldTrack($entity)) {
      return;
    }

    $publicationAction = $this->getPublicationAction($entity);
    if ($publicationAction === 'published') {
      $this->record($entity, 'published', 'added');
    }
    elseif ($publicationAction === 'unpublished') {
      $this->record($entity, 'unpublished', 'removed');
    }
    else {
      $this->record($entity, 'updated', 'changed');
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  public function entityDelete(EntityInterface $entity): void {
    if ($this->shouldTrack($entity)) {
      $this->record($entity, 'deleted', 'removed');
    }
  }

  /**
   * Determines whether a supported entity may be captured.
   */
  private function shouldTrack(EntityInterface $entity): bool {
    if (!$this->recorder->isEnabled($this)) {
      return FALSE;
    }
    if (!in_array($entity->getEntityTypeId(), ['node', 'media', 'block_content', 'taxonomy_term'], TRUE)) {
      return FALSE;
    }

    $trackUnpublished = (bool) ($this->configFactory
      ->get('changelogify.settings')
      ->get('track_unpublished_content') ?? FALSE);
    return !$entity instanceof EntityPublishedInterface
      || $entity->isPublished()
      || $trackUnpublished;
  }

  /**
   * Records a supported content change.
   */
  private function record(EntityInterface $entity, string $action, string $sectionHint): void {
    $this->recorder->record($this, new EventInput(
      eventType: $entity->getEntityTypeId() . '_' . $action,
      source: 'content_entity',
      message: $this->buildMessage($entity, $action),
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      entityTypeId: $entity->getEntityTypeId(),
      entityId: $entity->id() === NULL ? NULL : (int) $entity->id(),
      bundle: $entity->bundle() ?: NULL,
      sectionHint: $sectionHint,
      metadata: $this->buildMetadata($entity, $action),
    ));
  }

  /**
   * Builds a backwards-compatible event message.
   */
  private function buildMessage(EntityInterface $entity, string $action): string {
    $verbs = [
      'created' => $this->t('Created'),
      'updated' => $this->t('Updated'),
      'deleted' => $this->t('Deleted'),
      'published' => $this->t('Published'),
      'unpublished' => $this->t('Unpublished'),
    ];
    return $this->t('@verb @descriptor: "@label"', [
      '@verb' => $verbs[$action] ?? ucfirst($action),
      '@descriptor' => $this->buildDescriptor($entity),
      '@label' => $this->buildLabel($entity),
    ])->__toString();
  }

  /**
   * Builds privacy-bounded event metadata.
   */
  private function buildMetadata(EntityInterface $entity, string $action): array {
    $metadata = ['action' => $action, 'label' => $this->buildLabel($entity)];
    try {
      $metadata['path'] = $entity->toUrl()->toString();
    }
    catch (\Throwable) {
      // Some new or deleted entities do not have a routable URL.
    }
    return $metadata;
  }

  /**
   * Builds a human-friendly entity descriptor.
   */
  private function buildDescriptor(EntityInterface $entity): string {
    $bundleLabel = $this->getBundleLabel($entity);
    return match ($entity->getEntityTypeId()) {
      'media' => $this->t('@bundle media item', ['@bundle' => $bundleLabel])->__toString(),
      'block_content' => $this->t('@bundle block', ['@bundle' => $bundleLabel])->__toString(),
      'taxonomy_term' => $this->t('@bundle term', ['@bundle' => $bundleLabel])->__toString(),
      default => $bundleLabel,
    };
  }

  /**
   * Gets a friendly bundle label.
   */
  private function getBundleLabel(EntityInterface $entity): string {
    $bundle = $entity->bundle();
    if ($bundle !== NULL && $bundle !== '') {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId());
      return !empty($bundleInfo[$bundle]['label'])
        ? (string) $bundleInfo[$bundle]['label']
        : ucwords(str_replace(['_', '-'], ' ', $bundle));
    }
    return (string) ($entity->getEntityType()->getLabel()
      ?? ucfirst(str_replace('_', ' ', $entity->getEntityTypeId())));
  }

  /**
   * Gets a stable display label.
   */
  private function buildLabel(EntityInterface $entity): string {
    $label = $entity->label();
    return $label !== NULL && $label !== '' ? $label : $this->t('Untitled')->__toString();
  }

  /**
   * Detects publication state changes.
   */
  private function getPublicationAction(EntityInterface $entity): ?string {
    if (!$entity instanceof EntityPublishedInterface) {
      return NULL;
    }
    $original = $this->getOriginalEntity($entity);
    if (!$original instanceof EntityPublishedInterface) {
      return NULL;
    }
    if (!$original->isPublished() && $entity->isPublished()) {
      return 'published';
    }
    return $original->isPublished() && !$entity->isPublished() ? 'unpublished' : NULL;
  }

  /**
   * Returns the original entity across supported Drupal versions.
   */
  private function getOriginalEntity(EntityInterface $entity): ?EntityInterface {
    if (method_exists($entity, 'getOriginal')) {
      $original = $entity->getOriginal();
      return $original instanceof EntityInterface ? $original : NULL;
    }
    return isset($entity->original) && $entity->original instanceof EntityInterface
      ? $entity->original
      : NULL;
  }

}
