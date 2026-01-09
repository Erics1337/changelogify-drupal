<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Changelogify.
 */
class SettingsForm extends ConfigFormBase {

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
    ];

    $form['event_sources']['track_modules'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track module changes'),
      '#description' => $this->t('Log events when modules are installed or uninstalled.'),
      '#config_target' => 'changelogify.settings:track_modules',
    ];

    $form['event_sources']['track_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track user changes'),
      '#description' => $this->t('Log events when users are created or roles change.'),
      '#config_target' => 'changelogify.settings:track_users',
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
    ];

    return parent::buildForm($form, $form_state);
  }

}
