<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the playback_job table for the first Core -> Playback integration slice.
 */
final class Version20260626190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create playback_job table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE playback_job (id UUID NOT NULL, source_message_id UUID NOT NULL, announcement_id UUID NOT NULL, type VARCHAR(32) NOT NULL, priority INT NOT NULL, status VARCHAR(32) NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, audio_sequence JSON NOT NULL, repeat_rule JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PLAYBACK_JOB_SOURCE_MESSAGE ON playback_job (source_message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE playback_job');
    }
}
