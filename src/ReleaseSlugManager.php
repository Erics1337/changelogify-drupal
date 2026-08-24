<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Generates and resolves unique, durable public release slugs.
 */
final class ReleaseSlugManager {

  public const MAX_LENGTH = 128;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TransliterationInterface $transliteration,
  ) {
  }

  /**
   * Ensures a release has a normalized, unique slug and retained history.
   */
  public function prepare(ChangelogifyReleaseInterface $release): void {
    $requested = (string) $release->get('slug')->value;
    $base = $this->normalize($requested !== '' ? $requested : $release->getTitle());
    $slug = $this->uniqueSlug($base, $release->id() === NULL ? NULL : (int) $release->id());
    $original = NULL;
    if (method_exists($release, 'getOriginal')) {
      $original = $release->getOriginal();
    }
    elseif (isset($release->original) && $release->original instanceof ChangelogifyReleaseInterface) {
      $original = $release->original;
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
  public function resolve(string $slug): ?array {
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('slug', $slug)
      ->range(0, 1)
      ->execute();
    if ($ids !== []) {
      return ['release' => $storage->load(reset($ids)), 'historical' => FALSE];
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('slug_history', $slug)
      ->range(0, 1)
      ->execute();
    return $ids === []
      ? NULL
      : ['release' => $storage->load(reset($ids)), 'historical' => TRUE];
  }

  /**
   * Finds an unused current and historical slug.
   */
  private function uniqueSlug(string $base, ?int $releaseId): string {
    for ($suffix = 1; $suffix < 10000; $suffix++) {
      $addition = $suffix === 1 ? '' : '-' . $suffix;
      $candidate = substr($base, 0, self::MAX_LENGTH - strlen($addition)) . $addition;
      $resolved = $this->resolve($candidate);
      if ($resolved === NULL || (int) $resolved['release']->id() === $releaseId) {
        return $candidate;
      }
    }
    throw new \LengthException('A unique release slug could not be generated.');
  }

}
