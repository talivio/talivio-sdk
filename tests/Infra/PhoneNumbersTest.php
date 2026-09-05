<?php

namespace Talivio\Sdk\Tests\Infra;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Talivio\Sdk\Infra\Support\PhoneNumbers;

class PhoneNumbersTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_customer_typed_numbers_normalise_to_epp_form(string $input, string $country, string $expected): void
    {
        $this->assertSame($expected, PhoneNumbers::toEpp($input, $country));
    }

    public static function numbers(): array
    {
        return [
            'already epp' => ['+90.5551234567', 'TR', '+90.5551234567'],
            'spaces after code' => ['+90 555 123 45 67', 'TR', '+90.5551234567'],
            'dashes and parens' => ['+1 (555) 123-4567', 'US', '+1.5551234567'],
            'e164 run of digits, country splits it' => ['+905551234567', 'tr', '+90.5551234567'],
            'e164 for a one-digit code' => ['+15551234567', 'US', '+1.5551234567'],
            'e164 for a three-digit code' => ['+372555', 'ee', '+372.555'],
            'national with trunk zero' => ['0555 123 45 67', 'TR', '+90.5551234567'],
            'national without trunk zero' => ['5551234', 'EE', '+372.5551234'],
        ];
    }

    public function test_an_international_number_whose_code_does_not_match_the_country_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        // +44 digits, but the registrant says Türkiye — can't tell where
        // the code ends without a separator.
        PhoneNumbers::toEpp('+445551234567', 'TR');
    }

    public function test_a_national_number_for_an_unknown_country_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        PhoneNumbers::toEpp('5551234', 'ZZ');
    }

    public function test_an_empty_number_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        PhoneNumbers::toEpp('   ', 'TR');
    }
}
