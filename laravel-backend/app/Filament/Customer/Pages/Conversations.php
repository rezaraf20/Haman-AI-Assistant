<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Exists so a suggestion can be checked. doc-07 requires every suggestion
 * to link to the conversations it came from, and a merchant being told
 * "31 people asked this" must be able to go and read those 31 chats —
 * otherwise the number is just an assertion.
 *
 * Deliberately reachable only with a conversation id (it is not a browse-
 * everything inbox), and the id is always re-checked against this
 * tenant's own schema, so a guessed UUID from another tenant resolves to
 * nothing.
 */
class Conversations extends Page
{
    protected static string $view = 'filament.customer.pages.conversations';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static bool $shouldRegisterNavigation = false;

    public ?string $conversationId = null;

    public static function getNavigationLabel(): string { return __('suggestions.conversation_nav'); }
    public function getTitle(): string { return __('suggestions.conversation_nav'); }

    public function mount(): void
    {
        $id = (string) request()->query('id', '');
        // Anything that isn't a UUID never reaches a query.
        $this->conversationId = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)
            ? $id : null;
    }

    public function getTranscript(): array
    {
        if (!$this->conversationId) return [];

        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            $conversation = DB::table('conversations')->where('id', $this->conversationId)->first();
            if (!$conversation) return [];

            $messages = DB::table('messages')
                ->where('conversation_id', $this->conversationId)
                ->orderBy('created_at')
                ->limit(200)
                ->get(['role', 'content', 'created_at', 'is_unanswered']);
        } catch (\Throwable $e) {
            return [];
        } finally {
            DB::statement('SET search_path TO public');
        }

        return [
            'conversation' => $conversation,
            'messages'     => $messages->all(),
        ];
    }
}
