<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012003457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User locale';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD locale VARCHAR(2) DEFAULT \'en\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP locale');
    }
}
