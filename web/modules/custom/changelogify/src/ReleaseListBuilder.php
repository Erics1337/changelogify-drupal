<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * List builder for releases.
 */
class ReleaseListBuilder extends EntityListBuilder
{

    /**
     * {@inheritdoc}
     */
    protected function getEntityIds()
    {
        $query = $this->getStorage()->getQuery()
            ->accessCheck(TRUE)
            ->sort('release_date', 'DESC');

        // Only add the pager if a limit is specified.
        if ($this->limit) {
            $query->pager($this->limit);
        }

        return $query->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function buildHeader(): array
    {
        $header = [
            'title' => $this->t('Title'),
            'version' => $this->t('Version'),
            'date' => $this->t('Date'),
            'status' => $this->t('Status'),
        ];
        return $header + parent::buildHeader();
    }

    /**
     * {@inheritdoc}
     */
    public function buildRow(EntityInterface $entity): array
    {
        /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $entity */
        $row = [
            'title' => $entity->toLink($entity->getTitle(), 'edit-form'),
            'version' => $entity->getVersion() ?: '-',
            'date' => \Drupal::service('date.formatter')->format($entity->getReleaseDate(), 'short'),
            'status' => $entity->isPublished() ? $this->t('Published') : $this->t('Draft'),
        ];
        return $row + parent::buildRow($entity);
    }

}
