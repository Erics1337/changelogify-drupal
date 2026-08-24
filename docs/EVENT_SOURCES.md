# Event source API

Changelogify discovers event sources as tagged Drupal services. A source declares
its stable ID, administrator label, privacy description, enabled default, legacy
configuration key when applicable, and the normalized event types it supports.

Sources should use Drupal lifecycle hooks for real-time changes and pass an
`EventInput` to `EventSourceRecorderInterface::record()`. The recorder prevents a
disabled source from creating new events while leaving historical events intact.

## Contributed source example

```yaml
services:
  Drupal\example\ExampleEventSource:
    autowire: true
    autoconfigure: true
    tags:
      - { name: changelogify.event_source }
```

```php
final class ExampleEventSource implements EventSourceInterface {
  public function __construct(
    private EventSourceRecorderInterface $recorder,
  ) {}

  public function getId(): string { return 'example'; }
  public function getLabel(): string { return 'Example changes'; }
  public function getPrivacyDescription(): string { return 'Stores example IDs.'; }
  public function getConfigurationDefaults(): array { return ['enabled' => false]; }
  public function getSupportedEventTypes(): array { return ['example_changed']; }
  public function getLegacyEnabledSetting(): ?string { return null; }

  // In an appropriate Drupal hook:
  // $this->recorder->record($this, new EventInput(...));
}
```

Source IDs must be unique lowercase machine names. Duplicate or malformed IDs
raise a clear exception when the registry is read. Contributed sources default to
their declared state and are never enabled automatically unless they explicitly
declare `enabled: true`.
