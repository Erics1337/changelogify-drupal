<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Changelogify Event entity.
 */
#[ContentEntityType(
    id: "changelogify_event",
    label: new TranslatableMarkup("Changelogify Event"),
    label_collection: new TranslatableMarkup("Events"),
    label_singular: new TranslatableMarkup("event"),
    label_plural: new TranslatableMarkup("events"),
    handlers: [
        "view_builder" => "Drupal\Core\Entity\EntityViewBuilder",
        "list_builder" => "Drupal\changelogify\EventListBuilder",
    ],
    base_table: "changelogify_event",
    admin_permission: "administer changelogify",
    entity_keys: [
        "id" => "id",
        "uuid" => "uuid",
        "label" => "message",
    ],
)]
class ChangelogifyEvent extends ContentEntityBase implements ChangelogifyEventInterface
{

    /**
     * {@inheritdoc}
     */
    public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array
    {
        $fields = parent::baseFieldDefinitions($entity_type);

        $fields['timestamp'] = BaseFieldDefinition::create('timestamp')
            ->setLabel(t('Timestamp'))
            ->setDescription(t('The time the event occurred.'))
            ->setRequired(TRUE);

        $fields['event_type'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Event Type'))
            ->setDescription(t('The type of event, e.g. content_created, module_installed.'))
            ->setRequired(TRUE)
            ->setSetting('max_length', 64);

        $fields['source'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Source'))
            ->setDescription(t('The source of the event, e.g. content_entity, config, user, system.'))
            ->setRequired(TRUE)
            ->setSetting('max_length', 64);

        $fields['entity_type_id'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Entity Type'))
            ->setDescription(t('The entity type, e.g. node, user, taxonomy_term.'))
            ->setSetting('max_length', 64);

        $fields['entity_id'] = BaseFieldDefinition::create('integer')
            ->setLabel(t('Entity ID'))
            ->setDescription(t('The ID of the related entity.'));

        $fields['bundle'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Bundle'))
            ->setDescription(t('The bundle of the related entity, e.g. article, page.'))
            ->setSetting('max_length', 64);

        $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
            ->setLabel(t('User'))
            ->setDescription(t('The user who triggered the event.'))
            ->setSetting('target_type', 'user');

        $fields['message'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Message'))
            ->setDescription(t('A short, technical description of the event.'))
            ->setRequired(TRUE)
            ->setSetting('max_length', 512);

        $fields['metadata'] = BaseFieldDefinition::create('string_long')
            ->setLabel(t('Metadata'))
            ->setDescription(t('JSON-encoded additional data about the event.'))
            ->setDefaultValue('{}');

        $fields['section_hint'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Section Hint'))
            ->setDescription(t('Suggested release section: added, changed, fixed, removed, security, other.'))
            ->setSetting('max_length', 32);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp(): int
    {
        return (int) $this->get('timestamp')->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventType(): string
    {
        return $this->get('event_type')->value ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function getSource(): string
    {
        return $this->get('source')->value ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function getMessage(): string
    {
        return $this->get('message')->value ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function getSectionHint(): ?string
    {
        return $this->get('section_hint')->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(): array
    {
        $value = $this->get('metadata')->value;
        if (empty($value)) {
            return [];
        }
        $decoded = json_decode($value, TRUE);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata(array $metadata): ChangelogifyEventInterface
    {
        $this->set('metadata', json_encode($metadata));
        return $this;
    }

}
