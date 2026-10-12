<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012005109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LLM provider keys hold the encrypted API key';
    }

    public function up(Schema $schema): void
    {
        // Rows written before encryption hold plaintext keys: they must not survive in the "encrypted" column
        $this->addSql('DELETE FROM llm_provider_key');
        $this->addSql('ALTER TABLE llm_provider_key RENAME COLUMN api_key TO encrypted_api_key');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM llm_provider_key');
        $this->addSql('ALTER TABLE llm_provider_key RENAME COLUMN encrypted_api_key TO api_key');
    }
}
