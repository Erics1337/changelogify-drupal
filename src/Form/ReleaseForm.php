<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Form for editing releases.
 */
class ReleaseForm extends ContentEntityForm {

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * Constructs a ReleaseForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, UuidInterface $uuid) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->uuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $this->entity;

    // Add sections editing.
    $form['sections_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Release Sections'),
      '#open' => TRUE,
      '#weight' => 5,
      '#tree' => TRUE,
    ];

    $sections = $release->getSections();
    $section_labels = [
      'added' => $this->t('Added'),
      'changed' => $this->t('Changed'),
      'fixed' => $this->t('Fixed'),
      'removed' => $this->t('Removed'),
      'security' => $this->t('Security'),
      'other' => $this->t('Other'),
    ];

    foreach ($section_labels as $key => $label) {
      $items = $sections[$key] ?? [];

      $form['sections_wrapper']['section_' . $key] = [
        '#type' => 'details',
        '#title' => $label . ' (' . count($items) . ')',
        '#open' => !empty($items),
      ];

      $form['sections_wrapper']['section_' . $key]['items'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Items'),
        '#description' => $this->t('One item per line.'),
        '#default_value' => $this->itemsToText($items),
        '#rows' => max(3, count($items)),
      ];
    }

    return $form;
  }

  /**
   * Converts items array to text.
   */
  protected function itemsToText(array $items): string {
    $lines = [];
    foreach ($items as $item) {
      $lines[] = $item['text'] ?? '';
    }
    return implode("\n", $lines);
  }

  /**
   * Converts text to items array.
   */
  protected function textToItems(string $text): array {
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    $items = [];
    foreach ($lines as $line) {
      $items[] = [
        'id' => $this->uuid->generate(),
        'text' => $line,
        'event_ids' => [],
      ];
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $this->entity;

    // Build sections from form values.
    $sections = [];
    $section_keys = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];

    foreach ($section_keys as $key) {
      $text = $form_state->getValue(['sections_wrapper', 'section_' . $key, 'items'], '');
      $sections[$key] = $this->textToItems($text);
    }

    $release->setSections($sections);

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
