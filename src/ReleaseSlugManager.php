<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Generates and resolves unique, durable public release slugs.
 */
final class ReleaseSlugManager {

  public const MAX_LENGTH = 128;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TransliterationInterface $transliteration,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Ensures a release has a normalized, unique slug and retained history.
   */
  public function prepare(ChangelogifyReleaseInterface $release): void {
    $requested = (string) $release->get('slug')->value;
    $base = $this->normalize($requested !== '' ? $requested : $release->getTitle());
    $langcode = $release->language()->getId();
    $slug = $this->uniqueSlug($base, $release->id() === NULL ? NULL : (int) $release->id(), $langcode);
    $original = NULL;
    if (method_exists($release, 'getOriginal')) {
      $original = $release->getOriginal();
    }
    elseif (isset($release->original) && $release->original instanceof ChangelogifyReleaseInterface) {
      $original = $release->original;
    }
    if ($original instanceof ChangelogifyReleaseInterface && $original->hasTranslation($langcode)) {
      $original = $original->getTranslation($langcode);
    }
    if ($original instanceof ChangelogifyReleaseInterface) {
      $oldSlug = $original->getSlug();
      if ($oldSlug !== '' && $oldSlug !== $slug) {
        $history = $release->getSlugHistory();
        $history[] = $oldSlug;
        $release->setSlugHistory(array_values(array_unique($history)));
      }
    }
    $release->set('slug', $slug);
  }

  /**
   * Normalizes a title or manual value into the public slug contract.
   */
  public function normalize(string $value): string {
    $value = strtolower($this->transliteration->transliterate($value, 'en', '-'));
    $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    if ($value === '' || !preg_match('/^[a-z]/', $value)) {
      $value = 'release-' . ($value !== '' ? $value : 'untitled');
    }
    return substr($value, 0, self::MAX_LENGTH);
  }

  /**
   * Loads a release by current or historical slug.
   *
   * @return array{release: \Drupal\changelogify\Entity\ChangelogifyReleaseInterface, historical: bool}|null
   *   The resolved release and whether the slug is historical.
   */
  public function resolve(string $slug, ?string $langcode = NULL): ?array {
    $langcode ??= $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('slug', $slug)
      ->condition('langcode', $langcode)
      ->range(0, 1)
      ->execute();
    $usedFallback = FALSE;
    if ($ids === [] && $this->allowsFallback()) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('slug', $slug)
        ->condition('default_langcode', TRUE)
        ->range(0, 1)
        ->execute();
      $usedFallback = $ids !== [];
    }
    if ($ids !== []) {
      $release = $storage->load(reset($ids));
      if ($usedFallback && $release->hasTranslation($langcode)) {
        return NULL;
      }
      if (!$release->hasTranslation($langcode)
        && $release->language()->getId() !== $langcode) {
        return [
          'release' => $release,
          'historical' => FALSE,
        ];
      }
      return [
        'release' => $release->getTranslation($langcode),
        'historical' => FALSE,
      ];
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('slug_history', $slug)
      ->condition('langcode', $langcode)
      ->range(0, 1)
      ->execute();
    $usedFallback = FALSE;
    if ($ids === [] && $this->allowsFallback()) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('slug_history', $slug)
        ->condition('default_langcode', TRUE)
        ->range(0, 1)
        ->execute();
      $usedFallback = $ids !== [];
    }
    if ($ids === []) {
      return NULL;
    }
    $release = $storage->load(reset($ids));
    if ($usedFallback && $release->hasTranslation($langcode)) {
      return NULL;
    }
    if (!$release->hasTranslation($langcode)
      && $release->language()->getId() !== $langcode) {
      return [
        'release' => $release,
        'historical' => TRUE,
      ];
    }
    return [
      'release' => $release->getTranslation($langcode),
      'historical' => TRUE,
    ];
  }

  /**
   * Finds an unused current and historical slug.
   */
  private function uniqueSlug(string $base, ?int $releaseId, string $langcode): string {
    for ($suffix = 1; $suffix < 10000; $suffix++) {
      $addition = $suffix === 1 ? '' : '-' . $suffix;
      $candidate = substr($base, 0, self::MAX_LENGTH - strlen($addition)) . $addition;
      $resolved = $this->resolve($candidate, $langcode);
      if ($resolved === NULL || (int) $resolved['release']->id() === $releaseId) {
        return $candidate;
      }
    }
    throw new \LengthException('A unique release slug could not be generated.');
  }

  /**
   * Returns whether source-language slug fallback is enabled.
   */
  private function allowsFallback(): bool {
    return (string) ($this->configFactory
      ->get('changelogify.settings')
      ->get('translation_fallback') ?? 'fallback') !== 'hide';
  }

}
