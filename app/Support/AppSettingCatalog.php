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
            'work_hours_v3' => self::featureFlag('Jam kerja v3'),
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
     * @return array{group:string,type:string,default:bool,label:string,description:string,rules:array<int, string>,feature_flag:bool}
     */
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
