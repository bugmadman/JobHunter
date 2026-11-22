<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122170409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A single English CV analysis text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_profile DROP analysis_original');
        $this->addSql('ALTER TABLE cv_profile RENAME COLUMN analysis_english TO analysis');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_profile RENAME COLUMN analysis TO analysis_english');
        $this->addSql('ALTER TABLE cv_profile ADD analysis_original TEXT DEFAULT NULL');
        // The old code requires both texts of a done analysis; the original-language one is gone for good
        $this->addSql('UPDATE cv_profile SET analysis_original = analysis_english');
    }
}
