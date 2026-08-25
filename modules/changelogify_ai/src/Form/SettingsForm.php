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
    $form['processing_consent'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#title' => $this->t('Allow AI processing of release evidence'),
      '#description' => $this->t('Changelogify sends only the filtered evidence selected for an AI action to the configured Drupal AI provider. Cloud providers may process that data outside this Drupal site; local providers process it according to their own configuration.'),
      '#open' => !$config->get('consent_external_processing'),
      '#weight' => -7,
    ];
    $form['processing_consent']['payload_preview'] = [
      '#type' => 'item',
      '#title' => $this->t('Review before allowing processing'),
      '#markup' => Link::fromTextAndUrl(
        $this->t('Preview the filtered information shared with the AI provider'),
        Url::fromRoute('changelogify_ai.payload_preview'),
      )->toString(),
    ];
    $form['processing_consent']['consent_external_processing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow the configured AI provider to process selected release evidence'),
      '#description' => $this->t('This separate administrator approval is required even after a provider is configured. Changelogify never receives provider credentials; Drupal AI and its provider module manage them.'),
      '#default_value' => $config->get('consent_external_processing'),
    ];
    $form['processing_consent']['consequences'] = [
      '#type' => 'item',
      '#markup' => $this->t('Disabling this approval blocks new AI requests. It does not delete previously accepted release revisions or the privacy-bounded AI operation history.'),
    ];
    $ready = $this->operations->isAvailable();
    $selection = $this->operations->selectedProviderModel();
    $providerConfig = $config->get('provider') ?: [];
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
    $form['provider_mode'] = [
      '#type' => 'item',
      '#title' => $this->t('Selection mode'),
      '#plain_text' => !empty($providerConfig['use_default'])
        ? $this->t('Use the site-wide default Drupal AI chat provider and model.')
        : $this->t('Use the provider and model selected specifically for Changelogify.'),
      '#weight' => -8,
    ];
    if ($selection !== NULL && $this->isDevelopmentProvider($selection['provider'], $selection['model'])) {
      $form['provider_development_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#weight' => -8,
        'message' => [
          '#plain_text' => $this->t('Development-only provider selected. Deterministic, fake, or test providers verify integration behavior but do not produce production-quality humanized writing.'),
        ],
      ];
    }
    $form['provider_link'] = [
      '#type' => 'item',
      '#title' => $this->t('Provider setup and credentials'),
      '#markup' => Link::fromTextAndUrl($this->t('Configure installed Drupal AI providers'), Url::fromRoute('ai.admin_providers'))->toString(),
      '#description' => $this->t('A provider is the service or local runtime that performs generation. The model is the specific chat model it runs. Provider modules and credentials are managed by Drupal AI and Key, not Changelogify.'),
      '#weight' => -8,
    ];
    $form['provider'] = [
      '#type' => 'ai_provider_configuration',
      '#title' => $this->t('Provider and model for release drafting'),
      '#description' => $this->t('Choose the service and chat model used for future Changelogify AI operations. Changing this selection does not rewrite historical operation records.'),
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
    $consent = (bool) $form_state->getValue([
      'processing_consent',
      'consent_external_processing',
    ]);
    $provider = $form_state->getValue('provider') ?: [];
    $policy = $form_state->getValue('policy') ?: [];
    $guidance = trim((string) $form_state->getValue('organization_guidance'));
    $language = trim((string) $form_state->getValue('output_language')) ?: 'en';
    $historyRetention = (int) $form_state->getValue('history_retention_days');
    $queueThreshold = (int) $form_state->getValue('queue_threshold');
    parent::submitForm($form, $form_state);
    $this->configFactory->getEditable('changelogify_ai.settings')
      ->set('consent_external_processing', $consent)
      ->set('provider', [
        'use_default' => (bool) ($provider['use_default'] ?? TRUE),
        'provider' => trim((string) ($provider['provider'] ?? '')),
        'model' => trim((string) ($provider['model'] ?? '')),
        'config' => is_array($provider['config'] ?? NULL) ? $provider['config'] : [],
      ])
      ->set('policy', $this->policyValues($policy))
      ->set('organization_guidance', $guidance)
      ->set('output_language', $language)
      ->set('history_retention_days', $historyRetention)
      ->set('queue_threshold', $queueThreshold)
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
   * Identifies provider selections that must not look production-ready.
   */
  private function isDevelopmentProvider(string $provider, string $model): bool {
    return preg_match('/(?:test|fake|deterministic)/i', $provider . ' ' . $model) === 1;
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
