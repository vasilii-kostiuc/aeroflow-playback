<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When the current play of a job began (task 034), for the worker heartbeat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job ADD started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job DROP started_at');
    }
}
