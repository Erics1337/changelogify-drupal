<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\changelogify\EventManagerInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests editor-facing Changelogify AI integration states.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
#[RunTestsInSeparateProcesses]
final class ChangelogifyAiFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'changelogify', 'ai', 'changelogify_ai'];

  /**
   * Tests that AI configuration is a standard dashboard tab.
   */
  public function testDashboardAiTab(): void {
    $this->drupalPlaceBlock('local_tasks_block');
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Make release notes shine with AI');
    $this->assertSession()->linkExists('AI drafting');
    $this->assertSession()->linkByHrefExists('/admin/config/development/changelogify/ai');
  }

  /**
   * Tests known missing prerequisites disable generation with a direct action.
   */
  public function testNotReadyGenerationAction(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->container->get(EventManagerInterface::class)->logEvent([
      'event_type' => 'content_updated',
      'source' => 'test',
      'message' => 'AI readiness evidence',
      'section_hint' => 'changed',
    ]);

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm(['mode' => 'since_last'], 'Preview changes');

    $this->assertSession()->pageTextContains('permission to process selected release evidence has not been granted');
    $this->assertSession()->linkExists('Configure Changelogify AI');
    $this->assertSession()->buttonExists('Create AI draft release');
    $this->assertSession()->elementAttributeContains(
      'css',
      'input[value="Create AI draft release"]',
      'disabled',
      'disabled',
    );
  }

  /**
   * Tests explicit consent copy, preview access, and the request gate.
   */
  public function testProcessingConsentExplanationAndControl(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('only the filtered evidence selected for an AI action');
    $this->assertSession()->pageTextContains('Cloud providers may process that data outside this Drupal site');
    $this->assertSession()->pageTextContains('Changelogify never receives provider credentials');
    $this->assertSession()->pageTextContains('does not delete previously accepted release revisions');
    $this->assertSession()->linkExists('Preview the filtered information shared with the AI provider');
    $this->assertSession()->checkboxNotChecked('processing_consent[consent_external_processing]');

    $this->submitForm([
      'processing_consent[consent_external_processing]' => TRUE,
    ], 'Save configuration');
    self::assertTrue((bool) $this->config('changelogify_ai.settings')
      ->get('consent_external_processing'));

    $this->submitForm([
      'processing_consent[consent_external_processing]' => FALSE,
    ], 'Save configuration');
    self::assertFalse((bool) $this->config('changelogify_ai.settings')
      ->get('consent_external_processing'));
  }

  /**
   * Tests provider/model explanations, selection modes, and development labels.
   */
  public function testProviderModelGuidanceAndDevelopmentWarning(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('A provider is the service or local runtime that performs generation. The model is the specific chat model it runs.');
    $this->assertSession()->pageTextContains('Use the site-wide default Drupal AI chat provider and model.');
    $this->assertSession()->pageTextContains('Provider modules and credentials are managed by Drupal AI and Key, not Changelogify.');
    $this->assertSession()->pageTextContains('Changing this selection does not rewrite historical operation records.');

    $this->config('changelogify_ai.settings')
      ->set('provider', [
        'use_default' => FALSE,
        'provider' => 'changelogify_ai_test_provider',
        'model' => 'deterministic_json',
        'config' => [],
      ])
      ->save();

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('Use the provider and model selected specifically for Changelogify.');
    $this->assertSession()->pageTextContains('Development-only provider selected.');
    $this->assertSession()->pageTextContains('do not produce production-quality humanized writing');
  }

  /**
   * Tests deterministic privacy presets and understandable custom controls.
   */
  public function testPrivacyPresetsAndCustomPolicy(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('Information shared with the AI provider');
    $this->assertSession()->pageTextContains('Recommended — minimum necessary');
    $this->assertSession()->pageTextContains('People — account names');
    $this->assertSession()->pageTextContains('Example: “editor_jane”');
    $this->assertSession()->pageTextContains('Field names may be shared, but their values remain excluded unless explicitly approved below.');

    $this->submitForm([
      'policy[preset]' => 'more_context',
    ], 'Save configuration');
    $policy = $this->config('changelogify_ai.settings')->get('policy');
    self::assertSame('more_context', $policy['preset']);
    self::assertSame('redact', $policy['usernames']);
    self::assertSame('redact', $policy['unpublished_labels']);
    self::assertSame('include', $policy['entity_ids']);
    self::assertSame('include', $policy['paths']);
    $this->assertSession()->pageTextContains('This policy includes information that may identify people, individual content, unpublished content, or private site locations.');

    $this->submitForm([
      'policy[preset]' => 'custom',
      'policy[custom_controls][usernames]' => 'include',
      'policy[custom_controls][actor_ids]' => 'redact',
      'policy[custom_controls][entity_ids]' => 'redact',
      'policy[custom_controls][paths]' => 'redact',
      'policy[custom_controls][unpublished_labels]' => 'include',
      'policy[custom_controls][bundle_labels]' => 'include',
      'policy[custom_controls][changed_field_names]' => 'include',
      'policy[custom_controls][allowlisted_values]' => "field_release_category\nfield_release_category\n",
      'policy[custom_controls][allow_manual_humanization]' => TRUE,
    ], 'Save configuration');
    $policy = $this->config('changelogify_ai.settings')->get('policy');
    self::assertSame('custom', $policy['preset']);
    self::assertSame('include', $policy['usernames']);
    self::assertSame('include', $policy['unpublished_labels']);
    self::assertSame('redact', $policy['entity_ids']);
    self::assertSame(['field_release_category'], $policy['allowlisted_values']);
    self::assertTrue($policy['allow_manual_humanization']);
    $this->assertSession()->pageTextContains('Shared categories: People — account names, Names of unpublished content');
  }

  /**
   * Tests eligibility controls and the exact no-network evidence preview.
   */
  public function testEvidenceEligibilityAndPayloadPreview(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('Evidence eligible for AI summaries');
    $this->assertSession()->pageTextContains('Privacy controls below independently determine which fields');
    $this->assertSession()->checkboxChecked('eligibility[categories][content]');
    $this->assertSession()->checkboxChecked('eligibility[categories][custom]');

    $this->submitForm([
      'eligibility[categories][content]' => FALSE,
      'eligibility[categories][extensions]' => FALSE,
      'eligibility[categories][users]' => FALSE,
      'eligibility[categories][configuration]' => FALSE,
      'eligibility[categories][custom]' => FALSE,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Select at least one event category eligible for AI summaries.');

    $this->submitForm([
      'eligibility[categories][content]' => 'content',
      'eligibility[categories][extensions]' => FALSE,
      'eligibility[categories][users]' => FALSE,
      'eligibility[categories][configuration]' => 'configuration',
      'eligibility[categories][custom]' => FALSE,
    ], 'Save configuration');
    self::assertSame(
      ['configuration', 'content'],
      $this->config('changelogify_ai.settings')->get('eligibility.categories'),
    );

    $this->container->get(EventManagerInterface::class)->logEvent([
      'event_type' => 'node_unpublished',
      'source' => 'content_entity',
      'message' => 'Unpublished page "Secret roadmap" at /private/roadmap.',
      'bundle' => 'page',
      'section_hint' => 'removed',
      'correlation_id' => 'operation-secret',
      'metadata' => [
        'action' => 'unpublished',
        'label' => 'Secret roadmap',
        'path' => '/private/roadmap',
        'changed_fields' => ['title', 'status'],
        'safe_field' => 'Approved context',
        'api_token' => 'must-never-leave',
      ],
    ]);
    $policy = $this->config('changelogify_ai.settings')->get('policy');
    $policy['allowlisted_values'] = ['safe_field', 'api_token'];
    $this->config('changelogify_ai.settings')->set('policy', $policy)->save();

    $this->drupalGet('/admin/config/development/changelogify/ai/payload-preview');
    $this->assertSession()->pageTextContains('exact eligible, policy-filtered data');
    $this->assertSession()->pageTextContains('No provider request was made.');
    $this->assertSession()->pageTextContains('node_unpublished');
    $this->assertSession()->pageTextContains('content_entity');
    $this->assertSession()->pageTextContains('Approved context');
    $this->assertSession()->pageTextContains('[redacted]');
    $this->assertSession()->pageTextNotContains('Secret roadmap');
    $this->assertSession()->pageTextNotContains('/private/roadmap');
    $this->assertSession()->pageTextNotContains('must-never-leave');

    $this->config('changelogify_ai.settings')
      ->set('eligibility.categories', ['users'])
      ->save();
    $this->drupalGet('/admin/config/development/changelogify/ai/payload-preview');
    $this->assertSession()->pageTextNotContains('node_unpublished');
    $this->assertSession()->pageTextNotContains('Approved context');
  }

  /**
   * Tests task-oriented settings sections and non-destructive verification.
   */
  public function testSettingsInformationArchitectureAndVerification(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify ai',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('optional editorial assistant');
    $this->assertSession()->pageTextContains('never publishes a release or changes source content on its own');
    $this->assertSession()->elementExists('css', 'table.changelogify-ai-readiness');
    $this->assertSession()->pageTextContains('Setup status');
    $this->assertSession()->pageTextContains('AI provider');
    $this->assertSession()->pageTextContains('Chat model');
    $this->assertSession()->pageTextContains('Permission to process selected evidence');
    $this->assertSession()->pageTextContains('Data and privacy');
    $this->assertSession()->pageTextContains('Editorial output');
    $this->assertSession()->pageTextContains('Advanced operations');
    $this->assertSession()->buttonExists('Save and verify configuration');
    $this->assertSession()->linkExists('Configure installed providers');

    $this->submitForm([
      'output_language' => 'not a language tag!',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Enter a valid IETF language tag');
    $this->assertSession()->pageTextContains('Data and privacy');

    $this->submitForm(['output_language' => 'en'], 'Save and verify configuration');
    $this->assertSession()->pageTextContains('Configuration was saved but is not ready: AI drafting is off because permission to process selected release evidence has not been granted.');
    $this->assertSession()->pageTextContains('No provider request was made.');
  }

}
