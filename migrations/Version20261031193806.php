<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031193806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The failure cause of a prompt generation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prompt ADD generation_failure_cause VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE prompt ADD generation_failure_message TEXT DEFAULT NULL');
        // A failed generation must have a cause; the reason of the ones failed before was only in the worker log
        $this->addSql("UPDATE prompt SET generation_failure_cause = 'unexpected' WHERE generation_status = 'failed'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prompt DROP generation_failure_cause');
        $this->addSql('ALTER TABLE prompt DROP generation_failure_message');
    }
}
