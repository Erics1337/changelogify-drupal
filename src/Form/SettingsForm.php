<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

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

    $form['event_sources']['track_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track content changes'),
      '#description' => $this->t('Log events when content is created, updated, or deleted.'),
      '#config_target' => 'changelogify.settings:track_content',
      '#default_value' => $this->setting('track_content', TRUE),
    ];

    $form['event_sources']['track_unpublished_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track unpublished content'),
      '#description' => $this->t('Privacy warning: stores labels and paths for unpublished or access-controlled content in the internal event log, where they can be included in draft releases. Restrict administrative access before enabling.'),
      '#config_target' => 'changelogify.settings:track_unpublished_content',
      '#default_value' => $this->setting('track_unpublished_content', FALSE),
      '#states' => [
        'visible' => [
          ':input[name="track_content"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['event_sources']['track_modules'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track module changes'),
      '#description' => $this->t('Log events when modules are installed or uninstalled.'),
      '#config_target' => 'changelogify.settings:track_modules',
      '#default_value' => $this->setting('track_modules', TRUE),
    ];

    $form['event_sources']['track_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track user changes'),
      '#description' => $this->t('Privacy warning: stores usernames and old/new role assignments when users are created or roles change. Restrict administrative access and confirm a lawful retention policy before enabling.'),
      '#config_target' => 'changelogify.settings:track_users',
      '#default_value' => $this->setting('track_users', FALSE),
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
    $this->routeBuilder->rebuild();
  }

  /**
   * Gets a boolean setting with an existing-site fallback.
   */
  private function setting(string $key, bool $default): bool {
    $value = $this->config('changelogify.settings')->get($key);
    return $value === NULL ? $default : (bool) $value;
  }

}
