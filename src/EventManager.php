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
     * Constructs an EventManager.
     */
    public function __construct(
        protected EntityTypeManagerInterface $entityTypeManager,
        protected AccountProxyInterface $currentUser,
        protected TimeInterface $time
    ) {
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
        $start = new \DateTimeImmutable('@' . $this->getNextReleaseStartTimestamp());
        $end = new \DateTimeImmutable('@' . $this->time->getRequestTime());

        return $this->getEventsByRange($start, $end);
    }

    /**
     * {@inheritdoc}
     */
    public function getNextReleaseStartTimestamp(): int
    {
        $release_storage = $this->entityTypeManager->getStorage('changelogify_release');
        $release_ids = $release_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('status', TRUE)
            ->sort('release_date', 'DESC')
            ->range(0, 1)
            ->execute();

        if (empty($release_ids)) {
            return 0;
        }

        /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface|null $release */
        $release = $release_storage->load(reset($release_ids));
        if ($release === NULL) {
            return 0;
        }

        $boundary = (int) ($release->get('date_end')->value ?? $release->getReleaseDate());
        return $boundary + 1;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventCountSinceLastRelease(): int
    {
        return $this->getEventCountSince($this->getNextReleaseStartTimestamp());
    }

    /**
     * {@inheritdoc}
     */
    public function purgeExpiredEvents(int $retention_days, int $limit = 1000): int
    {
        if ($retention_days < 1 || $limit < 1) {
            return 0;
        }

        $storage = $this->entityTypeManager->getStorage('changelogify_event');
        $cutoff = $this->time->getCurrentTime() - ($retention_days * 86400);
        $ids = $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('timestamp', $cutoff, '<')
            ->sort('timestamp', 'ASC')
            ->range(0, $limit)
            ->execute();

        if (empty($ids)) {
            return 0;
        }

        $events = $storage->loadMultiple($ids);
        $storage->delete($events);
        return count($events);
    }

}
