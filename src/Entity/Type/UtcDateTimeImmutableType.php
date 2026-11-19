<?php

declare(strict_types=1);

namespace App\Entity\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * A date and time, stored in UTC and read in the timezone of the instance
 * (the one of PHP), which only says how dates are shown: changing it moves
 * nothing already written.
 *
 * It replaces `datetime_immutable` for every table (config/packages/doctrine.yaml),
 * the ones of Messenger included. A column holds no offset, so this is the one
 * place that knows which it is: a date read by hand from SQL goes through
 * `Connection::convertToPHPValue()`.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $date = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value, self::utc());
        if (false === $date) {
            try {
                $date = new \DateTimeImmutable($value, self::utc());
            } catch (\Exception $e) {
                throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString(), $e);
            }
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
