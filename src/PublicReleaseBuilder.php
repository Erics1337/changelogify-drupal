<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Loads and formats accessible public releases for reusable presentation.
 */
final class PublicReleaseBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Loads a bounded, newest-first list of accessible published releases.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyReleaseInterface[]
   *   Accessible published releases.
   */
  public function load(int $limit): array {
    $limit = min(20, max(1, $limit));
    return $this->loadPage($limit);
  }

  /**
   * Loads one bounded page of accessible published releases.
   *
   * A maximum of 21 supports one-item lookahead for the public API while its
   * documented page size remains capped at 20.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyReleaseInterface[]
   *   Accessible published releases.
   */
  public function loadPage(int $limit, int $offset = 0): array {
    $limit = min(21, max(1, $limit));
    $offset = max(0, $offset);
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE)
      ->sort('release_date', 'DESC')
      ->sort('id', 'DESC')
      ->range($offset, $limit)
      ->execute();
    $releases = [];
    foreach ($storage->loadMultiple($ids) as $release) {
      if (!$release instanceof ChangelogifyReleaseInterface) {
        continue;
      }
      $translation = $this->translateForPublic($release);
      if ($translation !== NULL && $translation->access('view', $this->currentUser)) {
        $releases[] = $translation;
      }
    }
    return $releases;
  }

  /**
   * Builds one safe public presentation record.
   */
  public function build(ChangelogifyReleaseInterface $release, array $includedSections): array {
    $allowed = array_fill_keys($includedSections, TRUE);
    $sections = [];
    foreach ($release->getSections() as $key => $items) {
      if (!isset($allowed[$key]) || $items === []) {
        continue;
      }
      $sections[$key] = [
        'label' => $this->sectionLabel($key),
        'items' => array_map(
          static fn (array $item): array => ['text' => (string) ($item['text'] ?? '')],
          $items,
        ),
      ];
    }
    return [
      'title' => $release->getTitle(),
      'date' => $this->dateFormatter->format($release->getReleaseDate(), 'medium'),
      'date_iso' => $this->dateFormatter->format($release->getReleaseDate(), 'custom', 'c'),
      'version' => $release->getVersion(),
      'sections' => $sections,
      'url' => $this->releaseUrl($release->getSlug())->toString(),
      'translation_fallback' => $this->fallbackMode() === 'label'
      && $release->language()->getId()
      !== $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId(),
      'language_name' => $release->language()->getName(),
    ];
  }

  /**
   * Selects the negotiated public translation under the configured policy.
   */
  public function translateForPublic(ChangelogifyReleaseInterface $release): ?ChangelogifyReleaseInterface {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    if ($release->hasTranslation($langcode)) {
      $translation = $release->getTranslation($langcode);
      return $translation->isPublished() ? $translation : NULL;
    }
    $source = $release->getUntranslated();
    return $this->fallbackMode() !== 'hide' && $source->isPublished() ? $source : NULL;
  }

  /**
   * Returns the validated missing-translation behavior.
   */
  private function fallbackMode(): string {
    $mode = (string) ($this->configFactory
      ->get('changelogify.settings')
      ->get('translation_fallback') ?? 'fallback');
    return in_array($mode, ['hide', 'fallback', 'label'], TRUE) ? $mode : 'fallback';
  }

  /**
   * Returns the configured changelog URL without assuming the default route.
   */
  public function changelogUrl(array $options = []): Url {
    return Url::fromUserInput($this->basePath(), $options);
  }

  /**
   * Returns the configured URL for one release slug.
   */
  public function releaseUrl(string $slug, array $options = []): Url {
    return Url::fromUserInput($this->basePath() . '/' . rawurlencode($slug), $options);
  }

  /**
   * Returns a translated public section label.
   */
  private function sectionLabel(string $section): string {
    return (string) match ($section) {
      'added' => $this->t('Added'),
      'changed' => $this->t('Changed'),
      'fixed' => $this->t('Fixed'),
      'removed' => $this->t('Removed'),
      'security' => $this->t('Security'),
      'other' => $this->t('Other'),
      default => ucfirst($section),
    };
  }

  /**
   * Resolves the configured public prefix.
   */
  private function basePath(): string {
    $configured = (string) $this->configFactory
      ->get('changelogify.settings')
      ->get('changelog_path');
    return '/' . trim($configured ?: '/changelog', '/');
  }

}
