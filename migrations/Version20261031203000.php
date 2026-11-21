<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The failure cause of a cover letter generation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cover_letter ADD failure_cause VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE cover_letter ADD failure_message TEXT DEFAULT NULL');
        // A failed generation must have a cause; the reason of the ones failed before was only in the worker log
        $this->addSql("UPDATE cover_letter SET failure_cause = 'unexpected' WHERE status = 'failed'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cover_letter DROP failure_cause');
        $this->addSql('ALTER TABLE cover_letter DROP failure_message');
    }
}
