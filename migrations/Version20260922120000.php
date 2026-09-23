<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flag a playing job the queue dispatcher has asked to preempt (task 025).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job ADD preempt_requested BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job DROP preempt_requested');
    }
}
