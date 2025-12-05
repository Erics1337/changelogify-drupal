<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify\Entity\ChangelogifyEventInterface;

/**
 * Manages event logging and retrieval.
 */
class EventManager implements EventManagerInterface
{

    /**
     * The entity type manager.
     */
    protected EntityTypeManagerInterface $entityTypeManager;

    /**
     * The current user.
     */
    protected AccountProxyInterface $currentUser;

    /**
     * The time service.
     */
    protected TimeInterface $time;

    /**
     * Constructs an EventManager.
     */
    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        AccountProxyInterface $current_user,
        TimeInterface $time
    ) {
        $this->entityTypeManager = $entity_type_manager;
        $this->currentUser = $current_user;
        $this->time = $time;
    }

    /**
     * {@inheritdoc}
     */
    public function logEvent(array $data): ChangelogifyEventInterface
    {
        $storage = $this->entityTypeManager->getStorage('changelogify_event');

        $event_data = [
            'timestamp' => $data['timestamp'] ?? $this->time->getRequestTime(),
            'event_type' => $data['event_type'],
            'source' => $data['source'],
            'message' => $data['message'],
            'user_id' => $data['user_id'] ?? $this->currentUser->id(),
        ];

        if (isset($data['entity_type_id'])) {
            $event_data['entity_type_id'] = $data['entity_type_id'];
        }
        if (isset($data['entity_id'])) {
            $event_data['entity_id'] = $data['entity_id'];
        }
        if (isset($data['bundle'])) {
            $event_data['bundle'] = $data['bundle'];
        }
        if (isset($data['section_hint'])) {
            $event_data['section_hint'] = $data['section_hint'];
        }
        if (isset($data['metadata'])) {
            $event_data['metadata'] = json_encode($data['metadata']);
        }

        /** @var \Drupal\changelogify\Entity\ChangelogifyEventInterface $event */
        $event = $storage->create($event_data);
        $event->save();

        return $event;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventsByRange(\DateTimeInterface $start, \DateTimeInterface $end, array $filters = []): array
    {
        $storage = $this->entityTypeManager->getStorage('changelogify_event');

        $query = $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('timestamp', $start->getTimestamp(), '>=')
            ->condition('timestamp', $end->getTimestamp(), '<=')
            ->sort('timestamp', 'ASC');

        if (!empty($filters['event_type'])) {
            $query->condition('event_type', $filters['event_type']);
        }
        if (!empty($filters['source'])) {
            $query->condition('source', $filters['source']);
        }
        if (!empty($filters['section_hint'])) {
            $query->condition('section_hint', $filters['section_hint']);
        }

        $ids = $query->execute();

        if (empty($ids)) {
            return [];
        }

        return $storage->loadMultiple($ids);
    }

    /**
     * {@inheritdoc}
     */
    public function getEventCountSince(int $since): int
    {
        $storage = $this->entityTypeManager->getStorage('changelogify_event');

        return (int) $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('timestamp', $since, '>=')
            ->count()
            ->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function getEventsSinceLastRelease(): array
    {
        $release_storage = $this->entityTypeManager->getStorage('changelogify_release');

        // Find the last published release.
        $release_ids = $release_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('status', TRUE)
            ->sort('release_date', 'DESC')
            ->range(0, 1)
            ->execute();

        $since = 0;
        if (!empty($release_ids)) {
            /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
            $release = $release_storage->load(reset($release_ids));
            // Use date_end if available, otherwise use release_date.
            $since = $release->get('date_end')->value ?? $release->getReleaseDate();
        }

        $start = new \DateTimeImmutable('@' . $since);
        $end = new \DateTimeImmutable('@' . $this->time->getRequestTime());

        return $this->getEventsByRange($start, $end);
    }

}
