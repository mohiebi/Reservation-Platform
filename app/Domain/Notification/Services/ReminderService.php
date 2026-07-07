<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\Reminder;
use App\Domain\Reservation\Models\Reservation;

class ReminderService
{
    public function scheduleForReservation(Reservation $reservation): void
    {
        foreach ([24 * 60 => '24_hour', 2 * 60 => '2_hour'] as $minutesBefore => $type) {
            $scheduledFor = $reservation->starts_at->copy()->subMinutes($minutesBefore);

            if ($scheduledFor->isFuture()) {
                Reminder::query()->firstOrCreate([
                    'reservation_id' => $reservation->id,
                    'type' => $type,
                ], [
                    'scheduled_for' => $scheduledFor,
                    'status' => 'pending',
                ]);
            }
        }
    }
}
