<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012153912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Job posting intake API key hash on the user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD api_key_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9848473FE ON app_user (api_key_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_88BDF3E9848473FE');
        $this->addSql('ALTER TABLE app_user DROP api_key_hash');
    }
}
