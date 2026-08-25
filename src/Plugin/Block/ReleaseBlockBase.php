<?php

declare(strict_types=1);

namespace Drupal\changelogify\Plugin\Block;

use Drupal\changelogify\PublicReleaseBuilder;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared configurable presentation for public-release blocks.
 */
abstract class ReleaseBlockBase extends BlockBase implements ContainerFactoryPluginInterface {

  protected const SECTIONS = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];

  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    protected PublicReleaseBuilder $releaseBuilder,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(PublicReleaseBuilder::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'item_count' => 5,
      'show_date' => TRUE,
      'show_version' => TRUE,
      'sections' => array_fill_keys(self::SECTIONS, TRUE),
      'show_changelog_link' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['item_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of releases'),
      '#default_value' => $this->configuration['item_count'],
      '#min' => 1,
      '#max' => 20,
      '#access' => $this->supportsMultipleReleases(),
    ];
    $form['show_date'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show release date'),
      '#default_value' => $this->configuration['show_date'],
    ];
    $form['show_version'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show version when available'),
      '#default_value' => $this->configuration['show_version'],
    ];
    $form['sections'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Included release sections'),
      '#options' => [
        'added' => $this->t('Added'),
        'changed' => $this->t('Changed'),
        'fixed' => $this->t('Fixed'),
        'removed' => $this->t('Removed'),
        'security' => $this->t('Security'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => array_keys(array_filter($this->configuration['sections'])),
    ];
    $form['show_changelog_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show a link to the full changelog'),
      '#default_value' => $this->configuration['show_changelog_link'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    if ($this->supportsMultipleReleases()) {
      $count = filter_var($form_state->getValue('item_count'), FILTER_VALIDATE_INT);
      if ($count === FALSE || $count < 1 || $count > 20) {
        $form_state->setErrorByName('item_count', $this->t('Enter a number of releases from 1 through 20.'));
      }
    }
    if (array_filter($form_state->getValue('sections', [])) === []) {
      $form_state->setErrorByName('sections', $this->t('Select at least one release section.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['item_count'] = $this->supportsMultipleReleases()
      ? min(20, max(1, (int) $form_state->getValue('item_count')))
      : 1;
    $this->configuration['show_date'] = (bool) $form_state->getValue('show_date');
    $this->configuration['show_version'] = (bool) $form_state->getValue('show_version');
    $this->configuration['sections'] = array_map(
      static fn (mixed $value): bool => (bool) $value,
      $form_state->getValue('sections'),
    );
    $this->configuration['show_changelog_link'] = (bool) $form_state->getValue('show_changelog_link');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $releases = $this->releaseBuilder->load($this->itemLimit());
    $includedSections = array_keys(array_filter($this->configuration['sections']));
    $items = [];
    foreach ($releases as $release) {
      $items[] = $this->releaseBuilder->build($release, $includedSections);
    }
    $build = [
      '#theme' => 'changelogify_release_block',
      '#releases' => $items,
      '#show_date' => (bool) $this->configuration['show_date'],
      '#show_version' => (bool) $this->configuration['show_version'],
      '#changelog_url' => $this->configuration['show_changelog_link']
        ? $this->releaseBuilder->changelogUrl()->toString()
        : NULL,
      '#attached' => ['library' => ['changelogify/public']],
    ];
    $metadata = (new CacheableMetadata())
      ->addCacheTags(['changelogify_release_list', 'config:changelogify.settings'])
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
      ]);
    foreach ($releases as $release) {
      $metadata->addCacheableDependency($release);
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Returns the validated number of releases to load.
   */
  protected function itemLimit(): int {
    return $this->supportsMultipleReleases()
      ? min(20, max(1, (int) $this->configuration['item_count']))
      : 1;
  }

  /**
   * Whether this block exposes the item-count setting.
   */
  abstract protected function supportsMultipleReleases(): bool;

}
