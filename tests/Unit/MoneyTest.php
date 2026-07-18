<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_format_idr_usd_dan_kode_lain(): void
    {
        $this->assertSame('Rp 1.234.567', Money::format(1_234_567, 'IDR'));
        $this->assertSame('$1,234,567', Money::format(1_234_567, 'USD'));
        $this->assertSame('SGD 1.234.567', Money::format(1_234_567, 'sgd'));
    }

    public function test_symbol(): void
    {
        $this->assertSame('Rp', Money::symbol('IDR'));
        $this->assertSame('$', Money::symbol('USD'));
        $this->assertSame('EUR', Money::symbol('eur'));
    }

    public function test_excel_format(): void
    {
        $this->assertSame('"Rp" #,##0', Money::excelFormat('IDR'));
        $this->assertSame('"$"#,##0', Money::excelFormat('USD'));
        $this->assertSame('"SGD" #,##0', Money::excelFormat('SGD'));
    }
}
