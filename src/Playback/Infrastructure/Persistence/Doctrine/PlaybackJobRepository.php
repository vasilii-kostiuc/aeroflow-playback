<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Persistence\Doctrine;

use App\Playback\Domain\Entity\PlaybackJob;
use App\Playback\Domain\Repository\PlaybackJobRepositoryInterface;
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

    public function findBySourceMessageId(Uuid $sourceMessageId): ?PlaybackJob
    {
        return $this->entityManager
            ->getRepository(PlaybackJob::class)
            ->findOneBy(['sourceMessageId' => $sourceMessageId]);
    }
}
