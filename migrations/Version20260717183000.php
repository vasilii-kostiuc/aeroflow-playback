<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260717183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store an outstanding operator stop request for a playing playback job.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job ADD stop_requested BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job DROP stop_requested');
    }
}
