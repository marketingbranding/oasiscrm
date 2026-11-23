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
            'consumer_parity.cutover_freshness_hours' => [
                'group' => 'consumer_parity',
                'type' => 'integer',
                'default' => 24,
                'label' => 'Batas kesegaran paritas cutover',
                'description' => 'Jam maksimum usia snapshot paritas sebelum cutover oasis_penuh.',
                'rules' => ['integer', 'min:1', 'max:168'],
            ],
            'consumer_parity.parallel_min_days' => [
                'group' => 'consumer_parity',
                'type' => 'integer',
                'default' => 14,
                'label' => 'Masa paralel minimum',
                'description' => 'Hari minimum cabang berada dalam status paralel sebelum cutover oasis_penuh.',
                'rules' => ['integer', 'min:0', 'max:90'],
            ],
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
            'sales_monitoring.daily_plan_cutoff_time' => [
                'group' => 'sales_monitoring',
                'type' => 'string',
                'default' => '10:00',
                'label' => 'Batas penanda Rencana Harian',
                'description' => 'Jam mulai penanda Rencana Harian belum dikirim pada hari kerja.',
                'rules' => ['string', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            ],
            'access_grants.max_days' => [
                'group' => 'access_grants',
                'type' => 'integer',
                'default' => 90,
                'label' => 'Maksimal masa Izin Akses Khusus',
                'description' => 'Batas masa berlaku grant akses khusus dalam hari (BR-53).',
                'rules' => ['integer', 'min:1', 'max:365'],
            ],
            'sales_lead.transfer_reasons' => [
                'group' => 'sales_lead',
                'type' => 'list',
                'default' => [
                    'Tidak dapat dihubungi',
                    'Penyesuaian pembagian tim',
                    'Permintaan konsumen',
                    'Lainnya',
                ],
                'label' => 'Alasan Alih Lead',
                'description' => 'Daftar alasan yang sah saat Lead dialihkan (BR-54).',
                'rules' => ['array', 'min:1'],
            ],
            'sales_lead.gugur_reasons' => [
                'group' => 'sales_lead',
                'type' => 'list',
                'default' => [
                    'Tidak berminat',
                    'Tidak bisa dihubungi',
                    'Tidak lolos syarat subsidi',
                    'Pindah ke developer lain',
                    'Lainnya',
                ],
                'label' => 'Alasan Gugur',
                'description' => 'Satu alasan per baris untuk menutup Lead sebagai Gugur (BR-13).',
                'rules' => ['array', 'min:1'],
            ],
            'consumer_progress.mundur_reasons' => [
                'group' => 'consumer_progress',
                'type' => 'list',
                'default' => [
                    'Mundur atas permintaan konsumen',
                    'Reject SLIK',
                    'Reject Bank',
                    'Lainnya',
                ],
                'label' => 'Alasan Mundur',
                'description' => 'Daftar alasan yang sah saat transaksi dimundurkan (BR-31).',
                'rules' => ['array', 'min:1'],
            ],
            'sales_lead.sales_max_edit_stage' => [
                'group' => 'sales_lead',
                'type' => 'string',
                'default' => 'utj',
                'label' => 'Batas tahap ubah oleh Sales',
                'description' => 'Tahap terakhir yang masih dapat diubah Sales (BR-14).',
                'rules' => ['string', 'in:lead_baru,dihubungi,tatap_muka,survey,utj'],
            ],
            'org_hierarchical_monitoring' => self::featureFlag('Pemantauan graf organisasi'),
            'lead_stages_v3' => self::scopedFeatureFlag('Tahap Lead v3'),
            'transaction_events_v3' => self::scopedFeatureFlag('Event transaksi v3'),
            'ai.send_names' => [
                'group' => 'ai',
                'type' => 'boolean',
                'default' => true,
                'label' => 'Kirim nama ke Asisten AI',
                'description' => 'Nama boleh dikirim ke penyedia AI; data pribadi lain tetap disaring (BR-70).',
                'rules' => ['boolean'],
            ],
            'backup.database_retention_days' => [
                'group' => 'backup',
                'type' => 'integer',
                'default' => 7,
                'label' => 'Retensi backup database',
                'description' => 'Jumlah hari backup database disimpan.',
                'rules' => ['integer', 'min:1', 'max:3650'],
            ],
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

        if (isset($values['sales_monitoring.daily_plan_cutoff_time'])) {
            $cutoff = self::minutes((string) $values['sales_monitoring.daily_plan_cutoff_time']);
            foreach (['weekday', 'friday'] as $day) {
                $ruleSet = self::calendarRuleSet($values, $day);
                if ($cutoff < self::minutes((string) $values[$ruleSet[0]])) {
                    self::addCalendarError($errors, 'sales_monitoring.daily_plan_cutoff_time', 'Batas penanda tidak boleh sebelum jam masuk.');
                }
                if ($cutoff > self::minutes((string) $values[$ruleSet[3]])) {
                    self::addCalendarError($errors, 'sales_monitoring.daily_plan_cutoff_time', 'Batas penanda tidak boleh setelah jam pulang.');
                }
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

    private static function scopedFeatureFlag(string $label): array
    {
        return [
            'group' => 'feature_flags',
            'type' => 'scoped_feature_flag',
            'default' => ['mode' => 'off', 'branch_ids' => []],
            'label' => $label,
            'description' => 'Feature flag PRD v3; rollout per cabang, bawaan mati.',
            'rules' => ['array'],
            'feature_flag' => true,
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
