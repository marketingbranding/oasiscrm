<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\User;
use App\Models\UserPresence;
use Illuminate\Database\Eloquent\Model;

class PresenceService
{
    public function clearEditing(User $user, Model $record, ?string $sessionKey): int
    {
        if (! is_string($sessionKey) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $sessionKey)) {
            return 0;
        }

        return UserPresence::where('user_id', $user->id)
            ->where('session_key', $sessionKey)
            ->where('record_type', $this->recordType($record))
            ->where('record_id', $record->getKey())
            ->delete();
    }

    private function recordType(Model $record): string
    {
        return match (true) {
            $record instanceof DanaTalangan => 'dana_talangan',
            $record instanceof ContentItem => 'content_item',
            $record instanceof DatabaseSheetRecord => 'database_sheet_record',
            default => class_basename($record),
        };
    }
}
