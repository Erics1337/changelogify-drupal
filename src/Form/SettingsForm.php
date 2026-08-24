<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\changelogify\EventSource\EventSourceRecorderInterface;
use Drupal\changelogify\EventSource\EventSourceRegistryInterface;
use Drupal\changelogify\EventSource\ContentCapturePolicyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for Changelogify.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected RouteBuilderInterface $routeBuilder,
    protected RouteProviderInterface $routeProvider,
    protected EventSourceRegistryInterface $eventSourceRegistry,
    protected EventSourceRecorderInterface $eventSourceRecorder,
    protected ContentCapturePolicyInterface $contentCapturePolicy,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
          $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('router.builder'),
      $container->get('router.route_provider'),
      $container->get(EventSourceRegistryInterface::class),
      $container->get(EventSourceRecorderInterface::class),
      $container->get(ContentCapturePolicyInterface::class),
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'changelogify_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['changelogify.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['changelog_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Changelog Path'),
      '#description' => $this->t('The URL path for the public changelog.'),
      '#config_target' => 'changelogify.settings:changelog_path',
      '#default_value' => $this->config('changelogify.settings')->get('changelog_path') ?: '/changelog',
      '#required' => TRUE,
    ];

    $form['event_sources'] = [
      '#type' => 'details',
      '#title' => $this->t('Event Sources'),
      '#open' => TRUE,
    ];

    foreach ($this->eventSourceRegistry->getSources() as $source) {
      $elementKey = $source->getLegacyEnabledSetting() ?? 'event_source_' . $source->getId();
      $form['event_sources'][$elementKey] = [
        '#type' => 'checkbox',
        '#title' => $source->getLabel(),
        '#description' => $source->getPrivacyDescription(),
        '#config_target' => sprintf('changelogify.settings:event_sources.%s.enabled', $source->getId()),
        '#default_value' => $this->eventSourceRecorder->isEnabled($source),
      ];
    }

    $form['event_sources']['track_unpublished_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track unpublished content'),
      '#description' => $this->t('Privacy warning: stores labels and paths for unpublished or access-controlled content in the internal event log, where they can be included in draft releases. Restrict administrative access before enabling.'),
      '#config_target' => 'changelogify.settings:track_unpublished_content',
      '#default_value' => (bool) ($this->config('changelogify.settings')
        ->get('track_unpublished_content') ?? FALSE),
      '#states' => [
        'visible' => [
          ':input[name="track_content"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['content_capture'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#title' => $this->t('Content capture policy'),
      '#description' => $this->t('Newly discovered entity types and bundles are disabled until explicitly selected.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [':input[name="track_content"]' => ['checked' => TRUE]],
      ],
    ];
    foreach ($this->contentCapturePolicy->getEligibleEntityTypes() as $entityTypeId => $info) {
      $form['content_capture'][$entityTypeId] = [
        '#type' => 'details',
        '#title' => $info['label'],
        '#description' => $info['privacy_sensitive']
          ? $this->t('Privacy warning: this entity type may contain personal or access-controlled information.')
          : $this->t('Choose whether this entity type and its bundles may be recorded.'),
      ];
      $form['content_capture'][$entityTypeId]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable @type capture', ['@type' => $info['label']]),
        '#config_target' => "changelogify.settings:content_capture.entity_types.$entityTypeId.enabled",
        '#default_value' => $this->contentCapturePolicy->isEntityTypeEnabled($entityTypeId),
      ];
      $form['content_capture'][$entityTypeId]['bundles'] = [
        '#type' => 'container',
      ];
      foreach ($info['bundles'] as $bundleId => $bundleLabel) {
        $form['content_capture'][$entityTypeId]['bundles'][$bundleId] = [
          '#type' => 'checkbox',
          '#title' => $bundleLabel,
          '#config_target' => "changelogify.settings:content_capture.entity_types.$entityTypeId.bundles.$bundleId",
          '#default_value' => $this->contentCapturePolicy->isBundleEnabled($entityTypeId, $bundleId),
          '#states' => [
            'visible' => [":input[name=\"content_capture[$entityTypeId][enabled]\"]" => ['checked' => TRUE]],
          ],
        ];
      }
    }

    $form['config_import'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#title' => $this->t('Configuration import capture'),
      '#description' => $this->t('Configuration values and exported YAML are never stored.'),
      '#open' => FALSE,
    ];
    $form['config_import']['include_sensitive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include names of sensitive configuration objects'),
      '#description' => $this->t('Includes technical names for roles, permissions, text formats, and extension configuration. Values are still excluded.'),
      '#config_target' => 'changelogify.settings:config_import.include_sensitive',
      '#default_value' => (bool) ($this->config('changelogify.settings')
        ->get('config_import.include_sensitive') ?? FALSE),
    ];
    $form['config_import']['excluded_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded configuration name patterns'),
      '#description' => $this->t('Enter one shell-style pattern per line, such as webform.*.'),
      '#default_value' => implode("\n", $this->config('changelogify.settings')
        ->get('config_import.excluded_patterns') ?? []),
    ];

    $form['retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Event Retention'),
      '#open' => TRUE,
    ];

    $form['retention']['event_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep events for (days)'),
      '#description' => $this->t('Events older than this will be deleted during cron. Set to 0 to keep forever.'),
      '#config_target' => 'changelogify.settings:event_retention_days',
      '#min' => 0,
      '#default_value' => $this->config('changelogify.settings')->get('event_retention_days') ?? 90,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $path = '/' . trim((string) $form_state->getValue('changelog_path'), '/');
    if (!preg_match('@^/[a-z0-9][a-z0-9/_-]*$@', $path)) {
      $form_state->setErrorByName('changelog_path', $this->t('Use a path such as /changelog containing lowercase letters, numbers, slashes, underscores, or hyphens.'));
      return;
    }

    $existingRoutes = $this->routeProvider->getRoutesByPattern($path)->all();
    unset($existingRoutes['changelogify.changelog']);
    if ($existingRoutes !== []) {
      $form_state->setErrorByName('changelog_path', $this->t('That path is already used by another Drupal route.'));
      return;
    }

    $form_state->setValue('changelog_path', $path);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $patterns = preg_split('/\R/', (string) $form_state->getValue([
      'config_import',
      'excluded_patterns',
    ])) ?: [];
    $patterns = array_values(array_filter(array_map('trim', $patterns)));
    $this->configFactory()->getEditable('changelogify.settings')
      ->set('config_import.excluded_patterns', $patterns)
      ->save();
    $this->routeBuilder->rebuild();
  }

}
