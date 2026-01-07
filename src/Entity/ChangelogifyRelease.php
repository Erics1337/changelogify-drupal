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
  ],
  links: [
    "canonical" => "/changelog/{changelogify_release}",
    "add-form" => "/admin/content/changelogify/releases/add",
    "edit-form" => "/admin/content/changelogify/releases/{changelogify_release}/edit",
    "delete-form" => "/admin/content/changelogify/releases/{changelogify_release}/delete",
    "collection" => "/admin/content/changelogify/releases",
  ],
)]
class ChangelogifyRelease extends ContentEntityBase implements ChangelogifyReleaseInterface
{

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array
  {
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
  public static function getDefaultTimestamp(): int
  {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string
  {
    return $this->get('title')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle(string $title): ChangelogifyReleaseInterface
  {
    $this->set('title', $title);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished(): bool
  {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished(bool $published = TRUE): ChangelogifyReleaseInterface
  {
    $this->set('status', $published);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSections(): array
  {
    $value = $this->get('sections')->value;
    if (empty($value)) {
      return $this->getDefaultSections();
    }
    $decoded = json_decode($value, TRUE);
    return is_array($decoded) ? $decoded : $this->getDefaultSections();
  }

  /**
   * {@inheritdoc}
   */
  public function setSections(array $sections): ChangelogifyReleaseInterface
  {
    $this->set('sections', json_encode($sections));
    return $this;
  }

  /**
   * Get default sections structure.
   */
  protected function getDefaultSections(): array
  {
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
  public function getReleaseDate(): int
  {
    return (int) $this->get('release_date')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): ?string
  {
    return $this->get('version')->value;
  }

}
