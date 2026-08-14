<?php

namespace Tests\Unit;

use App\Services\PromoTsvParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PromoTsvParserTest extends TestCase
{
    #[DataProvider('dates')]
    public function test_parses_header_or_fixed_order_and_strict_dates(string $date, string $expected): void
    {
        $rows = (new PromoTsvParser)->parse("id_promo\tnama_promo\ttanggal_mulai\ttanggal_selesai\tketerangan\r\nP1\tPromo 1\t{$date}\t\tCatatan");
        $this->assertSame('Baru', $rows[0]['status']);
        $this->assertSame($expected, $rows[0]['normalized_data']['start_date']);
        $this->assertSame(2, $rows[0]['line_number']);

        $withoutHeader = (new PromoTsvParser)->parse("P2\tPromo 2\t{$date}\t\t");
        $this->assertSame(1, $withoutHeader[0]['line_number']);
    }

    public static function dates(): array
    {
        return [['1/1/2026', '2026-01-01'], ['01/01/2026', '2026-01-01'], ['2026-01-01', '2026-01-01']];
    }

    public function test_marks_every_duplicate_and_invalid_date_order(): void
    {
        $rows = (new PromoTsvParser)->parse("P1\tPromo\t31/2/2026\t2026-01-01\t\nP1\tPromo lain\t2026-02-01\t2026-01-01\t");
        $this->assertSame(['Duplikat Input', 'Duplikat Input'], array_column($rows, 'status'));
        $this->assertStringContainsString('duplikat', implode(' ', $rows[0]['errors']));
        $this->assertStringContainsString('setelah', implode(' ', $rows[1]['errors']));
    }

    public function test_rejects_near_header_and_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PromoTsvParser)->parse("id_promo\tnama_promo\ttanggal_mulai\tketerangan\nP1\tPromo\t\t");
    }
}
