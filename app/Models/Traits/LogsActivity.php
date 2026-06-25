<?php

namespace App\Models\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->logActivity('created');
        });

        static::updated(function ($model) {
            $model->logActivity('updated');
        });

        static::deleted(function ($model) {
            $model->logActivity('deleted');
        });
    }

    public function logActivity(string $event, ?array $extra = []): void
    {
        $description = $this->activityDescription($event);
        $properties = array_merge($this->activityProperties($event), $extra);

        ActivityLog::create([
            'causer_id' => Auth::id(),
            'subject_type' => static::class,
            'subject_id' => $this->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
        ]);
    }

    protected function activityDescription(string $event): string
    {
        $name = class_basename(static::class);
        $label = $this->activityLabel();
        return match ($event) {
            'created' => "{$label} dibuat",
            'updated' => "{$label} diperbarui",
            'deleted' => "{$label} dihapus",
            default => "{$label} {$event}",
        };
    }

    protected function activityLabel(): string
    {
        return class_basename(static::class);
    }

    protected function activityProperties(string $event): array
    {
        $props = [];

        if ($event === 'updated' && $this->getOriginal()) {
            $changed = [];
            foreach ($this->getDirty() as $key => $value) {
                $changed[$key] = [
                    'old' => $this->getOriginal($key),
                    'new' => $value,
                ];
            }
            $props['changed'] = $changed;
        }

        if ($event === 'created') {
            $props['attributes'] = $this->toArray();
        }

        return $props;
    }

    public function activities()
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }
}
