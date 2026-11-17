<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddResourceCatalogAndConflicts extends Migration
{
    public function up()
    {
        if (!$this->forge->addColumn('resources', [
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => 'General',
            ],
            'catalog_group' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'inventory_identifier' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'conflict_group' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],
            'conflict_group_alt' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],
        ])) {
            throw new RuntimeException('Unable to add resource catalog and conflict columns.');
        }
        $this->forge->addUniqueKey('inventory_identifier', 'resource_inventory_identifier');
        $this->forge->addKey(['resource_type', 'catalog_group', 'category', 'rate_per_hour'], false, false, 'resource_catalog_group');
        $this->forge->addKey('conflict_group', false, false, 'resource_conflict_group');
        $this->forge->addKey('conflict_group_alt', false, false, 'resource_conflict_group_alt');
        if (!$this->forge->processIndexes('resources')) {
            throw new RuntimeException('Unable to add resource catalog and conflict indexes.');
        }
    }

    public function down()
    {
        $this->forge->dropKey('resources', 'resource_inventory_identifier');
        $this->forge->dropKey('resources', 'resource_catalog_group');
        $this->forge->dropKey('resources', 'resource_conflict_group');
        $this->forge->dropKey('resources', 'resource_conflict_group_alt');
        $this->forge->dropColumn('resources', [
            'category',
            'catalog_group',
            'inventory_identifier',
            'conflict_group',
            'conflict_group_alt',
        ]);
    }
}
