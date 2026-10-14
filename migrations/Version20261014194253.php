<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014194253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The owner\'s manual verdict on a job posting, next to the LLM\'s';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_posting ADD manual_verdict VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_posting DROP manual_verdict');
    }
}
