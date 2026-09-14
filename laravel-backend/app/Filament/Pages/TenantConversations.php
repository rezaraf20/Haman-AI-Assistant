<?php
namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Support\PlatformAccess;
use App\Support\PlatformActivity;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Lets platform staff read a customer's conversations — which is genuinely
 * necessary (the problem a customer is calling about is usually in one)
 * and genuinely sensitive (they contain real phone numbers and emails
 * customers gave a shop, not us).
 *
 * So access is allowed but conditional:
 *   - a reason must be chosen before anything is shown, and it is recorded
 *     with the conversation id, tenant and time;
 *   - phone numbers and emails render masked;
 *   - revealing one is a separate, deliberate click that writes its own
 *     audit entry.
 *
 * The masking is done server-side. Sending the real values to the browser
 * and hiding them with CSS would mean the page source still contained
 * every customer's phone number, which is not masking at all.
 */
class TenantConversations extends Page
{
    protected static string $view = 'filament.pages.tenant-conversations';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static bool $shouldRegisterNavigation = false;

    public ?string $tenantId = null;
    public ?string $conversationId = null;
    public ?string $reason = null;
    /** Conversation ids whose contacts this operator has explicitly revealed. */
    public array $revealed = [];

    public static function canAccess(): bool { return PlatformAccess::allows('conversations'); }

    public function getTitle(): string { return __('staff_conversations.title'); }

    public function mount(): void
    {
        $this->tenantId = request()->query('tenant');
        $this->conversationId = request()->query('conversation');
    }

    public function reasonOptions(): array
    {
        return [
            'ticket_review'    => __('staff_conversations.reason_ticket'),
            'error_report'     => __('staff_conversations.reason_error'),
            'customer_request' => __('staff_conversations.reason_customer'),
        ];
    }

    /** Nothing is read until a reason has been given. */
    public function setReason(string $reason): void
    {
        if (!in_array($reason, PlatformActivity::REASONS, true)) return;
        $this->reason = $reason;

        PlatformActivity::record(
            'conversation_list_opened',
            tenantId: $this->tenantId,
            subjectType: 'tenant',
            subjectId: $this->tenantId,
            reason: $reason,
        );
    }

    public function openConversation(string $conversationId): void
    {
        if (!$this->reason) return;
        $this->conversationId = $conversationId;

        PlatformActivity::record(
            'conversation_viewed',
            tenantId: $this->tenantId,
            subjectType: 'conversation',
            subjectId: $conversationId,
            reason: $this->reason,
        );
    }

    /** A deliberate, separately-recorded act. */
    public function reveal(string $conversationId): void
    {
        if (!$this->reason) return;
        if (!in_array($conversationId, $this->revealed, true)) {
            $this->revealed[] = $conversationId;
        }

        PlatformActivity::record(
            'contact_revealed',
            tenantId: $this->tenantId,
            subjectType: 'conversation',
            subjectId: $conversationId,
            reason: $this->reason,
        );
    }

    public function isRevealed(?string $conversationId): bool
    {
        return $conversationId !== null && in_array($conversationId, $this->revealed, true);
    }

    public function tenantName(): ?string
    {
        return $this->tenantId ? Tenant::find($this->tenantId)?->name : null;
    }

    /** Recent conversations for the tenant, once a reason has been given. */
    public function getConversations(): array
    {
        if (!$this->reason || !$this->tenantId) return [];

        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) return [];

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            return DB::table('conversations')
                ->select('id', 'session_id', 'status', 'started_at', 'message_count')
                ->orderByDesc('started_at')
                ->limit(50)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            return [];
        } finally {
            DB::statement('SET search_path TO public');
        }
    }

    /**
     * The transcript, with contact details masked unless this operator has
     * explicitly revealed them for this conversation.
     */
    public function getTranscript(): array
    {
        if (!$this->reason || !$this->conversationId || !$this->tenantId) return [];

        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) return [];

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            $messages = DB::table('messages')
                ->where('conversation_id', $this->conversationId)
                ->orderBy('created_at')
                ->limit(300)
                ->get(['role', 'content', 'created_at', 'is_unanswered'])
                ->all();
        } catch (\Throwable $e) {
            return [];
        } finally {
            DB::statement('SET search_path TO public');
        }

        if ($this->isRevealed($this->conversationId)) {
            return $messages;
        }

        foreach ($messages as $m) {
            $m->content = self::maskContacts((string) $m->content);
        }
        return $messages;
    }

    /**
     * Masks phone numbers and email addresses inside free text. Applied to
     * the string before it ever leaves the server.
     */
    public static function maskContacts(string $text): string
    {
        // Emails first: an address can contain digits that would otherwise
        // be partly eaten by the phone pattern.
        $text = preg_replace_callback(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            fn ($m) => PlatformActivity::mask($m[0]),
            $text
        ) ?? $text;

        // Iranian mobiles in any of the shapes customers actually type,
        // plus loose international.
        $text = preg_replace_callback(
            '/(?:\+98|0098|0)?9\d{9}|\+[1-9]\d{7,14}/',
            fn ($m) => PlatformActivity::mask($m[0]),
            $text
        ) ?? $text;

        return $text;
    }
}
