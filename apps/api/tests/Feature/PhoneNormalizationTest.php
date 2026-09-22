<?php

namespace Tests\Feature;

use App\Identity\Phone\InvalidPhoneNumber;
use App\Identity\Phone\PakistaniMobileNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNormalizationTest extends TestCase
{
    private PakistaniMobileNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new PakistaniMobileNormalizer;
    }

    #[DataProvider('acceptedNumbers')]
    public function test_pakistani_mobile_numbers_normalize_to_e164(string $input): void
    {
        $this->assertSame('+923001234567', $this->normalizer->normalize($input));
    }

    public function test_landline_and_foreign_numbers_are_rejected(): void
    {
        $this->expectException(InvalidPhoneNumber::class);
        $this->normalizer->normalize('+924235764444');
    }

    public function test_foreign_mobile_numbers_are_rejected(): void
    {
        $this->expectException(InvalidPhoneNumber::class);
        $this->normalizer->normalize('+14155552671');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function acceptedNumbers(): array
    {
        return [
            ['03001234567'],
            ['923001234567'],
            ['+923001234567'],
            ['0300 123 4567'],
            ['0300-1234567'],
        ];
    }
}
