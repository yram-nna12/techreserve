<?php

namespace App\Services;

use Config\Booking;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

class BookingRules
{
    public const MAX_CENTS = 9999999999;

    public function __construct(private ?Booking $config = null)
    {
        $this->config ??= config('Booking');
    }

    public function schedule(array $input, ?DateTimeImmutable $now = null): array
    {
        $start = $this->local($input['date'] ?? null, $input['start_time'] ?? null);
        $end = $this->local($input['end_date'] ?? ($input['date'] ?? null), $input['end_time'] ?? null);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ((int) $start->format('i') % $this->config->incrementMinutes !== 0
            || (int) $end->format('i') % $this->config->incrementMinutes !== 0) {
            throw new DomainException('Start and end times must align with ' . $this->config->incrementMinutes . '-minute clock intervals.');
        }
        if ($seconds < $this->config->minimumMinutes * 60 || $seconds > $this->config->maximumMinutes * 60) {
            throw new DomainException('Reservation duration must be between ' . $this->config->minimumMinutes . ' and ' . $this->config->maximumMinutes . ' minutes.');
        }
        if ($start <= $now || $start > $now->modify('+' . $this->config->advanceDays . ' days')) {
            throw new DomainException('Start must be in the future and no more than ' . $this->config->advanceDays . ' days ahead.');
        }
        return [
            'start_datetime' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_datetime' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'minutes' => intdiv($seconds, 60),
        ];
    }

    private function local(mixed $date, mixed $time): DateTimeImmutable
    {
        if (!is_string($date) || !is_string($time)
            || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
            || !preg_match('/\A[0-9]{2}:[0-9]{2}\z/', $time)) {
            throw new DomainException('Enter valid reservation dates and times.');
        }
        $text = $date . ' ' . $time;
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, new DateTimeZone($this->config->timezone));
        if (!$value || $value->format('Y-m-d H:i') !== $text) {
            throw new DomainException('Enter valid reservation dates and times.');
        }
        return $value;
    }

    public static function display(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(config('Booking')->timezone))->format('Y-m-d H:i');
    }

    public static function fee(string $rate, int $minutes, int $quantity): int
    {
        if (!preg_match('/\A([0-9]{1,8})\.([0-9]{2})\z/', $rate, $match) || $minutes < 1 || $quantity < 1 || $quantity > 4294967295) {
            throw new DomainException('Invalid rate, duration, or quantity.');
        }
        $cents = (int) $match[1] * 100 + (int) $match[2];
        $factor = $minutes * $quantity;
        // Check before multiplying so neither PHP integers nor DECIMAL(10,2) can overflow.
        if ($cents > intdiv(self::MAX_CENTS * 60 + 29, $factor)) {
            throw new DomainException('The calculated fee exceeds the supported amount.');
        }
        return intdiv($cents * $factor + 30, 60);
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function key(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
