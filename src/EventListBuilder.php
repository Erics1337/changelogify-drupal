<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * List builder for events.
 */
class EventListBuilder extends EntityListBuilder {

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
      'timestamp' => \Drupal::service('date.formatter')->format($entity->getTimestamp(), 'short'),
      'type' => $entity->getEventType(),
      'message' => $entity->getMessage(),
      'section' => $entity->getSectionHint() ?: '-',
    ];
    return $row + parent::buildRow($entity);
  }

}
