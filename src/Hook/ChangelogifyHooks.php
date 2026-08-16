<?php

declare(strict_types=1);

namespace Drupal\changelogify\Hook;

use Drupal\changelogify\EventManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Hook implementations for changelogify.
 */
class ChangelogifyHooks
{

    use StringTranslationTrait;

    public function __construct(
        protected EventManagerInterface $eventManager,
        protected ConfigFactoryInterface $configFactory,
    ) {
    }

    /**
     * Implements hook_entity_insert().
     */
    #[Hook('entity_insert')]
    public function entityInsert(EntityInterface $entity): void
    {
        if (!$entity instanceof NodeInterface || !$this->shouldTrackContent($entity)) {
            return;
        }

        $this->eventManager->logEvent([
            'event_type' => 'content_created',
            'source' => 'content_entity',
            'entity_type_id' => $entity->getEntityTypeId(),
            'entity_id' => (int) $entity->id(),
            'bundle' => $entity->bundle(),
            'message' => $this->t('Created @type: "@title"', [
                '@type' => $entity->type->entity->label(),
                '@title' => $entity->getTitle(),
            ])->__toString(),
            'section_hint' => 'added',
            'metadata' => [
                'title' => $entity->getTitle(),
                'path' => $entity->toUrl()->toString(),
            ],
        ]);
    }

    /**
     * Implements hook_entity_update().
     */
    #[Hook('entity_update')]
    public function entityUpdate(EntityInterface $entity): void
    {
        if (!$entity instanceof NodeInterface || !$this->shouldTrackContent($entity)) {
            return;
        }

        $this->eventManager->logEvent([
            'event_type' => 'content_updated',
            'source' => 'content_entity',
            'entity_type_id' => $entity->getEntityTypeId(),
            'entity_id' => (int) $entity->id(),
            'bundle' => $entity->bundle(),
            'message' => $this->t('Updated @type: "@title"', [
                '@type' => $entity->type->entity->label(),
                '@title' => $entity->getTitle(),
            ])->__toString(),
            'section_hint' => 'changed',
            'metadata' => [
                'title' => $entity->getTitle(),
                'path' => $entity->toUrl()->toString(),
            ],
        ]);
    }

    /**
     * Implements hook_entity_delete().
     */
    #[Hook('entity_delete')]
    public function entityDelete(EntityInterface $entity): void
    {
        if (!$entity instanceof NodeInterface || !$this->shouldTrackContent($entity)) {
            return;
        }

        $this->eventManager->logEvent([
            'event_type' => 'content_deleted',
            'source' => 'content_entity',
            'entity_type_id' => $entity->getEntityTypeId(),
            'entity_id' => (int) $entity->id(),
            'bundle' => $entity->bundle(),
            'message' => $this->t('Deleted @type: "@title"', [
                '@type' => $entity->type->entity->label(),
                '@title' => $entity->getTitle(),
            ])->__toString(),
            'section_hint' => 'removed',
            'metadata' => [
                'title' => $entity->getTitle(),
            ],
        ]);
    }

    /**
     * Implements hook_modules_installed().
     */
    #[Hook('modules_installed')]
    public function modulesInstalled(array $modules, bool $is_syncing): void
    {
        if ($is_syncing || !$this->settingEnabled('track_modules', TRUE)) {
            return;
        }

        foreach ($modules as $module) {
            // Skip logging our own installation.
            if ($module === 'changelogify') {
                continue;
            }

            $this->eventManager->logEvent([
                'event_type' => 'module_installed',
                'source' => 'system',
                'message' => $this->t('Installed module: @module', ['@module' => $module])->__toString(),
                'section_hint' => 'added',
                'metadata' => [
                    'module' => $module,
                ],
            ]);
        }
    }

    /**
     * Implements hook_modules_uninstalled().
     */
    #[Hook('modules_uninstalled')]
    public function modulesUninstalled(array $modules, bool $is_syncing): void
    {
        if ($is_syncing || !$this->settingEnabled('track_modules', TRUE)) {
            return;
        }

        foreach ($modules as $module) {
            $this->eventManager->logEvent([
                'event_type' => 'module_uninstalled',
                'source' => 'system',
                'message' => $this->t('Uninstalled module: @module', ['@module' => $module])->__toString(),
                'section_hint' => 'removed',
                'metadata' => [
                    'module' => $module,
                ],
            ]);
        }
    }

    /**
     * Implements hook_user_insert().
     */
    #[Hook('user_insert')]
    public function userInsert(UserInterface $account): void
    {
        if (!$this->settingEnabled('track_users', FALSE)) {
            return;
        }

        $this->eventManager->logEvent([
            'event_type' => 'user_created',
            'source' => 'user',
            'entity_type_id' => 'user',
            'entity_id' => (int) $account->id(),
            'message' => $this->t('Created user: @name', ['@name' => $account->getAccountName()])->__toString(),
            'section_hint' => 'added',
            'metadata' => [
                'username' => $account->getAccountName(),
            ],
        ]);
    }

    /**
     * Implements hook_user_update().
     */
    #[Hook('user_update')]
    public function userUpdate(UserInterface $account): void
    {
        if (!$this->settingEnabled('track_users', FALSE)) {
            return;
        }

        /** @var \Drupal\Core\Entity\EntityInterface $original */
        $original = $account->original;

        // Check if roles changed.
        $old_roles = $original->getRoles();
        $new_roles = $account->getRoles();

        if ($old_roles !== $new_roles) {
            $this->eventManager->logEvent([
                'event_type' => 'user_role_changed',
                'source' => 'user',
                'entity_type_id' => 'user',
                'entity_id' => (int) $account->id(),
                'message' => $this->t('Changed roles for user: @name', ['@name' => $account->getAccountName()])->__toString(),
                'section_hint' => 'changed',
                'metadata' => [
                    'username' => $account->getAccountName(),
                    'old_roles' => $old_roles,
                    'new_roles' => $new_roles,
                ],
            ]);
        }
    }

    /**
     * Implements hook_theme().
     */
    #[Hook('theme')]
    public function theme(): array
    {
        return [
            'changelogify_release_list' => [
                'variables' => [
                    'releases' => [],
                ],
            ],
            'changelogify_release' => [
                'variables' => [
                    'release' => NULL,
                    'sections' => [],
                ],
            ],
        ];
    }

    /**
     * Implements hook_cron().
     */
    #[Hook('cron')]
    public function cron(): void
    {
        $retention_days = (int) ($this->configFactory
            ->get('changelogify.settings')
            ->get('event_retention_days') ?? 90);
        $this->eventManager->purgeExpiredEvents($retention_days);
    }

    /**
     * Determines whether a node change should be recorded.
     */
    private function shouldTrackContent(NodeInterface $node): bool
    {
        if (!$this->settingEnabled('track_content', TRUE)) {
            return FALSE;
        }

        return $node->isPublished()
            || $this->settingEnabled('track_unpublished_content', FALSE);
    }

    /**
     * Reads a boolean setting while preserving a default for existing sites.
     */
    private function settingEnabled(string $key, bool $default): bool
    {
        $value = $this->configFactory->get('changelogify.settings')->get($key);
        return $value === NULL ? $default : (bool) $value;
    }

}
