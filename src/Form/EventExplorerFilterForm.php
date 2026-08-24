<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides GET filters for the administrative event explorer.
 */
final class EventExplorerFilterForm extends FormBase {

  public const FILTERS = [
    'date_from', 'date_to', 'source', 'event_type', 'actor', 'entity_type',
    'bundle', 'section_hint', 'correlation_id', 'release_inclusion',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'changelogify_event_explorer_filters';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'changelogify-event-filters';
    $textFields = [
      'source' => $this->t('Source'),
      'event_type' => $this->t('Event type'),
      'actor' => $this->t('Actor user ID'),
      'entity_type' => $this->t('Entity type'),
      'bundle' => $this->t('Bundle'),
      'correlation_id' => $this->t('Correlation ID'),
    ];
    foreach ($textFields as $name => $title) {
      $form[$name] = [
        '#type' => $name === 'actor' ? 'number' : 'textfield',
        '#title' => $title,
        '#default_value' => $request->query->get($name, ''),
        '#size' => 20,
      ];
      if ($name === 'actor') {
        $form[$name]['#min'] = 0;
      }
    }
    foreach (['date_from' => $this->t('From'), 'date_to' => $this->t('Through')] as $name => $title) {
      $form[$name] = [
        '#type' => 'date',
        '#title' => $title,
        '#default_value' => $request->query->get($name, ''),
      ];
    }
    $sections = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];
    $form['section_hint'] = [
      '#type' => 'select',
      '#title' => $this->t('Section'),
      '#empty_option' => $this->t('- Any -'),
      '#options' => array_combine($sections, array_map('ucfirst', $sections)),
      '#default_value' => $request->query->get('section_hint', ''),
    ];
    $form['release_inclusion'] = [
      '#type' => 'select',
      '#title' => $this->t('Release use'),
      '#empty_option' => $this->t('- Any -'),
      '#options' => [
        'included' => $this->t('Included in a release'),
        'unused' => $this->t('Not included in a release'),
      ],
      '#default_value' => $request->query->get('release_inclusion', ''),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Apply filters')];
    $form['actions']['clear'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear filters'),
      '#url' => Url::fromRoute('entity.changelogify_event.collection'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['date_from', 'date_to'] as $name) {
      $value = trim((string) $form_state->getValue($name));
      if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $form_state->setErrorByName($name, $this->t('Enter a valid date.'));
      }
    }
    $from = (string) $form_state->getValue('date_from');
    $to = (string) $form_state->getValue('date_to');
    if ($from !== '' && $to !== '' && $from > $to) {
      $form_state->setErrorByName('date_to', $this->t('The end date must not be before the start date.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    foreach (self::FILTERS as $name) {
      $value = trim((string) $form_state->getValue($name));
      if ($value !== '') {
        $query[$name] = $value;
      }
    }
    $form_state->setRedirect('entity.changelogify_event.collection', [], ['query' => $query]);
  }

}
