<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for events.
 */
class EventListBuilder extends EntityListBuilder {

  /**
   * Constructs an EventListBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'timestamp' => $this->t('Time'),
      'type' => $this->t('Type'),
      'message' => $this->t('Message'),
      'section' => $this->t('Section'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\changelogify\Entity\ChangelogifyEventInterface $entity */
    $row = [
      'timestamp' => $this->dateFormatter->format($entity->getTimestamp(), 'short'),
      'type' => $entity->getEventType(),
      'message' => $entity->getMessage(),
      'section' => $entity->getSectionHint() ?: '-',
    ];
    return $row + parent::buildRow($entity);
  }

}
