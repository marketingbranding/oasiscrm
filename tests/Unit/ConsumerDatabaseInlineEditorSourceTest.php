<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConsumerDatabaseInlineEditorSourceTest extends TestCase
{
    public function test_customer_write_locks_customer_before_ordered_applications(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/ConsumerDatabaseWriteService.php');
        $customerLock = strpos($source, 'Customer::query()->lockForUpdate()->find($customerId)');
        $applicationLocks = strpos($source, 'where(\'customer_id\', $customerId)->orderBy(\'id\')->lockForUpdate()->get()');

        $this->assertIsInt($customerLock);
        $this->assertIsInt($applicationLocks);
        $this->assertLessThan($applicationLocks, $customerLock);
        $this->assertStringContainsString('firstWhere(\'id\', $applicationId)', $source);
        $this->assertStringContainsString('ConsumerApplication::query()->lockForUpdate()->findOrFail($applicationId)', $source);
    }

    public function test_inline_editor_source_contract(): void
    {
        $source = file_get_contents(__DIR__.'/../../resources/js/consumer-database-inline-editor.js');
        $app = file_get_contents(__DIR__.'/../../resources/js/app.js');
        $blade = file_get_contents(__DIR__.'/../../resources/views/crm/consumer-database/index.blade.php');

        $this->assertStringContainsString("'X-CSRF-TOKEN'", $source);
        foreach (['response.status === 409', '[422, 403].includes(response.status)', 'response.status === 403', 'errors', 'oasis-conflict', "method: 'PATCH'", 'Enter', 'Escape'] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
        $this->assertStringContainsString("event.target.tagName !== 'TEXTAREA'", $source);
        $this->assertStringContainsString("event.key === 'Escape'", $source);
        $this->assertStringContainsString('@elseif($column[\'edit_type\'] === \'number\')', $blade);
        $this->assertStringContainsString('type="number"', $blade);
        $this->assertStringContainsString('@elseif($column[\'edit_type\'] === \'date\')', $blade);
        $this->assertStringContainsString('type="date"', $blade);
        $this->assertStringNotContainsString('contenteditable', strtolower($source));
        $this->assertStringContainsString('registerConsumerDatabaseInlineEditor(Alpine)', $app);
    }
}
