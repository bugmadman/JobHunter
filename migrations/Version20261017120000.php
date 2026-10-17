<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the job platforms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform (name, access_type) VALUES
                ('Upwork', 'browser'),
                ('LinkedIn', 'browser'),
                ('Glassdoor', 'browser'),
                ('Indeed', 'browser')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Platforms may already have job postings and prompts');
    }
}
