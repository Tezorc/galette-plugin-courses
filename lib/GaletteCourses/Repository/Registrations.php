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

namespace GaletteCourses\Repository;

use Galette\Core\Db;
use Galette\Core\Login;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Registration;
use GaletteCourses\Entity\Session;
use GaletteCourses\Filters\RegistrationsList;
use Analog\Analog;
use Laminas\Db\Sql\Expression;
use Throwable;

class Registrations
{
    private int $count = 0;

    public function __construct(
        private Db $zdb,
        private Login $login,
        private ?RegistrationsList $filters = null
    ) {
        if ($this->filters === null) {
            $this->filters = new RegistrationsList();
        }
    }

    /**
     * @return array<int, Registration>
     */
    public function getList(): array
    {
        try {
            $select = $this->zdb->select(Registration::TABLE, 'r');

            $this->buildWhereClause($select);

            // Count
            $countSelect = clone $select;
            $countSelect->reset('columns');
            $countSelect->columns(['count' => new Expression('COUNT(*)')]);
            $results = $this->zdb->execute($countSelect);
            $this->count = (int)$results->current()->count;
            $this->filters->setCounter($this->count);

            // Order
            $select->order('r.registration_date DESC');

            // Pagination
            $this->filters->setLimits($select);

            $results = $this->zdb->execute($select);
            $registrations = [];
            foreach ($results as $r) {
                $registrations[(int)$r->{Registration::PK}] = new Registration($this->zdb, $r);
            }
            return $registrations;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading registrations list: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * @return array<int, Registration>
     */
    public function getForSession(int $sessionId): array
    {
        try {
            $select = $this->zdb->select(Registration::TABLE);
            $select->where(['session_id' => $sessionId]);
            $select->where->notEqualTo('status', Registration::STATUS_CANCELLED);
            $select->order('registration_date ASC');

            $results = $this->zdb->execute($select);
            $registrations = [];
            foreach ($results as $r) {
                $registrations[(int)$r->{Registration::PK}] = new Registration($this->zdb, $r);
            }
            return $registrations;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading registrations for session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * @return array<int, Registration>
     */
    public function getForMember(int $memberId): array
    {
        return $this->getForMembers([$memberId]);
    }

    /**
     * @param array<int> $memberIds
     * @return array<int, Registration>
     */
    public function getForMembers(array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }
        try {
            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                ['session_date', 'start_time', 'end_time', 'event_id']
            );
            $select->where->in('r.member_id', $memberIds);
            $select->where->notEqualTo('r.status', Registration::STATUS_CANCELLED);
            $select->order('s.session_date ASC, s.start_time ASC, r.' . Registration::PK . ' ASC');

            $results = $this->zdb->execute($select);
            $registrations = [];
            foreach ($results as $r) {
                $registrations[(int)$r->{Registration::PK}] = new Registration($this->zdb, $r);
            }
            return $registrations;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading registrations for members [' . implode(',', $memberIds) . ']: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    private function buildWhereClause($select): void
    {
        if ($this->filters->session_filter !== null) {
            $select->where->equalTo('r.session_id', $this->filters->session_filter);
        }

        if ($this->filters->member_filter !== null) {
            $select->where->equalTo('r.member_id', $this->filters->member_filter);
        }

        if ($this->filters->status_filter !== null && $this->filters->status_filter !== '') {
            $select->where->equalTo('r.status', $this->filters->status_filter);
        } else {
            // By default, hide cancelled registrations
            $select->where->notEqualTo('r.status', Registration::STATUS_CANCELLED);
        }

        // Join sessions (+events) if any session/event-level filter is active
        $needsSessionJoin = $this->filters->date_from !== null
            || $this->filters->date_to !== null
            || $this->filters->event_type_filter !== null
            || ($this->filters->name_filter !== null && $this->filters->name_filter !== '');

        if ($needsSessionJoin) {
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                []
            );

            if ($this->filters->date_from !== null) {
                $select->where->greaterThanOrEqualTo('s.session_date', $this->filters->date_from);
            }
            if ($this->filters->date_to !== null) {
                $select->where->lessThanOrEqualTo('s.session_date', $this->filters->date_to);
            }

            $needsEventJoin = $this->filters->event_type_filter !== null
                || ($this->filters->name_filter !== null && $this->filters->name_filter !== '');

            if ($needsEventJoin) {
                $select->join(
                    ['e' => PREFIX_DB . Event::TABLE],
                    's.event_id = e.' . Event::PK,
                    []
                );
                if ($this->filters->event_type_filter !== null) {
                    $select->where->equalTo('e.type_id', $this->filters->event_type_filter);
                }
                if ($this->filters->name_filter !== null && $this->filters->name_filter !== '') {
                    $select->where->equalTo('e.name', $this->filters->name_filter);
                }
            }
        }
    }

    /**
     * Returns distinct event names (with type_id) from registered sessions.
     * Filtered by event_type_filter if active. Used for the cascading name dropdown.
     *
     * @return array<array{name: string, type_id: int}>
     */
    public function getAvailableNames(): array
    {
        try {
            $select = $this->zdb->select(Event::TABLE, 'e');
            $select->columns(['name', 'type_id']);
            $select->quantifier('DISTINCT');

            // Only events that have at least one registration
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                's.event_id = e.' . Event::PK,
                []
            );
            $select->join(
                ['r' => PREFIX_DB . Registration::TABLE],
                'r.session_id = s.' . Session::PK,
                []
            );

            if ($this->filters->event_type_filter !== null) {
                $select->where->equalTo('e.type_id', $this->filters->event_type_filter);
            }

            $select->order('e.name ASC');
            $results = $this->zdb->execute($select);

            $names = [];
            foreach ($results as $r) {
                $names[] = ['name' => $r->name, 'type_id' => (int)$r->type_id];
            }
            return $names;
        } catch (Throwable $e) {
            Analog::log('Error loading event names for registrations: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
