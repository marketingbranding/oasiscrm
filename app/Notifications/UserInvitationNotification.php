<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $rawToken,
        private readonly UserInvitation $invitation,
        private readonly ?User $inviter,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $support = config('mail.support_address', config('mail.from.address'));

        return (new MailMessage)
            ->subject('Aktifkan akun OASIS Anda')
            ->greeting("Halo {$notifiable->name},")
            ->line('Anda diundang untuk menggunakan OASIS CRM'.($this->inviter ? " oleh {$this->inviter->name}." : '.'))
            ->action('Aktifkan Akun OASIS', route('invitations.show', ['token' => $this->rawToken]))
            ->line('Tautan ini hanya dapat digunakan satu kali dan berlaku sampai '.$this->invitation->expires_at->timezone(config('app.timezone'))->format('d M Y H:i').'.')
            ->line("Jika Anda tidak mengenali undangan ini, abaikan email ini atau hubungi {$support}.")
            ->salutation('Tim OASIS');
    }
}
