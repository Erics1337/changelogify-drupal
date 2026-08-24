<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\changelogify\EventInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;

/**
 * Captures configured user lifecycle events.
 */
final class UserEventSource implements EventSourceInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventSourceRecorderInterface $recorder,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'users';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Track user changes';
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivacyDescription(): string {
    return 'Privacy warning: stores usernames and old/new role assignments when users are created or roles change. Restrict administrative access and confirm a lawful retention policy before enabling.';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationDefaults(): array {
    return ['enabled' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedEventTypes(): array {
    return ['user_created', 'user_role_assignments_changed'];
  }

  /**
   * {@inheritdoc}
   */
  public function getLegacyEnabledSetting(): ?string {
    return 'track_users';
  }

  /**
   * Implements hook_user_insert().
   */
  public function userInsert(UserInterface $account): void {
    $this->recorder->record($this, new EventInput(
      eventType: 'user_created',
      source: 'user',
      message: $this->t('Created user: @name', ['@name' => $account->getAccountName()])->__toString(),
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      entityTypeId: 'user',
      entityId: (int) $account->id(),
      sectionHint: 'added',
      metadata: ['username' => $account->getAccountName()],
    ));
  }

  /**
   * Implements hook_user_update().
   */
  public function userUpdate(UserInterface $account): void {
    $original = $this->getOriginal($account);
    if (!$original instanceof UserInterface) {
      return;
    }
    $oldRoles = $original->getRoles();
    $newRoles = $account->getRoles();
    sort($oldRoles);
    sort($newRoles);
    if ($oldRoles === $newRoles) {
      return;
    }
    $this->recorder->record($this, new EventInput(
      eventType: 'user_role_assignments_changed',
      source: 'user',
      message: $this->t('Changed role assignments for user: @name', ['@name' => $account->getAccountName()])->__toString(),
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      entityTypeId: 'user',
      entityId: (int) $account->id(),
      sectionHint: 'changed',
      metadata: [
        'username' => $account->getAccountName(),
        'old_roles' => $oldRoles,
        'new_roles' => $newRoles,
      ],
    ));
  }

  /**
   * Gets the original account across supported Drupal versions.
   */
  private function getOriginal(UserInterface $account): ?UserInterface {
    if (method_exists($account, 'getOriginal')) {
      $original = $account->getOriginal();
      return $original instanceof UserInterface ? $original : NULL;
    }
    return isset($account->original) && $account->original instanceof UserInterface
      ? $account->original
      : NULL;
  }

}
