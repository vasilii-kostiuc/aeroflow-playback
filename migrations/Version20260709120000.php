<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second slice (task 015): the queue is strictly sequential, so at most one job may
 * be Playing at a time (one global audio output in this version). The partial unique
 * index is the last-resort guard behind the dispatcher's pessimistic lock.
 */
final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce a single Playing playback_job (single global audio output).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE UNIQUE INDEX UNIQ_PLAYBACK_JOB_SINGLE_PLAYING ON playback_job ((1)) WHERE status = 'playing'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_PLAYBACK_JOB_SINGLE_PLAYING');
    }
}
