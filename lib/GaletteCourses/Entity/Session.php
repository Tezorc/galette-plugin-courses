<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 Team ccag42 <contact@ccag42.org>
 *
 * This file is part of Galette (https://galette.eu).
 *
 * Galette is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Galette is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Galette. If not, see <http://www.gnu.org/licenses/>.
 */

namespace GaletteCourses\Entity;

use ArrayObject;
use Galette\Core\Db;
use Analog\Analog;
use Throwable;

class Session
{
    public const TABLE = 'courses_sessions';
    public const PK = 'id_session';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';


    private int $id;
    private int $event_id;
    private string $session_date;
    private string $start_time;
    private string $end_time;
    private string $status = self::STATUS_OPEN;
    private ?int $max_capacity = null;
    private int $current_registrations = 0;
    private ?string $cancellation_reason = null;
    private ?string $cancellation_comment = null;

    private ?Event $event = null;

    public function __construct(private Db $zdb, int|ArrayObject|null $args = null)
    {
        if (is_int($args)) {
            $this->load($args);
        } elseif ($args instanceof ArrayObject) {
            $this->loadFromRS($args);
        }
    }

    private function load(int $id): void
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->limit(1)->where([self::PK => $id]);
            $results = $this->zdb->execute($select);
            /** @var ArrayObject<string, int|string>|null $res */
            $res = $results->current();
            if ($res) {
                $this->loadFromRS($res);
            }
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred loading session #' . $id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * @param ArrayObject<string, int|string> $rs
     */
    private function loadFromRS(ArrayObject $rs): void
    {
        $this->id = (int)$rs->{self::PK};
        $this->event_id = (int)$rs->event_id;
        $this->session_date = (string)$rs->session_date;
        $this->start_time = (string)$rs->start_time;
        $this->end_time = (string)$rs->end_time;
        $this->status = (string)$rs->status;
        $this->max_capacity = $rs->max_capacity !== null ? (int)$rs->max_capacity : null;
        $this->current_registrations = (int)$rs->current_registrations;
        $this->cancellation_reason = isset($rs->cancellation_reason) ? (string)$rs->cancellation_reason : null;
        $this->cancellation_comment = isset($rs->cancellation_comment) ? (string)$rs->cancellation_comment : null;
    }

    public function store(): bool
    {
        try {
            $values = [
                'event_id' => $this->event_id,
                'session_date' => $this->session_date,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'status' => $this->status,
                'max_capacity' => $this->max_capacity,
                'current_registrations' => $this->current_registrations,
                'cancellation_reason' => $this->cancellation_reason,
                'cancellation_comment' => $this->cancellation_comment,
            ];

            if (isset($this->id) && $this->id > 0) {
                $update = $this->zdb->update(self::TABLE);
                $update->set($values)->where([self::PK => $this->id]);
                $this->zdb->execute($update);
            } else {
                $insert = $this->zdb->insert(self::TABLE);
                $insert->values($values);
                $add = $this->zdb->execute($insert);
                if (!$add->count() > 0) {
                    return false;
                }
                $this->id = $this->zdb->getLastGeneratedValue($this);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred storing session: ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    public function getRemainingSpots(): ?int
    {
        if ($this->max_capacity === null) {
            return null;
        }
        return max(0, $this->max_capacity - $this->current_registrations);
    }

    public function isFull(): bool
    {
        if ($this->max_capacity === null) {
            return false;
        }
        return $this->current_registrations >= $this->max_capacity;
    }

    public function isOpen(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }
        $today = date('Y-m-d');
        if ($this->session_date > $today) {
            return true;
        }
        if ($this->session_date < $today) {
            return false;
        }
        // Same day: allow registration until the session starts
        return date('H:i:s') < $this->start_time;
    }

    public function canUnregister(?int $deadline_days = null): bool
    {
        if ($deadline_days === null) {
            return true;
        }
        $deadline = date('Y-m-d', strtotime($this->session_date . ' -' . $deadline_days . ' days'));
        return date('Y-m-d') <= $deadline;
    }

    public function incrementRegistrations(): void
    {
        $this->current_registrations++;
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set(['current_registrations' => $this->current_registrations]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
        } catch (Throwable $e) {
            Analog::log(
                'Error incrementing registrations for session #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    public function decrementRegistrations(): void
    {
        if ($this->current_registrations > 0) {
            $this->current_registrations--;
        }
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set(['current_registrations' => $this->current_registrations]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
        } catch (Throwable $e) {
            Analog::log(
                'Error decrementing registrations for session #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    public function getEvent(): Event
    {
        if ($this->event === null) {
            $this->event = new Event($this->zdb, $this->event_id);
        }
        return $this->event;
    }

    public function getCapacityPercent(): int
    {
        if ($this->max_capacity === null || $this->max_capacity === 0) {
            return 0;
        }
        return (int)round(($this->current_registrations / $this->max_capacity) * 100);
    }

    // Setters for creating sessions programmatically
    public function setEventId(int $event_id): void
    {
        $this->event_id = $event_id;
    }

    public function setSessionDate(string $date): void
    {
        $this->session_date = $date;
    }

    public function setStartTime(string $time): void
    {
        $this->start_time = $time;
    }

    public function setEndTime(string $time): void
    {
        $this->end_time = $time;
    }

    public function setMaxCapacity(?int $capacity): void
    {
        $this->max_capacity = $capacity;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getEventId(): int
    {
        return $this->event_id;
    }

    public function getSessionDate(): string
    {
        return $this->session_date;
    }

    /**
     * Returns date formatted as dd/mm/yyyy
     */
    public function getFormattedDate(): string
    {
        return date('d/m/Y', strtotime($this->session_date));
    }

    /**
     * Returns date formatted as "jj mmm aaaa" (e.g. "24 avr. 2026")
     */
    public function getFormattedDateShort(): string
    {
        $ts = strtotime($this->session_date);
        return (int)date('d', $ts)
            . ' ' . self::FRENCH_MONTHS[(int)date('n', $ts)]
            . ' ' . date('Y', $ts);
    }

    /**
     * Returns long French date (e.g. "Samedi 14 Mars 2026")
     */
    public function getFormattedDateLong(): string
    {
        $ts = strtotime($this->session_date);
        return self::FRENCH_DAYS[(int)date('w', $ts)]
            . ' ' . (int)date('d', $ts)
            . ' ' . self::FRENCH_MONTHS_FULL[(int)date('n', $ts)]
            . ' ' . date('Y', $ts);
    }

    private const FRENCH_DAYS = [
        0 => 'Dimanche', 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi',
        4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi',
    ];

    private const FRENCH_MONTHS = [
        1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.',
        5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août',
        9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
    ];

    private const FRENCH_MONTHS_FULL = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    /**
     * Returns abbreviated French month + year (e.g. "mars 2026")
     */
    public function getMonthYear(): string
    {
        $ts = strtotime($this->session_date);
        return self::FRENCH_MONTHS[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    }

    public function getStartTime(): string
    {
        return $this->start_time;
    }

    public function getEndTime(): string
    {
        return $this->end_time;
    }

    /**
     * Returns start time formatted as HhMM (e.g. "14h00")
     */
    public function getFormattedStartTime(): string
    {
        return date('G\hi', strtotime($this->start_time));
    }

    /**
     * Returns end time formatted as HhMM (e.g. "15h00")
     */
    public function getFormattedEndTime(): string
    {
        return date('G\hi', strtotime($this->end_time));
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => _T('Open', 'courses'),
            self::STATUS_CLOSED => _T('Closed', 'courses'),
            self::STATUS_CANCELLED => _T('Cancelled', 'courses'),
            default => $this->status,
        };
    }

    public function getMaxCapacity(): ?int
    {
        return $this->max_capacity;
    }

    public function getCurrentRegistrations(): int
    {
        return $this->current_registrations;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellation_reason;
    }

    public function getCancellationReasonLabel(): string
    {
        if ($this->cancellation_reason === null) {
            return '';
        }
        return match ($this->cancellation_reason) {
            'concours' => _T('Competition', 'courses'),
            'absence_moniteur' => _T('Instructor absent', 'courses'),
            'formation' => _T('Training', 'courses'),
            'meteo' => _T('Weather', 'courses'),
            'autre' => _T('Other', 'courses'),
            default => $this->cancellation_reason,
        };
    }

    public function getCancellationComment(): ?string
    {
        return $this->cancellation_comment;
    }

    public function setCancellationReason(?string $reason): void
    {
        $this->cancellation_reason = $reason;
    }

    public function setCancellationComment(?string $comment): void
    {
        $this->cancellation_comment = $comment;
    }
}
