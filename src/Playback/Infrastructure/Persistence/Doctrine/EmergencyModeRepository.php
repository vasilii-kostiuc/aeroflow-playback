<?php

declare(strict_types=1);

namespace App\Playback\Infrastructure\Persistence\Doctrine;

use App\Playback\Domain\Entity\EmergencyMode;
use App\Playback\Domain\Repository\EmergencyModeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class EmergencyModeRepository implements EmergencyModeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function current(): EmergencyMode
    {
        return $this->entityManager->find(EmergencyMode::class, Uuid::fromString(EmergencyMode::SINGLETON_ID))
            ?? EmergencyMode::inactive();
    }

    public function save(EmergencyMode $mode): void
    {
        // The id is fixed, so a concurrent first activation cannot create a second
        // row: one of the two loses on the primary key and is redelivered.
        $this->entityManager->persist($mode);
        $this->entityManager->flush();
    }
}
