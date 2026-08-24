# Configuration classifier API

`ConfigClassifierInterface` converts a Drupal configuration object name and
collection into a stable machine category, human category label, owning extension
when known, and sensitivity flag. Unknown names retain their original name and use
the `other_configuration` category.

Contributed modules can register overrides without altering Changelogify:

```yaml
services:
  Drupal\example\ExampleConfigClassifier:
    autowire: true
    tags:
      - { name: changelogify.config_classifier }
```

The service implements `ConfigClassifierExtensionInterface`. Higher values from
`getPriority()` run first; equal priorities are resolved by classifier class name,
making conflicts deterministic. Extensions run before built-in rules and may
therefore override a core category intentionally.

Classification results are cached only in memory for the current request. No
configuration values or sensitive payloads are read or persisted by this API.
