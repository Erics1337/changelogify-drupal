<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
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
    protected DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ReleaseGeneratorInterface::class),
      $container->get('logger.factory')->get('changelogify'),
      $container->get('date.formatter'),
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
    if ($form_state->get('preview') !== NULL) {
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
      '#description' => $this->t('Optional. The exact generated title will be shown in the preview.'),
      '#maxlength' => 255,
    ];

    $form['options']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#description' => $this->t('Optional. Leave blank to use a date-based release label. Example: 1.2.0.'),
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

    if ($form_state->get('preview') !== NULL) {
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
      if ($selected === 0) {
        $form_state->setErrorByName('change_sets', $this->t('Select at least one change to create a draft release.'));
      }
    }
  }

  /**
   * Builds the change-set selection step.
   */
  private function buildPreviewForm(array $form, FormStateInterface $form_state): array {
    $preview = $form_state->get('preview');
    $form['#attached']['library'][] = 'changelogify/editor';
    $form['#attributes']['class'][] = 'changelogify-release-generator';
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Release window'),
      '#markup' => $this->t('@start through @end', [
        '@start' => $this->formatBoundary((int) $preview['start']),
        '@end' => $this->formatBoundary((int) $preview['end']),
      ]),
    ];
    $overlaps = $preview['coverage']['overlaps'] ?? [];
    $reused = $preview['coverage']['reused_change_sets'] ?? [];
    $gap = $preview['coverage']['gap_before'] ?? NULL;
    if ($overlaps !== [] || $reused !== [] || $gap !== NULL) {
      $form['coverage_summary'] = [
        '#type' => 'details',
        '#title' => $this->t('Coverage review: @overlaps overlapping release(s), @reused previously used change(s)@gap', [
          '@overlaps' => count($overlaps),
          '@reused' => count($reused),
          '@gap' => $gap === NULL ? '' : $this->t(', 1 coverage gap'),
        ]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['changelogify-coverage-summary']],
      ];
      if ($overlaps !== []) {
        $items = [];
        foreach ($overlaps as $overlap) {
          $link = Link::fromTextAndUrl(
            $overlap['title'],
            Url::fromRoute('entity.changelogify_release.canonical', [
              'changelogify_release' => $overlap['release_id'],
            ]),
          )->toRenderable();
          $items[] = [
            '#type' => 'container',
            'release' => $link,
            'detail' => [
              '#markup' => $this->t('@separator @status, @start through @end', [
                '@separator' => '—',
                '@status' => $overlap['status'],
                '@start' => $this->formatBoundary((int) $overlap['start']),
                '@end' => $this->formatBoundary((int) $overlap['end']),
              ]),
            ],
          ];
        }
        $form['coverage_summary']['overlaps'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('Overlapping releases'),
          '#items' => $items,
        ];
      }
      if ($gap !== NULL) {
        $form['coverage_summary']['gap'] = [
          '#type' => 'item',
          '#title' => $this->t('Coverage gap'),
          '#markup' => $this->t('@start through @end has no release coverage.', [
            '@start' => $this->formatBoundary((int) $gap['start']),
            '@end' => $this->formatBoundary((int) $gap['end']),
          ]),
        ];
      }
    }
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
      '#description' => $this->t('Optional. Leave blank to use “@title”.', [
        '@title' => $this->defaultTitle((int) $preview['end']),
      ]),
    ];
    $form['options']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#maxlength' => 50,
      '#default_value' => $form_state->getValue('version', ''),
      '#description' => $this->t('Optional. Leave blank to use a date-based release label instead of a version badge.'),
    ];
    $sectionOptions = array_combine(
      $this->sections(),
      array_map('ucfirst', $this->sections()),
    );
    $submitted = $form_state->getValue('change_sets', []);
    $form['candidate_filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['changelogify-candidate-filters']],
      'search' => [
        '#type' => 'search',
        '#title' => $this->t('Search changes'),
        '#attributes' => [
          'class' => ['changelogify-candidate-search'],
          'placeholder' => $this->t('Search change messages'),
        ],
      ],
      'source' => [
        '#type' => 'select',
        '#title' => $this->t('Filter by source'),
        '#empty_option' => $this->t('- All sources -'),
        '#options' => $this->sourceOptions($preview['change_sets']),
        '#attributes' => ['class' => ['changelogify-candidate-source-filter']],
      ],
    ];
    $form['change_set_groups'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['changelogify-change-set-groups']],
    ];
    $groups = [];
    foreach ($preview['change_sets'] as $changeSet) {
      $id = $changeSet['id'];
      $isReused = isset($reused[$id]);
      $groupKey = $isReused ? 'reused' : $this->sourceGroup($changeSet);
      $groups[$groupKey][] = $changeSet;
    }
    foreach ($groups as $groupKey => $changeSets) {
      $isReusedGroup = $groupKey === 'reused';
      $form['change_set_groups'][$groupKey] = [
        '#type' => 'details',
        '#title' => $isReusedGroup
          ? $this->t('Already included in another release (@count)', ['@count' => count($changeSets)])
          : $this->t('@source (@count)', [
            '@source' => $this->sourceLabel($groupKey),
            '@count' => count($changeSets),
          ]),
        '#open' => !$isReusedGroup,
        '#attributes' => [
          'class' => ['changelogify-change-set-group'],
          'data-changelogify-source-group' => $groupKey,
        ],
      ];
      $ids = array_column($changeSets, 'id');
      $form['change_set_groups'][$groupKey]['actions'] = [
        '#type' => 'actions',
        '#attributes' => ['class' => ['changelogify-group-actions']],
        'select' => [
          '#type' => 'submit',
          '#value' => $isReusedGroup ? $this->t('Include all again') : $this->t('Select all'),
          '#submit' => ['::groupSelectionSubmit'],
          '#limit_validation_errors' => [],
          '#candidate_ids' => $ids,
          '#candidate_value' => TRUE,
        ],
        'clear' => [
          '#type' => 'submit',
          '#value' => $this->t('Clear all'),
          '#submit' => ['::groupSelectionSubmit'],
          '#limit_validation_errors' => [],
          '#candidate_ids' => $ids,
          '#candidate_value' => FALSE,
        ],
      ];
      $form['change_set_groups'][$groupKey]['items'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['changelogify-change-set-list']],
      ];
      foreach ($changeSets as $changeSet) {
        $id = $changeSet['id'];
        $sourceKey = $this->sourceGroup($changeSet);
        $suggestedSection = in_array($changeSet['suggested_section'], $this->sections(), TRUE)
          ? $changeSet['suggested_section']
          : 'other';
        $row = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['changelogify-change-set-row'],
            'data-changelogify-source' => $sourceKey,
            'data-changelogify-search' => mb_strtolower($changeSet['message'] . ' ' . $changeSet['source'] . ' ' . $changeSet['kind']),
          ],
        ];
        $row['include'] = [
          '#type' => 'checkbox',
          '#title' => $isReusedGroup ? $this->t('Include again') : $this->t('Include'),
          '#default_value' => $submitted[$id]['include'] ?? !$isReusedGroup,
          '#parents' => ['change_sets', $id, 'include'],
        ];
        $row['summary'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['changelogify-change-set-summary']],
          'message' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => $changeSet['message'] !== '' ? $changeSet['message'] : $this->t('Untitled tracked change'),
          ],
          'metadata' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('@source · @kind · @count record(s) · @date', [
              '@source' => $changeSet['source'] ?: $this->t('Unknown source'),
              '@kind' => $changeSet['kind'],
              '@count' => $changeSet['evidence_count'],
              '@date' => $this->formatBoundary((int) $changeSet['start']),
            ]),
          ],
        ];
        if ($isReusedGroup) {
          $row['summary']['reuse'] = [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Already used in: @releases', [
              '@releases' => implode(', ', $reused[$id]),
            ]),
            '#attributes' => ['class' => ['changelogify-change-set-reuse']],
          ];
        }
        $row['section'] = [
          '#type' => 'select',
          '#title' => $this->t('Release section'),
          '#options' => $sectionOptions,
          '#default_value' => $submitted[$id]['section'] ?? $suggestedSection,
          '#parents' => ['change_sets', $id, 'section'],
        ];
        $form['change_set_groups'][$groupKey]['items'][$id] = $row;
      }
    }
    if ($preview['change_sets'] === []) {
      $form['empty'] = [
        '#markup' => $this->t('No change sets were found in this release window.'),
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['commit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create draft release'),
      '#button_type' => 'primary',
      '#submit' => ['::commitSubmit'],
      '#disabled' => $preview['change_sets'] === [],
      '#attributes' => ['class' => ['changelogify-create-draft']],
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
   * Selects or clears every candidate in one visible source group.
   */
  public function groupSelectionSubmit(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $values = $form_state->getValue('change_sets', []);
    foreach ($trigger['#candidate_ids'] ?? [] as $changeSetId) {
      $values[$changeSetId]['include'] = (bool) ($trigger['#candidate_value'] ?? FALSE);
    }
    $form_state->setValue('change_sets', $values)->setRebuild();
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
        FALSE,
        $this->selectionReusesEvidence($preview, $selection),
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
   * Returns source filter options present in the current preview.
   */
  private function sourceOptions(array $changeSets): array {
    $options = [];
    foreach ($changeSets as $changeSet) {
      $key = $this->sourceGroup($changeSet);
      $options[$key] = $this->sourceLabel($key);
    }
    asort($options);
    return $options;
  }

  /**
   * Maps technical event sources to a small editor-facing source taxonomy.
   */
  private function sourceGroup(array $changeSet): string {
    $value = strtolower((string) ($changeSet['source'] ?? '') . ' ' . (string) ($changeSet['kind'] ?? ''));
    return match (TRUE) {
      str_contains($value, 'config') => 'configuration',
      str_contains($value, 'module'), str_contains($value, 'theme'), str_contains($value, 'extension') => 'extensions',
      str_contains($value, 'user') => 'users',
      str_contains($value, 'content'), str_contains($value, 'entity') => 'content',
      default => 'other',
    };
  }

  /**
   * Returns a translated source-group label.
   */
  private function sourceLabel(string $key): string {
    return (string) match ($key) {
      'content' => $this->t('Content'),
      'configuration' => $this->t('Configuration'),
      'extensions' => $this->t('Extensions'),
      'users' => $this->t('Users'),
      default => $this->t('Other'),
    };
  }

  /**
   * Treats selecting a clearly marked reused row as explicit confirmation.
   */
  private function selectionReusesEvidence(array $preview, array $selection): bool {
    return array_intersect_key(
      $preview['coverage']['reused_change_sets'] ?? [],
      $selection,
    ) !== [];
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
   * Formats a release boundary without exposing the Unix-epoch sentinel.
   */
  private function formatBoundary(int $timestamp): string {
    if ($timestamp === 0) {
      return $this->t('Beginning of recorded history')->__toString();
    }
    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i:s T');
  }

  /**
   * Returns the exact fallback title for a preview end boundary.
   */
  private function defaultTitle(int $endTimestamp): string {
    return $this->t('Release - @date', [
      '@date' => $this->dateFormatter->format($endTimestamp, 'custom', 'F Y'),
    ])->__toString();
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
