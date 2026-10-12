<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012125806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CV profile skills';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cv_profile_skill (cv_profile_id INT NOT NULL, skill_id INT NOT NULL, PRIMARY KEY (cv_profile_id, skill_id))');
        $this->addSql('CREATE INDEX IDX_F963E32B30C872A ON cv_profile_skill (cv_profile_id)');
        $this->addSql('CREATE INDEX IDX_F963E325585C142 ON cv_profile_skill (skill_id)');
        $this->addSql('ALTER TABLE cv_profile_skill ADD CONSTRAINT FK_F963E32B30C872A FOREIGN KEY (cv_profile_id) REFERENCES cv_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cv_profile_skill ADD CONSTRAINT FK_F963E325585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_profile_skill DROP CONSTRAINT FK_F963E32B30C872A');
        $this->addSql('ALTER TABLE cv_profile_skill DROP CONSTRAINT FK_F963E325585C142');
        $this->addSql('DROP TABLE cv_profile_skill');
    }
}
