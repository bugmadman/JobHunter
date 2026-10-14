<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014194902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The owner\'s comment on a job posting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_posting ADD comment TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_posting DROP comment');
    }
}
