<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Changelogify.
 */
class SettingsForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'changelogify_settings_form';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return ['changelogify.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config('changelogify.settings');

        $form['general'] = [
            '#type' => 'details',
            '#title' => $this->t('General Settings'),
            '#open' => TRUE,
        ];

        $form['general']['changelog_path'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Changelog Path'),
            '#description' => $this->t('The URL path for the public changelog.'),
            '#default_value' => $config->get('changelog_path') ?: '/changelog',
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
            '#default_value' => $config->get('track_content') ?? TRUE,
        ];

        $form['event_sources']['track_modules'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Track module changes'),
            '#description' => $this->t('Log events when modules are installed or uninstalled.'),
            '#default_value' => $config->get('track_modules') ?? TRUE,
        ];

        $form['event_sources']['track_users'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Track user changes'),
            '#description' => $this->t('Log events when users are created or roles change.'),
            '#default_value' => $config->get('track_users') ?? TRUE,
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
            '#default_value' => $config->get('event_retention_days') ?? 365,
            '#min' => 0,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->config('changelogify.settings')
            ->set('changelog_path', $form_state->getValue('changelog_path'))
            ->set('track_content', $form_state->getValue('track_content'))
            ->set('track_modules', $form_state->getValue('track_modules'))
            ->set('track_users', $form_state->getValue('track_users'))
            ->set('event_retention_days', $form_state->getValue('event_retention_days'))
            ->save();

        parent::submitForm($form, $form_state);
    }

}
