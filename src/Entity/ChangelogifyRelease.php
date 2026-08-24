<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Changelogify Release entity.
 *
 * @ContentEntityType(
 *   id = "changelogify_release",
 *   label = @Translation("Release"),
 *   label_collection = @Translation("Releases"),
 *   label_singular = @Translation("release"),
 *   label_plural = @Translation("releases"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\changelogify\ReleaseListBuilder",
 *     "access" = "Drupal\changelogify\Access\ChangelogifyReleaseAccessControlHandler",
 *     "storage_schema" = "Drupal\changelogify\Entity\ChangelogifyReleaseStorageSchema",
 *     "form" = {
 *       "default" = "Drupal\changelogify\Form\ReleaseForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "changelogify_release",
 *   admin_permission = "manage changelogify releases",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "owner" = "uid",
 *     "published" = "status"
 *   },
 *   links = {
 *     "add-form" = "/admin/content/changelogify/releases/add",
 *     "edit-form" = "/admin/content/changelogify/releases/{changelogify_release}/edit",
 *     "delete-form" = "/admin/content/changelogify/releases/{changelogify_release}/delete",
 *     "collection" = "/admin/content/changelogify/releases"
 *   }
 * )
 */
#[ContentEntityType(
  id: "changelogify_release",
  label: new TranslatableMarkup("Release"),
  label_collection: new TranslatableMarkup("Releases"),
  label_singular: new TranslatableMarkup("release"),
  label_plural: new TranslatableMarkup("releases"),
  handlers: [
    "view_builder" => "Drupal\Core\Entity\EntityViewBuilder",
    "list_builder" => "Drupal\changelogify\ReleaseListBuilder",
    "access" => "Drupal\changelogify\Access\ChangelogifyReleaseAccessControlHandler",
    "storage_schema" => "Drupal\changelogify\Entity\ChangelogifyReleaseStorageSchema",
    "form" => [
      "default" => "Drupal\changelogify\Form\ReleaseForm",
      "delete" => "Drupal\Core\Entity\ContentEntityDeleteForm",
    ],
    "route_provider" => [
      "html" => "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
    ],
  ],
  base_table: "changelogify_release",
  admin_permission: "manage changelogify releases",
  entity_keys: [
    "id" => "id",
    "uuid" => "uuid",
    "label" => "title",
    "owner" => "uid",
    "published" => "status",
  ],
  links: [
    "add-form" => "/admin/content/changelogify/releases/add",
    "edit-form" => "/admin/content/changelogify/releases/{changelogify_release}/edit",
    "delete-form" => "/admin/content/changelogify/releases/{changelogify_release}/delete",
    "collection" => "/admin/content/changelogify/releases",
  ],
)]
class ChangelogifyRelease extends ContentEntityBase implements ChangelogifyReleaseInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of the release, e.g. "October 2025 Release" or "v1.2.0".'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['label_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Label Type'))
      ->setDescription(t('The type of label for this release.'))
      ->setSetting('allowed_values', [
        'date_range' => 'Date Range',
        'custom' => 'Custom',
        'semantic_version' => 'Semantic Version',
      ])
      ->setDefaultValue('custom')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['version'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Version'))
      ->setDescription(t('Semantic version number, e.g. "1.2.0".'))
      ->setSetting('max_length', 50)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['release_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Release Date'))
      ->setDescription(t('The date of the release.'))
      ->setDefaultValueCallback(static::class . '::getDefaultTimestamp')
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => -7,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['date_start'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Date Start'))
      ->setDescription(t('Start of the change window this release covers.'))
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['date_end'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Date End'))
      ->setDescription(t('End of the change window this release covers.'))
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['sections'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Sections'))
      ->setDescription(t('JSON-encoded sections with release items.'))
      ->setDefaultValue('{}')
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDescription(t('Whether the release is published.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the release was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the release was last edited.'));

    return $fields;
  }

  /**
   * Default value callback for release_date field.
   */
  public static function getDefaultTimestamp(): int {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return $this->get('title')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle(string $title): ChangelogifyReleaseInterface {
    $this->set('title', $title);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished(bool $published = TRUE): ChangelogifyReleaseInterface {
    $this->set('status', $published);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setUnpublished(): ChangelogifyReleaseInterface {
    return $this->setPublished(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSections(): array {
    $value = $this->get('sections')->value;
    if (empty($value)) {
      return $this->getDefaultSections();
    }

    $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new \UnexpectedValueException('Release sections must decode to an array.');
    }

    return $decoded + $this->getDefaultSections();
  }

  /**
   * {@inheritdoc}
   */
  public function setSections(array $sections): ChangelogifyReleaseInterface {
    $normalized = $this->getDefaultSections();
    foreach ($sections as $section => $items) {
      if (!array_key_exists($section, $normalized)) {
        throw new \InvalidArgumentException(sprintf('Unknown release section "%s".', $section));
      }
      if (!is_array($items)) {
        throw new \InvalidArgumentException(sprintf('Release section "%s" must contain an array of items.', $section));
      }

      $normalized[$section] = [];
      foreach ($items as $item) {
        if (!is_array($item)
          || !isset($item['id'], $item['text'])
          || !is_string($item['id'])
          || $item['id'] === ''
          || !is_string($item['text'])
          || trim($item['text']) === ''
          || !is_array($item['event_ids'] ?? [])) {
          throw new \InvalidArgumentException(sprintf('Release section "%s" contains an invalid item.', $section));
        }

        $normalized[$section][] = [
          'id' => $item['id'],
          'text' => trim($item['text']),
          'event_ids' => array_values($item['event_ids'] ?? []),
        ];
      }
    }

    $this->set('sections', json_encode($normalized, JSON_THROW_ON_ERROR));
    return $this;
  }

  /**
   * Get default sections structure.
   */
  protected function getDefaultSections(): array {
    return [
      'added' => [],
      'changed' => [],
      'fixed' => [],
      'removed' => [],
      'security' => [],
      'other' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReleaseDate(): int {
    return (int) $this->get('release_date')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): ?string {
    return $this->get('version')->value;
  }

}
