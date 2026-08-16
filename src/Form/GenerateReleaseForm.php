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
      '#value' => $this->t('Generate Release'),
      '#button_type' => 'primary',
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
    if ($form_state->getValue('mode') === 'custom') {
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
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
      if ($form_state->getValue('mode') === 'since_last') {
        $release = $this->releaseGenerator->generateReleaseSinceLast($options);
      }
      else {
        $start = $form_state->getValue('start_date');
        $end = $form_state->getValue('end_date');
        assert($start instanceof DrupalDateTime);
        assert($end instanceof DrupalDateTime);
        $start_date = $start->getPhpDateTime()->setTime(0, 0, 0);
        $end_date = $end->getPhpDateTime()->setTime(23, 59, 59);
        $release = $this->releaseGenerator->generateReleaseFromRange($start_date, $end_date, $options);
      }

      $this->messenger()->addStatus($this->t('Draft release "@title" has been created.', [
        '@title' => $release->getTitle(),
      ]));

      $form_state->setRedirectUrl($release->toUrl('edit-form'));
    }
    catch (\Throwable $exception) {
      $this->logger->error('Release generation failed: @message', [
        '@message' => $exception->getMessage(),
        'exception' => $exception,
      ]);
      $this->messenger()->addError($this->t('The release could not be generated. Check the logs for details.'));
    }
  }

}
