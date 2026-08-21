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
                foreach (['key', 'label', 'path', 'type', 'sortable', 'filterable', 'classification', 'data_type', 'editable', 'edit_type', 'validation', 'write_strategy', 'permission', 'scope_action', 'readonly_reason'] as $key) {
                    $this->assertArrayHasKey($key, $column);
                }
                $this->assertNotEmpty($column['key']);
                $this->assertNotEmpty($column['label']);
                $this->assertContains($column['classification'], ['simple_master', 'simple_application', 'relation_lifecycle', 'process_stage', 'derived_identifier']);
                $this->assertNotEmpty($column['data_type']);
                $this->assertIsBool($column['sortable']);
                $this->assertIsBool($column['filterable']);
                $this->assertIsBool($column['editable']);
                $this->assertSame('manage', $column['scope_action']);
                if (! $column['editable']) {
                    $this->assertNotEmpty($column['readonly_reason']);
                }
            }
        }
    }

    public function test_data_consumer_required_columns_and_native_column_letters(): void
    {
        $registry = new DatabaseModuleRegistry;

        $this->assertSame(['customer_name', 'phone', 'project', 'kavling', 'sales', 'consumer_status', 'current_stage', 'completeness', 'notes', 'status_cash'], $registry->get('data-konsumen')['default_columns']);
        $editable = collect($registry->get('data-konsumen')['columns'])->where('editable', true);
        $this->assertSame(['customer_name', 'phone', 'notes', 'status_cash'], $editable->pluck('key')->values()->all());
        $this->assertSame(['customer_field', 'customer_field', 'application_field', 'application_field'], $editable->pluck('write_strategy')->values()->all());
        $this->assertSame(['A', 'Z', 'AA'], [$registry->columnLetter(1), $registry->columnLetter(26), $registry->columnLetter(27)]);
    }

    public function test_unknown_module_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DatabaseModuleRegistry)->get('unknown');
    }
}
