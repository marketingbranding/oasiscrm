<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CoordinatorAgendaReminderService
{
    public function __construct(private readonly CoordinatorLeadTeamService $teams) {}

    /**
     * @param  array<int, int>  $salesIds
     * @return array{sent: int, needs_confirmation: array<int, int>}
     */
    public function send(User $coordinator, array $salesIds, bool $confirmRecent = false): array
    {
        abort_unless($this->teams->isCoordinator($coordinator)
            && $coordinator->hasPermission('sales_pocketbook.manage_team'), 403);

        $salesIds = array_values(array_unique(array_map('intval', $salesIds)));
        $teamIds = $this->teams->currentSalesQuery($coordinator)->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        if ($salesIds === [] || array_diff($salesIds, $teamIds) !== []) {
            throw new HttpException(403, 'Pilih Sales aktif dalam tim Anda.');
        }

        $recentIds = UserNotification::query()
            ->whereIn('user_id', $salesIds)
            ->where('type', 'coordinator_agenda_reminder')
            ->where('created_at', '>=', now()->subHours(24))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $needsConfirmation = $confirmRecent ? [] : $recentIds;
        $recipientIds = $confirmRecent ? $salesIds : array_values(array_diff($salesIds, $recentIds));
        $recipients = User::query()->whereIn('id', $recipientIds)->get();

        foreach ($recipients as $sales) {
            UserNotification::create([
                'user_id' => $sales->id,
                'type' => 'coordinator_agenda_reminder',
                'title' => 'Pengingat pembaruan agenda',
                'message' => 'Koordinator mengingatkan Anda untuk melengkapi hasil atau bukti agenda minggu ini.',
                'action_url' => route('sales-agendas.index'),
                'actor_user_id' => $coordinator->id,
                'data' => ['coordinator_user_id' => $coordinator->id],
            ]);
        }

        return ['sent' => $recipients->count(), 'needs_confirmation' => $needsConfirmation];
    }
}
