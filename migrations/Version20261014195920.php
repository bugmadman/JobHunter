<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014195920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When the owner applied to a job posting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_posting ADD applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_posting DROP applied_at');
    }
}
