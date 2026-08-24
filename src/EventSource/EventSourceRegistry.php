<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

/**
 * Discovers and validates tagged event-source services.
 */
final class EventSourceRegistry implements EventSourceRegistryInterface {

  /**
   * Sources keyed by ID.
   *
   * @var \Drupal\changelogify\EventSource\EventSourceInterface[]|null
   */
  private ?array $sources = NULL;

  /**
   * Constructs the registry.
   *
   * @param iterable<\Drupal\changelogify\EventSource\EventSourceInterface> $sourceServices
   *   Tagged event-source services.
   */
  public function __construct(
    private readonly iterable $sourceServices,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(): array {
    if ($this->sources !== NULL) {
      return $this->sources;
    }

    $sources = [];
    foreach ($this->sourceServices as $source) {
      $id = $source->getId();
      if (isset($sources[$id])) {
        throw new \LogicException(sprintf('Duplicate Changelogify event source ID "%s".', $id));
      }
      if (preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
        throw new \LogicException(sprintf('Invalid Changelogify event source ID "%s".', $id));
      }
      $sources[$id] = $source;
    }
    ksort($sources);
    return $this->sources = $sources;
  }

  /**
   * {@inheritdoc}
   */
  public function getSource(string $id): EventSourceInterface {
    $sources = $this->getSources();
    if (!isset($sources[$id])) {
      throw new \InvalidArgumentException(sprintf('Unknown Changelogify event source "%s".', $id));
    }
    return $sources[$id];
  }

}
