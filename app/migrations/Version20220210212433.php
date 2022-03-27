<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\AbstractMauticMigration;

final class Version20220210212433 extends AbstractMauticMigration
{
    public function getDescription(): string
    {
        return 'Add custom_url_parameters to emails table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE {$this->prefix}emails ADD custom_url_parameters VARCHAR(191) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE {$this->prefix}emails DROP custom_url_parameters");
    }
}
