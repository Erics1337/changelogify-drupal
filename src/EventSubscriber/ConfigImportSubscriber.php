<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSubscriber;

use Drupal\changelogify\ConfigClassifier\ConfigClassifierInterface;
use Drupal\changelogify\EventInput;
use Drupal\changelogify\EventSource\ConfigImportEventSource;
use Drupal\changelogify\EventSource\EventSourceRecorderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records completed configuration imports as bounded correlated operations.
 */
final class ConfigImportSubscriber implements EventSubscriberInterface {

  private const MAX_MEMBERS = 200;

  /**
   * Importers already recorded in this request.
   *
   * @var \WeakMap<object, bool>
   */
  private \WeakMap $recordedImporters;

  public function __construct(
    private readonly ConfigImportEventSource $source,
    private readonly EventSourceRecorderInterface $recorder,
    private readonly ConfigClassifierInterface $classifier,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {
    $this->recordedImporters = new \WeakMap();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::IMPORT => ['onImport', -1000],
      ConfigEvents::IMPORT_VALIDATE => ['onValidate', -1000],
    ];
  }

  /**
   * Records a successfully completed import.
   */
  public function onImport(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    if (isset($this->recordedImporters[$importer])) {
      return;
    }
    $this->recordedImporters[$importer] = TRUE;

    $members = [];
    $totals = ['create' => 0, 'update' => 0, 'delete' => 0, 'rename' => 0];
    $excluded = 0;
    $truncated = 0;
    $comparer = $importer->getStorageComparer();
    $collections = $comparer->getAllCollectionNames(TRUE);
    sort($collections);
    foreach ($collections as $collection) {
      $displayCollection = $collection === StorageInterface::DEFAULT_COLLECTION
        ? 'default'
        : $collection;
      foreach (['create', 'update', 'delete', 'rename'] as $operation) {
        $names = $event->getChangelist($operation, $collection);
        sort($names);
        $totals[$operation] += count($names);
        foreach ($names as $name) {
          $renameNames = $operation === 'rename'
            ? $comparer->extractRenameNames($name)
            : NULL;
          $classifiedName = $renameNames['new_name'] ?? $name;
          $classification = $this->classifier->classify($classifiedName, $displayCollection);
          if ($this->isExcluded($classifiedName, $classification->sensitive)) {
            $excluded++;
            continue;
          }
          if (count($members) >= self::MAX_MEMBERS) {
            $truncated++;
            continue;
          }
          $member = [
            'operation' => $operation,
            'name' => $renameNames['old_name'] ?? $name,
            'collection' => $displayCollection,
            'category' => $classification->category,
            'owning_extension' => $classification->owningExtension,
            'sensitive' => $classification->sensitive,
          ];
          if ($renameNames !== NULL) {
            $member['new_name'] = $renameNames['new_name'];
          }
          $members[] = $member;
        }
      }
    }
    if (array_sum($totals) === 0) {
      return;
    }

    $this->recorder->record($this->source, new EventInput(
      eventType: 'config_import_succeeded',
      source: 'config',
      message: 'Applied configuration import.',
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      sectionHint: 'changed',
      metadata: [
        'status' => 'succeeded',
        'totals' => $totals,
        'member_count' => count($members),
        'excluded_count' => $excluded,
        'truncated_count' => $truncated,
        'members' => $members,
      ],
      correlationId: $this->uuid->generate(),
    ));
  }

  /**
   * Records validation failures without claiming the import was applied.
   */
  public function onValidate(ConfigImporterEvent $event): void {
    $errors = $event->getConfigImporter()->getErrors();
    if ($errors !== []) {
      $this->recordFailure($event, count($errors));
    }
  }

  /**
   * Records an import failure observed by an integration or validation event.
   */
  public function recordFailure(ConfigImporterEvent $event, int $errorCount = 1): void {
    $importer = $event->getConfigImporter();
    if (isset($this->recordedImporters[$importer])) {
      return;
    }
    $this->recordedImporters[$importer] = TRUE;
    $this->recorder->record($this->source, new EventInput(
      eventType: 'config_import_failed',
      source: 'config',
      message: 'Configuration import failed.',
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      sectionHint: 'other',
      metadata: [
        'status' => 'failed',
        'error_count' => max(1, $errorCount),
      ],
      correlationId: $this->uuid->generate(),
    ));
  }

  /**
   * Applies configured names and default sensitivity exclusions.
   */
  private function isExcluded(string $name, bool $sensitive): bool {
    $config = $this->configFactory->get('changelogify.settings');
    if ($sensitive && !(bool) ($config->get('config_import.include_sensitive') ?? FALSE)) {
      return TRUE;
    }
    $patterns = $config->get('config_import.excluded_patterns') ?? [];
    if (!is_array($patterns)) {
      return FALSE;
    }
    foreach ($patterns as $pattern) {
      if (is_string($pattern) && $pattern !== '' && $this->matchesPattern($pattern, $name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Matches a portable shell-style asterisk/question-mark pattern.
   */
  private function matchesPattern(string $pattern, string $name): bool {
    $expression = str_replace(
      ['\\*', '\\?'],
      ['.*', '.'],
      preg_quote($pattern, '/'),
    );
    return preg_match('/^' . $expression . '$/D', $name) === 1;
  }

}
