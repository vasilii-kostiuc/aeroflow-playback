<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Persistence\Doctrine;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Enum\PlaybackJobStatus;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PlaybackJobRepository implements PlaybackJobRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(PlaybackJob $job): void
    {
        $this->entityManager->persist($job);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?PlaybackJob
    {
        return $this->entityManager->find(PlaybackJob::class, $id);
    }

    public function findBySourceMessageId(Uuid $sourceMessageId): ?PlaybackJob
    {
        return $this->entityManager
            ->getRepository(PlaybackJob::class)
            ->findOneBy(['sourceMessageId' => $sourceMessageId]);
    }

    public function findActive(): ?PlaybackJob
    {
        return $this->entityManager
            ->getRepository(PlaybackJob::class)
            ->findOneBy(['status' => PlaybackJobStatus::Playing]);
    }

    public function findNextPendingForUpdate(): ?PlaybackJob
    {
        // PESSIMISTIC_WRITE serialises concurrent dispatchers on the same head-of-queue
        // row: the loser waits, re-reads the row and finds it no longer Pending. The
        // partial unique index on the single Playing row is the last-resort guard.
        $jobs = $this->entityManager->createQueryBuilder()
            ->select('job')
            ->from(PlaybackJob::class, 'job')
            ->where('job.status = :status')
            ->setParameter('status', PlaybackJobStatus::Pending)
            ->orderBy('job.priority', 'DESC')
            ->addOrderBy('job.createdAt', 'ASC')
            ->addOrderBy('job.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $jobs[0] ?? null;
    }

    public function findLatestByAnnouncementIdForUpdate(Uuid $announcementId): ?PlaybackJob
    {
        $jobs = $this->entityManager->createQueryBuilder()
            ->select('job')
            ->from(PlaybackJob::class, 'job')
            ->where('job.announcementId = :announcementId')
            ->setParameter('announcementId', $announcementId)
            ->orderBy('job.createdAt', 'DESC')
            ->addOrderBy('job.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $jobs[0] ?? null;
    }
}
