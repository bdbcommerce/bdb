<?php

namespace Tests\Feature;

use App\Services\Invoices\InvoiceParser;
use Tests\TestCase;

class InvoiceFileNameTest extends TestCase
{
    public function test_invoice_file_names(): void
    {
        foreach (['Счет №125.pdf', 'счет.pdf', 'СЧЕТ 5.PDF', 'Счёт-фактура.pdf', ' "Счет 1".pdf', '«Счет»', 'Cчет.pdf' /* Latin C */, 'Счет_на_оплату.png', 'Счёт №5.pdf', 'СЧЁТ.pdf', ' Счет.pdf', '​Счет.pdf', '﻿Счет.pdf', '‎Счет.pdf', '(Счет).pdf'] as $name) {
            $this->assertTrue(InvoiceParser::isInvoiceFileName($name), $name);
        }
        foreach ([null, '', 'Договор.pdf', 'Invoice.pdf', 'Акт и Счет.pdf', 'Сче.pdf', 'scan.pdf', ['Счет.pdf'], 5, '��Счет', 'Сч ет.pdf'] as $name) {
            $this->assertFalse(InvoiceParser::isInvoiceFileName($name), json_encode($name, JSON_UNESCAPED_UNICODE));
        }
    }
}
