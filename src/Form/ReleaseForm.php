<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\changelogify\ReleaseItemNormalizer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
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
      );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Sections are edited through the structured textareas below. Never expose
    // the JSON storage field as a second, conflicting form widget.
    unset($form['sections']);

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
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $this->entity;

    // Build sections from form values while preserving source event IDs.
    $existingSections = $release->getSections();
    $sections = [];
    $section_keys = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];

    foreach ($section_keys as $key) {
      $text = $form_state->getValue(['sections_wrapper', 'section_' . $key, 'items'], '');
      $sections[$key] = $this->itemNormalizer->fromText(
            $text,
            $existingSections[$key] ?? [],
        );
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
