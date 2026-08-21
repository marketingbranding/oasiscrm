<?php

namespace Tests\Unit;

use App\Services\DatabaseModuleRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DatabaseModuleRegistryTest extends TestCase
{
    public function test_registry_has_exact_modules_and_metadata(): void
    {
        $registry = new DatabaseModuleRegistry;

        $this->assertSame(['data-konsumen', 'bi-checking', 'psjb', 'pemberkasan', 'proses-bank', 'ppjb', 'akad', 'bast'], $registry->slugs());
        foreach ($registry->all() as $key => $module) {
            $this->assertSame($key, $module['key']);
            $this->assertNotEmpty($module['label']);
            $this->assertNotEmpty($module['description']);
            $this->assertSame(array_column($module['columns'], 'key'), $module['default_columns']);
            foreach ($module['columns'] as $column) {
                $this->assertSame(['key', 'label', 'path', 'type', 'sortable', 'filterable', 'data_type'], array_keys($column));
                $this->assertNotEmpty($column['key']);
                $this->assertNotEmpty($column['label']);
                $this->assertNotEmpty($column['data_type']);
                $this->assertIsBool($column['sortable']);
                $this->assertIsBool($column['filterable']);
            }
        }
    }

    public function test_data_consumer_required_columns_and_native_column_letters(): void
    {
        $registry = new DatabaseModuleRegistry;

        $this->assertSame(['customer_name', 'phone', 'project', 'kavling', 'sales', 'consumer_status', 'current_stage', 'completeness'], $registry->get('data-konsumen')['default_columns']);
        $this->assertSame(['A', 'Z', 'AA'], [$registry->columnLetter(1), $registry->columnLetter(26), $registry->columnLetter(27)]);
    }

    public function test_unknown_module_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DatabaseModuleRegistry)->get('unknown');
    }
}
