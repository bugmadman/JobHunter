<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The failure cause of a CV analysis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_profile ADD analysis_failure_cause VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE cv_profile ADD analysis_failure_message TEXT DEFAULT NULL');
        // A failed analysis must have a cause; the reason of the ones failed before was only in the worker log
        $this->addSql("UPDATE cv_profile SET analysis_failure_cause = 'unexpected' WHERE analysis_status = 'failed'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_profile DROP analysis_failure_cause');
        $this->addSql('ALTER TABLE cv_profile DROP analysis_failure_message');
    }
}
