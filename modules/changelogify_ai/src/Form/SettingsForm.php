<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\changelogify_ai\AiOperationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Safely configures consent, model selection, and payload policy.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(ConfigFactoryInterface $configFactory, TypedConfigManagerInterface $typedConfigManager, protected AiOperationManager $operations) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get(AiOperationManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['changelogify_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'changelogify_ai_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('changelogify_ai.settings');
    $form['consent_external_processing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow selected evidence to be processed by the configured AI provider'),
      '#description' => $this->t('Provider credentials remain managed by Drupal AI and its provider module.'),
      '#default_value' => $config->get('consent_external_processing'),
    ];
    $form['payload_preview'] = [
      '#type' => 'item',
      '#title' => $this->t('Payload preview'),
      '#markup' => Link::fromTextAndUrl(
        $this->t('Preview redacted outbound payload'),
        Url::fromRoute('changelogify_ai.payload_preview'),
      )->toString(),
    ];
    $ready = $this->operations->isAvailable();
    $selection = $this->operations->selectedProviderModel();
    $form['provider_status'] = [
      '#type' => 'item',
      '#title' => $this->t('External processing status'),
      '#plain_text' => $ready
        ? $this->t('Ready: consent is granted and the selected Drupal AI chat provider and model are available.')
        : $this->t('Not ready: grant consent and configure an available Drupal AI chat provider and model.'),
      '#weight' => -10,
    ];
    $form['provider_identity'] = [
      '#type' => 'item',
      '#title' => $this->t('Selected provider and model'),
      '#plain_text' => $selection === NULL
        ? $this->t('No provider/model is currently selected.')
        : $this->t('Provider: @provider; model: @model.', [
          '@provider' => $selection['provider'],
          '@model' => $selection['model'],
        ]),
      '#weight' => -9,
    ];
    $form['provider_link'] = [
      '#type' => 'item',
      '#markup' => Link::fromTextAndUrl($this->t('Configure Drupal AI providers'), Url::fromRoute('ai.admin_providers'))->toString(),
      '#weight' => -8,
    ];
    $form['provider'] = [
      '#type' => 'ai_provider_configuration',
      '#title' => $this->t('Drupal AI chat provider and model'),
      '#description' => $this->t('Credentials stay in the provider and Key integration.'),
      '#operation_type' => 'chat',
      '#advanced_config' => TRUE,
      '#default_provider_allowed' => TRUE,
      '#default_value' => $config->get('provider'),
    ];
    $form['policy'] = [
      '#type' => 'details',
      '#title' => $this->t('Outbound payload policy'),
      '#open' => TRUE,
    ];
    foreach ($this->policyLabels() as $key => $label) {
      $form['policy'][$key] = [
        '#type' => 'select',
        '#title' => $this->t('@label treatment', ['@label' => $label]),
        '#options' => ['redact' => $this->t('Redact'), 'include' => $this->t('Include')],
        '#default_value' => $config->get("policy.$key") ?? 'redact',
      ];
    }
    $allowlistedValues = $config->get('policy.allowlisted_values') ?: [];
    $form['policy']['allowlisted_values'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed structured field values'),
      '#description' => $this->t('Enter one field name per line. All other source field values are excluded.'),
      '#default_value' => implode("\n", array_values($allowlistedValues)),
      '#maxlength' => 1000,
    ];
    $form['policy']['allow_manual_humanization'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow manual release items to be humanized'),
      '#description' => $this->t('Manual items have no automatic evidence and are clearly marked as such during review.'),
      '#default_value' => $config->get('policy.allow_manual_humanization'),
    ];
    $form['organization_guidance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Organization editorial guidance'),
      '#maxlength' => 1000,
      '#default_value' => $config->get('organization_guidance'),
      '#description' => $this->t('This cannot override mandatory evidence and safety rules.'),
    ];
    $form['output_language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Output language'),
      '#description' => $this->t('Use an IETF language tag, for example en or fr-CA.'),
      '#maxlength' => 35,
      '#default_value' => $config->get('output_language') ?: 'en',
    ];
    foreach ([
      'history_retention_days' => [$this->t('History retention (days)'), 1, 3650],
      'queue_threshold' => [$this->t('Queue complete drafts at this many change sets'), 1, 5000],
    ] as $key => [$label, $min, $max]) {
      $form[$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#min' => $min,
        '#max' => $max,
        '#default_value' => $config->get($key),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $language = trim((string) $form_state->getValue('output_language'));
    if (!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language)) {
      $form_state->setErrorByName('output_language', $this->t('Enter a valid IETF language tag, for example en or fr-CA.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $provider = $form_state->getValue('provider') ?: [];
    $this->configFactory->getEditable('changelogify_ai.settings')
      ->set('consent_external_processing', (bool) $form_state->getValue('consent_external_processing'))
      ->set('provider', [
        'use_default' => (bool) ($provider['use_default'] ?? TRUE),
        'provider' => trim((string) ($provider['provider'] ?? '')),
        'model' => trim((string) ($provider['model'] ?? '')),
        'config' => is_array($provider['config'] ?? NULL) ? $provider['config'] : [],
      ])
      ->set('policy', $this->policyValues($form_state->getValue('policy')))
      ->set('organization_guidance', trim((string) $form_state->getValue('organization_guidance')))
      ->set('output_language', trim((string) $form_state->getValue('output_language')) ?: 'en')
      ->set('history_retention_days', (int) $form_state->getValue('history_retention_days'))
      ->set('queue_threshold', (int) $form_state->getValue('queue_threshold'))
      ->save();
  }

  /**
   * Returns the selectable outbound-payload fields and labels.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Keys mapped to human-readable labels.
   */
  private function policyLabels(): array {
    return [
      'usernames' => $this->t('Usernames'),
      'actor_ids' => $this->t('Actor IDs'),
      'entity_ids' => $this->t('Entity IDs'),
      'paths' => $this->t('Paths'),
      'unpublished_labels' => $this->t('Unpublished labels'),
      'bundle_labels' => $this->t('Bundle labels'),
      'changed_field_names' => $this->t('Changed field names'),
    ];
  }

  /**
   * Normalizes human-entered allowlist lines into stable configuration.
   */
  private function policyValues(array $policy): array {
    $lines = preg_split('/\R/', (string) ($policy['allowlisted_values'] ?? '')) ?: [];
    $policy['allowlisted_values'] = array_values(array_unique(array_filter(array_map('trim', $lines))));
    return $policy;
  }

}
