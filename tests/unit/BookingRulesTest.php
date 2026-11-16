<?php

use App\Services\AvailabilityService;
use App\Services\BookingRules;
use PHPUnit\Framework\TestCase;

final class BookingRulesTest extends TestCase
{
    public function testTenMinuteBoundariesAdvanceWindowAndMidnight(): void
    {
        $rules = new BookingRules();
        $now = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
        $input = ['date' => '2030-01-02', 'end_date' => '2030-01-02', 'start_time' => '09:00', 'end_time' => '09:10'];
        $this->assertSame(10, $rules->schedule($input, $now)['minutes']);
        $this->assertSame(480, $rules->schedule(array_replace($input, ['end_time' => '17:00']), $now)['minutes']);
        foreach ([['end_time' => '09:05'], ['start_time' => '09:05', 'end_time' => '09:15'], ['end_time' => '09:00'],
            ['end_time' => '08:50'], ['end_time' => '17:10'], ['date' => '2029-12-31'], ['date' => '2030-02-30'],
            ['date' => '2030-02-01', 'end_date' => '2030-02-01'], ['start_time' => ['array']],
            ['start_time' => "09:00\0"], ['date' => '2030-01-31', 'end_date' => '2030-01-31', 'start_time' => '08:10', 'end_time' => '08:20'],
        ] as $invalid) {
            try { $rules->schedule(array_replace($input, $invalid), $now); $this->fail('Invalid schedule accepted.'); }
            catch (DomainException $exception) { $this->assertNotSame('', $exception->getMessage()); }
        }
        $boundary = $rules->schedule(array_replace($input, ['date' => '2030-01-31', 'end_date' => '2030-01-31', 'start_time' => '08:00', 'end_time' => '08:10']), $now);
        $this->assertSame(10, $boundary['minutes']);
        $cross = $rules->schedule(array_replace($input, ['start_time' => '23:50', 'end_date' => '2030-01-03', 'end_time' => '00:10']), $now);
        $this->assertSame('2030-01-02 15:50:00', $cross['start_datetime']);
        $this->assertSame('2030-01-02 16:10:00', $cross['end_datetime']);
        $this->assertSame('2030-01-03 00:10', BookingRules::display($cross['end_datetime']));
    }

    public function testIntegerMoneyRoundingAndOverflow(): void
    {
        $this->assertSame(4000, BookingRules::fee('60.00', 20, 2));
        $this->assertSame('40.00', BookingRules::money(4000));
        $this->assertSame(1, BookingRules::fee('0.03', 10, 1));
        $this->assertSame(0, BookingRules::fee('0.01', 10, 1));
        $this->assertSame(0, BookingRules::fee('0.00', 480, 4294967295));
        $this->expectException(DomainException::class);
        BookingRules::fee('99999999.99', 480, 4294967295);
    }

    public function testEquipmentSweepReleasesBeforeAllocatingAtSameTime(): void
    {
        $rows = [
            ['start_datetime' => '2030-01-01 09:00:00', 'end_datetime' => '2030-01-01 10:00:00', 'quantity' => 2],
            ['start_datetime' => '2030-01-01 10:00:00', 'end_datetime' => '2030-01-01 11:00:00', 'quantity' => 2],
        ];
        $this->assertSame(2, AvailabilityService::peak($rows, '2030-01-01 09:30:00', '2030-01-01 10:30:00'));
        $rows[] = ['start_datetime' => '2030-01-01 09:40:00', 'end_datetime' => '2030-01-01 10:10:00', 'quantity' => 1];
        $this->assertSame(3, AvailabilityService::peak($rows, '2030-01-01 09:30:00', '2030-01-01 10:30:00'));
    }
}
