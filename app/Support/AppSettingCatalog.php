<?php

namespace App\Support;

use InvalidArgumentException;

class AppSettingCatalog
{
    /**
     * @return array<string, array{group:string,type:string,default:mixed,label:string,description:string,rules:array<int, mixed>,feature_flag?:bool}>
     */
    public static function definitions(): array
    {
        return [
            'sales_agenda.reminder_lead_minutes' => [
                'group' => 'sales_agenda',
                'type' => 'integer',
                'default' => 15,
                'label' => 'Jeda pengingat kegiatan',
                'description' => 'Menit sebelum kegiatan untuk mengirim pengingat (BR-06).',
                'rules' => ['integer', 'min:1', 'max:1440'],
            ],
            'sales_agenda.target_minutes' => self::timeInteger('Target menit agenda', 480, 1, 1440),
            'sales_agenda.weekday_start_time' => self::timeString('Jam masuk Senin–Kamis', '08:00'),
            'sales_agenda.weekday_end_time' => self::timeString('Jam pulang Senin–Kamis', '17:00'),
            'sales_agenda.weekday_break_start' => self::timeString('Mulai istirahat Senin–Kamis', '12:00'),
            'sales_agenda.weekday_break_end' => self::timeString('Selesai istirahat Senin–Kamis', '13:00'),
            'sales_agenda.friday_start_time' => self::timeString('Jam masuk Jumat', '07:30'),
            'sales_agenda.friday_end_time' => self::timeString('Jam pulang Jumat', '17:00'),
            'sales_agenda.friday_break_start' => self::timeString('Mulai istirahat Jumat', '11:30'),
            'sales_agenda.friday_break_end' => self::timeString('Selesai istirahat Jumat', '13:00'),
            'sales_monitoring.stale_lead_days' => [
                'group' => 'sales_monitoring',
                'type' => 'integer',
                'default' => 2,
                'label' => 'Batas Lead basi',
                'description' => 'Jumlah hari tanpa aktivitas sebelum Lead ditandai perlu perhatian.',
                'rules' => ['integer', 'min:1', 'max:14'],
            ],
            'org_hierarchical_monitoring' => self::featureFlag('Pemantauan graf organisasi'),
            'lead_stages_v3' => self::featureFlag('Tahap Lead v3'),
            'transaction_events_v3' => self::featureFlag('Event transaksi v3'),
        ];
    }

    /**
     * @return array{group:string,type:string,default:mixed,label:string,description:string,rules:array<int, mixed>,feature_flag?:bool}
     */
    public static function definition(string $key): array
    {
        $definition = self::definitions()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown application setting [{$key}].");
        }

        return $definition;
    }

    /**
     * @param  array<string, int|string>  $values
     * @return array<string, list<string>>
     */
    public static function calendarValidationErrors(array $values): array
    {
        $errors = [];
        $weekday = self::calendarRuleSet($values, 'weekday');
        $friday = self::calendarRuleSet($values, 'friday');

        foreach ([$weekday, $friday] as $ruleSet) {
            [$start, $breakStart, $breakEnd, $end] = array_map(
                static fn (string $key): int => self::minutes((string) $values[$key]),
                $ruleSet,
            );

            if (! ($start < $breakStart)) {
                self::addCalendarError($errors, $ruleSet[0], 'Jam masuk harus lebih awal dari mulai istirahat.');
                self::addCalendarError($errors, $ruleSet[1], 'Mulai istirahat harus setelah jam masuk.');
            }

            if (! ($breakStart < $breakEnd)) {
                self::addCalendarError($errors, $ruleSet[1], 'Mulai istirahat harus lebih awal dari selesai istirahat.');
                self::addCalendarError($errors, $ruleSet[2], 'Selesai istirahat harus setelah mulai istirahat.');
            }

            if (! ($breakEnd < $end)) {
                self::addCalendarError($errors, $ruleSet[2], 'Selesai istirahat harus lebih awal dari jam pulang.');
                self::addCalendarError($errors, $ruleSet[3], 'Jam pulang harus setelah selesai istirahat.');
            }

            $effectiveMinutes = ($end - $start) - ($breakEnd - $breakStart);
            if ((int) $values['sales_agenda.target_minutes'] > $effectiveMinutes) {
                self::addCalendarError(
                    $errors,
                    'sales_agenda.target_minutes',
                    "Target menit agenda tidak boleh melebihi menit kerja efektif ({$effectiveMinutes} menit).",
                );
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, int|string>  $values
     * @return array{0:string,1:string,2:string,3:string}
     */
    private static function calendarRuleSet(array $values, string $day): array
    {
        return $day === 'friday'
            ? ['sales_agenda.friday_start_time', 'sales_agenda.friday_break_start', 'sales_agenda.friday_break_end', 'sales_agenda.friday_end_time']
            : ['sales_agenda.weekday_start_time', 'sales_agenda.weekday_break_start', 'sales_agenda.weekday_break_end', 'sales_agenda.weekday_end_time'];
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    /** @param array<string, list<string>> $errors */
    private static function addCalendarError(array &$errors, string $key, string $message): void
    {
        $errors[$key][] = $message;
    }

    /**
     * @return array{group:string,type:string,default:bool,label:string,description:string,rules:array<int, string>,feature_flag:bool}
     */
    /**
     * @return array{group:string,type:string,default:int,label:string,description:string,rules:array<int, string>}
     */
    private static function timeInteger(string $label, int $default, int $min, int $max): array
    {
        return [
            'group' => 'sales_agenda',
            'type' => 'integer',
            'default' => $default,
            'label' => $label,
            'description' => 'Pengaturan kalender kerja Receipt/Agenda.',
            'rules' => ['integer', "min:{$min}", "max:{$max}"],
        ];
    }

    /**
     * @return array{group:string,type:string,default:string,label:string,description:string,rules:array<int, string>}
     */
    private static function timeString(string $label, string $default): array
    {
        return [
            'group' => 'sales_agenda',
            'type' => 'string',
            'default' => $default,
            'label' => $label,
            'description' => 'Format HH:MM untuk kalender kerja Receipt/Agenda.',
            'rules' => ['string', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
        ];
    }

    private static function featureFlag(string $label): array
    {
        return [
            'group' => 'feature_flags',
            'type' => 'boolean',
            'default' => false,
            'label' => $label,
            'description' => 'Feature flag PRD v3; bawaan mati untuk rollout aman.',
            'rules' => ['boolean'],
            'feature_flag' => true,
        ];
    }
}
