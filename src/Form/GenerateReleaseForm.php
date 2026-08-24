<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\changelogify\ReleaseGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for generating a new release.
 */
class GenerateReleaseForm extends FormBase {

  /**
   * Constructs a GenerateReleaseForm.
   */
  public function __construct(
    protected ReleaseGeneratorInterface $releaseGenerator,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ReleaseGeneratorInterface::class),
      $container->get('logger.factory')->get('changelogify'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'changelogify_generate_release_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($form_state->has('preview')) {
      return $this->buildPreviewForm($form, $form_state);
    }

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Date Range'),
      '#options' => [
        'since_last' => $this->t('Since last release'),
        'custom' => $this->t('Custom date range'),
      ],
      '#default_value' => 'since_last',
      '#required' => TRUE,
    ];

    $form['date_range'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['date_range']['start_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start Date'),
      '#date_date_element' => 'date',
      '#date_time_element' => 'none',
    ];

    $form['date_range']['end_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End Date'),
      '#date_date_element' => 'date',
      '#date_time_element' => 'none',
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Release Options'),
      '#open' => FALSE,
    ];

    $form['options']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#description' => $this->t('Leave empty to auto-generate based on date.'),
      '#maxlength' => 255,
    ];

    $form['options']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#description' => $this->t('Optional semantic version, e.g. 1.2.0'),
      '#maxlength' => 50,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview changes'),
      '#button_type' => 'primary',
      '#submit' => ['::previewSubmit'],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('changelogify.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->has('preview') && $form_state->getValue('mode') === 'custom') {
      $start = $form_state->getValue('start_date');
      $end = $form_state->getValue('end_date');

      if (empty($start) || empty($end)) {
        $form_state->setError($form['date_range'], $this->t('Please specify both start and end dates for custom range.'));
        return;
      }

      if (!$start instanceof DrupalDateTime || !$end instanceof DrupalDateTime) {
        $form_state->setError($form['date_range'], $this->t('The selected date range is invalid.'));
        return;
      }

      if ($start->getTimestamp() > $end->getTimestamp()) {
        $form_state->setError($form['date_range'], $this->t('The end date must be on or after the start date.'));
      }
    }

    $version = trim((string) $form_state->getValue('version'));
    if ($version !== '' && !preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
      $form_state->setErrorByName('version', $this->t('Enter a semantic version such as 1.2.0 or 1.2.0-beta.1.'));
    }

    if ($form_state->has('preview')) {
      $preview = $form_state->get('preview');
      $candidateIds = array_column($preview['change_sets'], NULL, 'id');
      $values = $form_state->getValue('change_sets', []);
      $selected = 0;
      foreach ($values as $changeSetId => $value) {
        if (!isset($candidateIds[$changeSetId])) {
          $form_state->setErrorByName('change_sets', $this->t('The preview contains an invalid change-set identifier. Preview the range again.'));
          continue;
        }
        if (!empty($value['include'])) {
          $selected++;
          if (!in_array($value['section'] ?? '', $this->sections(), TRUE)) {
            $form_state->setErrorByName('change_sets', $this->t('Select a valid section for every included change set.'));
          }
        }
      }
      if ($selected === 0 && !$form_state->getValue('confirm_empty')) {
        $form_state->setErrorByName('confirm_empty', $this->t('Confirm that you want to create an empty draft.'));
      }
    }
  }

  /**
   * Builds the change-set selection step.
   */
  private function buildPreviewForm(array $form, FormStateInterface $form_state): array {
    $preview = $form_state->get('preview');
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Release window'),
      '#markup' => $this->t('@start through @end', [
        '@start' => date('Y-m-d H:i:s T', $preview['start']),
        '@end' => date('Y-m-d H:i:s T', $preview['end']),
      ]),
    ];
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Release options'),
      '#open' => TRUE,
    ];
    $form['options']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('title', ''),
    ];
    $form['options']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#maxlength' => 50,
      '#default_value' => $form_state->getValue('version', ''),
    ];
    $form['change_sets'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    $sectionOptions = array_combine(
      $this->sections(),
      array_map('ucfirst', $this->sections()),
    );
    foreach ($preview['change_sets'] as $changeSet) {
      $id = $changeSet['id'];
      $form['change_sets'][$id] = [
        '#type' => 'fieldset',
        '#title' => $changeSet['message'] !== ''
          ? $changeSet['message']
          : $this->t('Change set @id', ['@id' => $id]),
      ];
      $form['change_sets'][$id]['description'] = [
        '#type' => 'item',
        '#markup' => $this->t('@source · @kind · @count evidence record(s) · @date', [
          '@source' => $changeSet['source'] ?: $this->t('Unknown source'),
          '@kind' => $changeSet['kind'],
          '@count' => $changeSet['evidence_count'],
          '@date' => date('Y-m-d H:i:s T', $changeSet['start']),
        ]),
      ];
      $form['change_sets'][$id]['include'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include this change set'),
        '#default_value' => 1,
      ];
      $form['change_sets'][$id]['section'] = [
        '#type' => 'select',
        '#title' => $this->t('Release section'),
        '#options' => $sectionOptions,
        '#default_value' => in_array($changeSet['suggested_section'], $this->sections(), TRUE)
          ? $changeSet['suggested_section']
          : 'other',
      ];
    }
    if ($preview['change_sets'] === []) {
      $form['empty'] = [
        '#markup' => $this->t('No change sets were found in this release window.'),
      ];
    }
    $form['confirm_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create an empty draft release'),
      '#description' => $this->t('Required when no change sets are selected.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['commit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create draft release'),
      '#button_type' => 'primary',
      '#submit' => ['::commitSubmit'],
    ];
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Change release window'),
      '#submit' => ['::backSubmit'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * Builds and stores a mutation-free preview.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    try {
      if ($form_state->getValue('mode') === 'since_last') {
        $preview = $this->releaseGenerator->previewSinceLast();
      }
      else {
        $start = $form_state->getValue('start_date');
        $end = $form_state->getValue('end_date');
        assert($start instanceof DrupalDateTime);
        assert($end instanceof DrupalDateTime);
        $preview = $this->releaseGenerator->previewRange(
          $start->getPhpDateTime()->setTime(0, 0, 0),
          $end->getPhpDateTime()->setTime(23, 59, 59),
        );
      }
      $form_state->set('preview', $preview->toArray())->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->generationError($exception);
    }
  }

  /**
   * Clears the preview without creating a release.
   */
  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('preview', NULL)->setRebuild();
  }

  /**
   * Revalidates the selected evidence and creates the draft.
   */
  public function commitSubmit(array &$form, FormStateInterface $form_state): void {
    $preview = $form_state->get('preview');
    $selection = [];
    foreach ($form_state->getValue('change_sets', []) as $changeSetId => $value) {
      if (!empty($value['include'])) {
        $selection[$changeSetId] = (string) $value['section'];
      }
    }
    $options = [];

    $title = trim((string) $form_state->getValue('title'));
    if ($title !== '') {
      $options['title'] = $title;
    }

    $version = trim((string) $form_state->getValue('version'));
    if ($version !== '') {
      $options['version'] = $version;
      $options['label_type'] = 'semantic_version';
    }

    try {
      $release = $this->releaseGenerator->generateReleaseFromSelection(
        new \DateTimeImmutable('@' . $preview['start']),
        new \DateTimeImmutable('@' . $preview['end']),
        $selection,
        $options,
        (bool) $form_state->getValue('confirm_empty'),
      );

      $this->messenger()->addStatus($this->t('Draft release "@title" has been created.', [
        '@title' => $release->getTitle(),
      ]));

      $form_state->setRedirectUrl($release->toUrl('edit-form'));
    }
    catch (\Throwable $exception) {
      $this->generationError($exception);
      $this->messenger()->addError($this->t('Preview the release window again and retry.'));
      $form_state->set('preview', NULL);
      $form_state->setRedirect('changelogify.generate_release');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * Returns stable release section identifiers.
   */
  private function sections(): array {
    return ['added', 'changed', 'fixed', 'removed', 'security', 'other'];
  }

  /**
   * Logs a generation failure without exposing internal details in the UI.
   */
  private function generationError(\Throwable $exception): void {
    $this->logger->error('Release generation failed: @message', [
      '@message' => $exception->getMessage(),
      'exception' => $exception,
    ]);
    $this->messenger()->addError($this->t('The release could not be generated. Check the logs for details.'));
  }

}
