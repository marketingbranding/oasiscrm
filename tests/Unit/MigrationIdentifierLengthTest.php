<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class MigrationIdentifierLengthTest extends TestCase
{
    public function test_migration_index_and_foreign_key_names_fit_mysql_limit(): void
    {
        $violations = [];
        $foreignIdPattern = <<<'REGEX'
/\$table->foreignId\(['"]([^'"]+)['"]\)(?:->nullable\(\))?->constrained\((?:['"]([^'"]+)['"])?[^)]*\)/
REGEX;
        $explicitNamePattern = <<<'REGEX'
/->(?:index|unique|foreign)\([^;]*?,\s*['"]([^'"]+)['"]\s*\)/
REGEX;

        foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migration) {
            $source = file_get_contents($migration);

            preg_match_all($explicitNamePattern, $source, $explicitNames);
            foreach ($explicitNames[1] as $name) {
                if (strlen($name) > 64) {
                    $violations[] = basename($migration).': '.$name.' ('.strlen($name).')';
                }
            }

            preg_match_all($foreignIdPattern, $source, $foreignIds, PREG_SET_ORDER);
            foreach ($foreignIds as $foreignId) {
                $tableName = ($foreignId[2] ?? '') ?: Str::plural(str_replace('_id', '', $foreignId[1]));
                $name = $tableName.'_'.$foreignId[1].'_foreign';
                if (strlen($name) > 64) {
                    $violations[] = basename($migration).': '.$name.' ('.strlen($name).')';
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
