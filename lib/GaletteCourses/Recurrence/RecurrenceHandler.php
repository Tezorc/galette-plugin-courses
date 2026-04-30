<?php

/**
 * Copyright © 2026-2026 The Galette Team && The CCAG42 Team
 *
 * This file is part of Galette Courses plugin (https://github.com/Tezorc/galette-plugin-courses).
 *
 * Galette Courses Plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Galette Courses Plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Galette Courses Plugin. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace GaletteCourses\Recurrence;

use Galette\Core\Db;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\PluginPreferences;
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class RecurrenceHandler
{
    public function __construct(
        private Db $zdb,
        private ?PluginPreferences $pluginPrefs = null
    ) {
    }

    /**
     * Generate recurring sessions for an event.
     *
     * If $startDate is provided (first creation), generates from that date.
     * Otherwise, continues from the last existing session.
     *
     * @param Event       $event     The recurring event
     * @param string|null $startDate Start date (yyyy-mm-dd) for first generation
     * @return Session[] Newly created sessions
     */
    public function generateSessions(Event $event, ?string $startDate = null): array
    {
        if (!$event->isRecurring() || $event->getId() === null) {
            return [];
        }

        $event->loadSlots();
        $slots = $event->getSlots();
        $firstSlot = $slots[0] ?? null;
        $startTime = $firstSlot ? $firstSlot['start_time'] : '09:00';
        $endTime = $firstSlot ? $firstSlot['end_time'] : '10:00';

        // Determine start date
        if ($startDate === null) {
            $startDate = $this->getNextStartDate($event);
            if ($startDate === null) {
                Analog::log(
                    'Cannot generate sessions for event #' . $event->getId() . ': no start date and no existing sessions.',
                    Analog::WARNING
                );
                return [];
            }
        }

        // Determine end date: today + advance_weeks
        $advanceWeeks = $event->getAdvanceWeeks() ?: 4;
        $endDate = date('Y-m-d', strtotime('+' . $advanceWeeks . ' weeks'));

        // Respect recurrence end date if set
        if ($event->getRecurrenceEndDate() !== null && $event->getRecurrenceEndDate() < $endDate) {
            $endDate = $event->getRecurrenceEndDate();
        }

        // Calculate all occurrence dates in the range
        $dates = $this->calculateOccurrences(
            $startDate,
            $endDate,
            $event->getRecurrenceType() ?? 'weekly',
            $event->getRecurrenceInterval() ?? 1
        );

        // Update future sessions without instructor: apply new slot times and capacity
        $updated = $this->refreshNoInstructorSessions($event, $startTime, $endTime);
        if ($updated > 0) {
            Analog::log(
                'Updated ' . $updated . ' no-instructor sessions for event #' . $event->getId(),
                Analog::INFO
            );
        }

        // Filter out dates that already have sessions or fall on closure periods
        $existingDates = $this->getExistingSessionDates($event->getId());
        $newDates = array_filter($dates, function (string $d) use ($existingDates): bool {
            if (in_array($d, $existingDates)) {
                return false;
            }
            if ($this->pluginPrefs !== null && $this->pluginPrefs->isClosureDate($d)) {
                Analog::log('Skipping session on closure date: ' . $d, Analog::INFO);
                return false;
            }
            return true;
        });

        // Create sessions
        $created = [];
        foreach ($newDates as $date) {
            $session = new Session($this->zdb);
            $session->setEventId($event->getId());
            $session->setSessionDate($date);
            $session->setStartTime($startTime);
            $session->setEndTime($endTime);
            $session->setMaxCapacity($event->getMaxCapacity());
            if ($session->store()) {
                $created[] = $session;
            }
        }

        if (count($created) > 0) {
            Analog::log(
                'Generated ' . count($created) . ' sessions for event #' . $event->getId(),
                Analog::INFO
            );
        }

        return $created;
    }

    /**
     * Calculate occurrence dates between start and end dates.
     *
     * @return string[] Array of dates (yyyy-mm-dd)
     */
    private function calculateOccurrences(
        string $startDate,
        string $endDate,
        string $recurrenceType,
        int $interval
    ): array {
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        $today = strtotime(date('Y-m-d'));

        while ($current <= $end) {
            // Only include today or future dates
            if ($current >= $today) {
                $dates[] = date('Y-m-d', $current);
            }

            $current = match ($recurrenceType) {
                'weekly' => strtotime('+' . $interval . ' weeks', $current),
                'biweekly' => strtotime('+' . (2 * $interval) . ' weeks', $current),
                'monthly' => strtotime('+' . $interval . ' months', $current),
                default => strtotime('+1 week', $current),
            };
        }

        return $dates;
    }

    /**
     * Get the next start date by looking at the latest existing session
     * and adding one recurrence interval.
     */
    private function getNextStartDate(Event $event): ?string
    {
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->where(['event_id' => $event->getId()]);
            $select->order('session_date DESC');
            $select->limit(1);
            $results = $this->zdb->execute($select);
            $row = $results->current();

            if ($row) {
                $lastDate = (string)$row->session_date;
                $type = $event->getRecurrenceType() ?? 'weekly';
                $interval = $event->getRecurrenceInterval() ?? 1;

                return match ($type) {
                    'weekly' => date('Y-m-d', strtotime($lastDate . ' +' . $interval . ' weeks')),
                    'biweekly' => date('Y-m-d', strtotime($lastDate . ' +' . (2 * $interval) . ' weeks')),
                    'monthly' => date('Y-m-d', strtotime($lastDate . ' +' . $interval . ' months')),
                    default => date('Y-m-d', strtotime($lastDate . ' +1 week')),
                };
            }

            return null;
        } catch (Throwable $e) {
            Analog::log(
                'Error getting next start date for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return null;
        }
    }

    /**
     * Update start_time, end_time and max_capacity on future sessions that have
     * no instructor assigned yet (those are still "planifiable").
     * Only sessions with date >= today are touched.
     *
     * @return int Number of sessions updated
     */
    private function refreshNoInstructorSessions(Event $event, string $startTime, string $endTime): int
    {
        $today = date('Y-m-d');
        $updated = 0;

        try {
            // Find future sessions without any instructor via LEFT JOIN
            $select = $this->zdb->select(Session::TABLE, 's');
            $select->columns([Session::PK, 'session_date', 'start_time', 'end_time', 'max_capacity']);
            $select->join(
                ['si' => PREFIX_DB . SessionInstructor::TABLE],
                's.' . Session::PK . ' = si.session_id',
                [],
                \Laminas\Db\Sql\Select::JOIN_LEFT
            );
            $select->where(['s.event_id' => $event->getId()]);
            $select->where->greaterThanOrEqualTo('s.session_date', $today);
            $select->where->notEqualTo('s.status', Session::STATUS_CANCELLED);
            $select->where->isNull('si.session_id'); // no instructor row

            $results = $this->zdb->execute($select);

            $newCapacity = $event->getMaxCapacity();

            foreach ($results as $row) {
                $sid = (int)$row->{Session::PK};

                // Only update if something actually changed
                if (
                    (string)$row->start_time === $startTime
                    && (string)$row->end_time === $endTime
                    && (string)($row->max_capacity ?? '') === (string)($newCapacity ?? '')
                ) {
                    continue;
                }

                $upd = $this->zdb->update(Session::TABLE);
                $upd->set([
                    'start_time'   => $startTime,
                    'end_time'     => $endTime,
                    'max_capacity' => $newCapacity,
                ]);
                $upd->where([Session::PK => $sid]);
                $this->zdb->execute($upd);
                $updated++;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error refreshing no-instructor sessions for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }

        return $updated;
    }

    /**
     * Get all existing session dates for an event.
     *
     * @return string[] Array of dates (yyyy-mm-dd)
     */
    private function getExistingSessionDates(int $eventId): array
    {
        $dates = [];
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->columns(['session_date']);
            $select->where(['event_id' => $eventId]);
            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $dates[] = (string)$r->session_date;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error getting existing session dates: ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $dates;
    }
}
