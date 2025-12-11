<?php

namespace App\Service;

/**
 * Helper service for date/time manipulations
 * Centralizes common date operations to reduce code duplication
 */
class DateTimeHelper
{
    /**
     * Get start of day (00:00:00) for a date
     */
    public static function startOfDay(\DateTimeInterface $date): \DateTime
    {
        $result = $date instanceof \DateTime ? clone $date : new \DateTime($date->format('Y-m-d H:i:s'));
        $result->setTime(0, 0, 0);
        return $result;
    }

    /**
     * Get end of day (23:59:59) for a date
     */
    public static function endOfDay(\DateTimeInterface $date): \DateTime
    {
        $result = $date instanceof \DateTime ? clone $date : new \DateTime($date->format('Y-m-d H:i:s'));
        $result->setTime(23, 59, 59);
        return $result;
    }

    /**
     * Convert time to minutes since midnight
     */
    public static function timeToMinutes(\DateTimeInterface $time): int
    {
        return (int) $time->format('H') * 60 + (int) $time->format('i');
    }

    /**
     * Get today's date
     */
    public static function today(): \DateTime
    {
        return new \DateTime();
    }

    /**
     * Get yesterday's date
     */
    public static function yesterday(): \DateTime
    {
        return new \DateTime('-1 day');
    }

    /**
     * Create DateTime from local time string with timezone offset
     *
     * @param string $localTime Format 'Y-m-d H:i'
     * @param int $tzOffsetMinutes Timezone offset in minutes
     * @return \DateTime
     */
    public static function fromLocalTime(string $localTime, int $tzOffsetMinutes): \DateTime
    {
        // Create DateTime in UTC
        $date = new \DateTime($localTime, new \DateTimeZone('UTC'));

        // Adjust for timezone offset (browser sends offset as minutes)
        $date->modify("-{$tzOffsetMinutes} minutes");

        return $date;
    }

    /**
     * Format duration in minutes to human readable string
     *
     * @param int $minutes Duration in minutes
     * @return string "Xh Ymin" or "Ymin"
     */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}min";
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return "{$hours}h";
        }

        return "{$hours}h {$mins}min";
    }
}
