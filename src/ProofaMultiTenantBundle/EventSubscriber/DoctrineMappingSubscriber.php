<?php

namespace App\ProofaMultiTenantBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
class DoctrineMappingSubscriber
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        $tablesToExtend = [
            'kimai2_users',
            'kimai2_projects',
            'kimai2_activities',
            'kimai2_teams',
        ];

        foreach ($tablesToExtend as $tableName) {
            if ($schema->hasTable($tableName)) {
                $table = $schema->getTable($tableName);
                if (!$table->hasColumn('affine_id')) {
                    $table->addColumn('affine_id', 'string', [
                        'length' => 255,
                        'notnull' => false,
                    ]);
                    // Add unique constraint
                    $table->addUniqueIndex(['affine_id'], 'uniq_' . $tableName . '_affine_id');
                }
            }
        }
    }
}
