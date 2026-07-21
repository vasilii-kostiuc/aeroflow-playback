<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flag a repeatable playback job whose series has been stopped (task 020).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job ADD repeat_cancelled BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playback_job DROP repeat_cancelled');
    }
}
