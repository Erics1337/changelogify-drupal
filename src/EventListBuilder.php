<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\changelogify\Form\EventExplorerFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * List builder for events.
 */
class EventListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entityType,
    // EntityListBuilder requires storage in its constructor contract.
    // @phpstan-ignore drupal.entityStorageDirectInjection
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
    protected RequestStack $requestStack,
    protected FormBuilderInterface $formBuilder,
    protected EventReleaseUsage $releaseUsage,
  ) {
    parent::__construct($entityType, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
          $entity_type,
          $container->get('entity_type.manager')->getStorage($entity_type->id()),
          $container->get('date.formatter'),
          $container->get('request_stack'),
          $container->get('form_builder'),
          $container->get(EventReleaseUsage::class),
      );
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['filters'] = $this->formBuilder->getForm(EventExplorerFilterForm::class);
    $build['filters']['#weight'] = -10;
    $request = $this->requestStack->getCurrentRequest();
    $from = (string) $request?->query->get('date_from', '');
    $to = (string) $request?->query->get('date_to', '');
    if (($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))
      || ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))) {
      $build['filter_error'] = [
        '#markup' => $this->t('One or more event filter dates are invalid.'),
        '#prefix' => '<div class="messages messages--error">',
        '#suffix' => '</div>',
        '#weight' => -9,
      ];
    }
    elseif ($from !== '' && $to !== '' && $from > $to) {
      $build['filter_error'] = [
        '#markup' => $this->t('The end date must not be before the start date.'),
        '#prefix' => '<div class="messages messages--error">',
        '#suffix' => '</div>',
        '#weight' => -9,
      ];
    }
    $build['table']['#empty'] = $this->t('No events match the selected filters.');
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->storage->getQuery()->accessCheck(FALSE);
    $request = $this->requestStack->getCurrentRequest();
    $exact = [
      'source' => 'source',
      'event_type' => 'event_type',
      'actor' => 'user_id',
      'entity_type' => 'entity_type_id',
      'bundle' => 'bundle',
      'section_hint' => 'section_hint',
      'correlation_id' => 'correlation_id',
    ];
    foreach ($exact as $parameter => $field) {
      $value = trim((string) $request?->query->get($parameter, ''));
      if ($value !== '') {
        $query->condition($field, $value);
      }
    }
    $from = (string) $request?->query->get('date_from', '');
    $to = (string) $request?->query->get('date_to', '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
      $query->condition('timestamp', strtotime($from . ' 00:00:00'), '>=');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      $query->condition('timestamp', strtotime($to . ' 23:59:59'), '<=');
    }
    $inclusion = (string) $request?->query->get('release_inclusion', '');
    if (in_array($inclusion, ['included', 'unused'], TRUE)) {
      $usedIds = array_keys($this->releaseUsage->getUsage());
      if ($inclusion === 'included') {
        if ($usedIds === []) {
          return [];
        }
        $query->condition('id', $usedIds, 'IN');
      }
      elseif ($usedIds !== []) {
        $query->condition('id', $usedIds, 'NOT IN');
      }
    }
    return $query->sort('timestamp', 'DESC')->sort('id', 'DESC')->pager($this->limit)->execute();
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
      'operations' => $this->t('Operations'),
    ];
    return $header;
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
      'operations' => Link::fromTextAndUrl($this->t('View details'), Url::fromRoute('changelogify.event_detail', [
        'changelogify_event' => $entity->id(),
      ])),
    ];
    return $row;
  }

}
