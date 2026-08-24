<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify\EventInput;
use Drupal\changelogify\EventManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Records normalized events only for enabled sources.
 */
final class EventSourceRecorder implements EventSourceRecorderInterface {

  public function __construct(
    private readonly EventManagerInterface $eventManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function record(EventSourceInterface $source, EventInput $input): ?ChangelogifyEventInterface {
    if (!$this->isEnabled($source)) {
      return NULL;
    }
    if (!in_array($input->eventType, $source->getSupportedEventTypes(), TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Event type "%s" is not supported by source "%s".',
        $input->eventType,
        $source->getId(),
      ));
    }
    return $this->eventManager->logEventInput($input);
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(EventSourceInterface $source): bool {
    $config = $this->configFactory->get('changelogify.settings');
    $enabled = $config->get(sprintf('event_sources.%s.enabled', $source->getId()));
    if ($enabled !== NULL) {
      return (bool) $enabled;
    }

    $legacyKey = $source->getLegacyEnabledSetting();
    if ($legacyKey !== NULL && $config->get($legacyKey) !== NULL) {
      return (bool) $config->get($legacyKey);
    }

    return $source->getConfigurationDefaults()['enabled'];
  }

}
