<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalCommentsInteractionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_text_rendering_limits_and_permission_driven_controls_are_source_safe(): void
    {
        $panel = file_get_contents(resource_path('views/components/comments/panel.blade.php'));

        $this->assertStringNotContainsString('x-html', $panel);
        foreach (['comment.body', 'reply.body', 'revision.previous_body'] as $binding) {
            $this->assertStringContainsString('x-text="'.$binding.'"', $panel);
        }
        $this->assertGreaterThanOrEqual(4, substr_count($panel, 'maxlength="5000"'));
        foreach (['comment.can_reply', 'comment.can_update', 'comment.can_delete', 'comment.can_moderate', 'comment.can_restore'] as $capability) {
            $this->assertStringContainsString($capability, $panel);
        }
        $this->assertStringContainsString("editing: { id: null, body: '', mentions: [], lock_version: 0 }", file_get_contents(resource_path('js/comments.js')));
    }

    public function test_mention_keyboard_caret_ime_debounce_and_abort_contracts_are_present(): void
    {
        $script = file_get_contents(resource_path('js/comments.js'));

        foreach (['ArrowDown', 'ArrowUp', 'Enter', 'Escape'] as $key) {
            $this->assertStringContainsString("event.key === '{$key}'", $script);
        }
        $this->assertStringContainsString('event.isComposing', $script);
        $this->assertMatchesRegularExpression('/mentionKey\(event\)\s*\{\s*if \(event\.isComposing/s', $script);
        $this->assertStringContainsString('event.target.selectionStart', $script);
        $this->assertStringContainsString('setSelectionRange(caret, caret)', $script);
        $this->assertStringContainsString('window.setTimeout(() => this.searchMentions(match[1]), 250)', $script);
        $this->assertStringContainsString('this.mentionRequest?.abort()', $script);
        $this->assertStringContainsString("error.name !== 'AbortError'", $script);
    }

    public function test_edit_and_conflict_flows_never_submit_a_missing_model_and_keep_form_context(): void
    {
        $script = file_get_contents(resource_path('js/comments.js'));
        $panel = file_get_contents(resource_path('views/components/comments/panel.blade.php'));

        $this->assertStringContainsString('if (!comment', $script);
        $this->assertStringContainsString('context: { form, reload, reloadLabel:', $script);
        $this->assertStringContainsString("reloadLabel: 'Muat Ulang Komentar'", $script);
        $this->assertStringContainsString('data-comment-edit-form', $panel);
        $this->assertStringContainsString('expected_lock_version', $panel);
    }

    public function test_mobile_wrapping_module_accents_and_dialog_semantics_remain_explicit(): void
    {
        $panel = file_get_contents(resource_path('views/components/comments/panel.blade.php'));
        $thread = file_get_contents(app_path('Http/Controllers/Crm/CommentThreadController.php'));

        $this->assertStringContainsString('flex-wrap', $panel);
        $this->assertStringContainsString('break-words', $panel);
        $this->assertStringContainsString('sm:pl-12', $panel);
        $this->assertSame(2, substr_count($panel, 'role="dialog"'));
        $this->assertSame(2, substr_count($panel, 'aria-modal="true"'));
        $this->assertStringContainsString('@keydown.escape.window="closeHistory()"', $panel);
        $this->assertStringContainsString('@keydown.escape.window="closeModeration()"', $panel);
        foreach (['#fcc20f', '#b3bd95', '#f1c40f'] as $accent) {
            $this->assertStringContainsString($accent, $thread);
        }
    }
}
