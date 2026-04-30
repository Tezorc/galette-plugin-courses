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
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\Event;
use GaletteCourses\Filters\SessionsList;
use Analog\Analog;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Predicate\Expression as PredicateExpression;
use Laminas\Db\Sql\Predicate\PredicateSet;
use Throwable;

class Sessions
{
    private int $count = 0;

    /**
     * When set, group filtering is always applied for this member ID,
     * regardless of admin/staff role (used for personal views like "My registrations").
     */
    private ?int $personalMemberId = null;

    public function __construct(
        private Db $zdb,
        private Login $login,
        private ?SessionsList $filters = null
    ) {
        if ($this->filters === null) {
            $this->filters = new SessionsList();
        }
    }

    /**
     * Force member-based group filtering regardless of role.
     * Use for personal views (e.g. "My registrations") so that staff/admin/monitors
     * only see sessions relevant to their own groups and children.
     */
    public function setPersonalMemberId(int $memberId): void
    {
        $this->personalMemberId = $memberId;
    }

    /**
     * @return array<int, Session>
     */
    public function getList(): array
    {
        try {
            $select = $this->zdb->select(Session::TABLE, 's');
            $select->join(
                ['e' => PREFIX_DB . Event::TABLE],
                's.event_id = e.' . Event::PK,
                []
            );

            $this->buildWhereClause($select);

            // Count
            $countSelect = clone $select;
            $countSelect->reset('columns');
            $countSelect->columns(['count' => new Expression('COUNT(*)')]);
            $results = $this->zdb->execute($countSelect);
            $this->count = (int)$results->current()->count;
            $this->filters->setCounter($this->count);

            // Order
            $select->order($this->buildOrderClause());

            // Pagination
            $this->filters->setLimits($select);

            $results = $this->zdb->execute($select);
            $sessions = [];
            foreach ($results as $r) {
                $sessions[(int)$r->{Session::PK}] = new Session($this->zdb, $r);
            }
            return $sessions;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading sessions list: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * @return array<int, Session>
     */
    public function getForEvent(int $eventId): array
    {
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->where(['event_id' => $eventId]);
            $select->order('session_date ASC, start_time ASC');

            $results = $this->zdb->execute($select);
            $sessions = [];
            foreach ($results as $r) {
                $sessions[(int)$r->{Session::PK}] = new Session($this->zdb, $r);
            }
            return $sessions;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading sessions for event #' . $eventId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * @return array<int, Session>
     */
    public function getUpcoming(int $limit = 20): array
    {
        try {
            $select = $this->zdb->select(Session::TABLE, 's');
            $select->join(
                ['e' => PREFIX_DB . Event::TABLE],
                's.event_id = e.' . Event::PK,
                []
            );
            $select->where->greaterThanOrEqualTo('s.session_date', date('Y-m-d'));
            $select->where->equalTo('s.status', Session::STATUS_OPEN);
            $select->where->equalTo('e.status', Event::STATUS_VALIDATED);

            // Group restriction filtering for regular members
            if (!$this->login->isAdmin() && !$this->login->isStaff() && !$this->login->isGroupManager() && $this->login->id !== null) {
                $memberId = (int)$this->login->id;
                $nested = $select->where->nest();
                $nested->equalTo('e.is_restricted', 0);
                // OR events matching the member's own groups
                $nested->addPredicate(
                    new PredicateExpression(
                        'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg'
                        . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm ON eg.group_id = gm.id_group'
                        . ' WHERE eg.event_id = e.' . Event::PK . ' AND gm.id_adh = ?)',
                        [$memberId]
                    ),
                    PredicateSet::OP_OR
                );
                // OR events matching any of the member's children's groups
                $nested->addPredicate(
                    new PredicateExpression(
                        'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg_c'
                        . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm_c ON eg_c.group_id = gm_c.id_group'
                        . ' INNER JOIN ' . PREFIX_DB . 'adherents child ON child.id_adh = gm_c.id_adh'
                        . ' WHERE eg_c.event_id = e.' . Event::PK . ' AND child.parent_id = ?)',
                        [$memberId]
                    ),
                    PredicateSet::OP_OR
                );
                $nested->unnest();
            }

            $select->order('s.session_date ASC, s.start_time ASC');
            $select->limit($limit);

            $results = $this->zdb->execute($select);
            $sessions = [];
            foreach ($results as $r) {
                $sessions[(int)$r->{Session::PK}] = new Session($this->zdb, $r);
            }
            return $sessions;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading upcoming sessions: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    private function buildWhereClause($select): void
    {
        // In personal view mode, always restrict to validated events and member's own groups
        if ($this->personalMemberId !== null) {
            $memberId = $this->personalMemberId;
            $select->where->equalTo('e.status', Event::STATUS_VALIDATED);
            $nested = $select->where->nest();
            // Unrestricted events (open to all)
            $nested->addPredicate(
                new PredicateExpression(
                    'NOT EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg_p WHERE eg_p.event_id = e.' . Event::PK . ')'
                )
            );
            // OR events matching the member's own groups
            $nested->addPredicate(
                new PredicateExpression(
                    'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg'
                    . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm ON eg.group_id = gm.id_group'
                    . ' WHERE eg.event_id = e.' . Event::PK . ' AND gm.id_adh = ?)',
                    [$memberId]
                ),
                PredicateSet::OP_OR
            );
            // OR events matching any of the member's children's groups
            $nested->addPredicate(
                new PredicateExpression(
                    'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg_c'
                    . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm_c ON eg_c.group_id = gm_c.id_group'
                    . ' INNER JOIN ' . PREFIX_DB . 'adherents child ON child.id_adh = gm_c.id_adh'
                    . ' WHERE eg_c.event_id = e.' . Event::PK . ' AND child.parent_id = ?)',
                    [$memberId]
                ),
                PredicateSet::OP_OR
            );
            $nested->unnest();
        } else {
            // Role-based access
            if (!$this->login->isAdmin() && !$this->login->isStaff()) {
                $select->where->equalTo('e.status', Event::STATUS_VALIDATED);

                // Group restriction filtering for regular members
                if (!$this->login->isGroupManager() && $this->login->id !== null) {
                    $memberId = (int)$this->login->id;
                    $nested = $select->where->nest();
                    $nested->equalTo('e.is_restricted', 0);
                    // OR events matching the member's own groups
                    $nested->addPredicate(
                        new PredicateExpression(
                            'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg'
                            . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm ON eg.group_id = gm.id_group'
                            . ' WHERE eg.event_id = e.' . Event::PK . ' AND gm.id_adh = ?)',
                            [$memberId]
                        ),
                        PredicateSet::OP_OR
                    );
                    // OR events matching any of the member's children's groups
                    $nested->addPredicate(
                        new PredicateExpression(
                            'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg_c'
                            . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm_c ON eg_c.group_id = gm_c.id_group'
                            . ' INNER JOIN ' . PREFIX_DB . 'adherents child ON child.id_adh = gm_c.id_adh'
                            . ' WHERE eg_c.event_id = e.' . Event::PK . ' AND child.parent_id = ?)',
                            [$memberId]
                        ),
                        PredicateSet::OP_OR
                    );
                    $nested->unnest();
                }
            }
        }

        // Apply filters
        if ($this->filters->event_filter !== null) {
            $select->where->equalTo('s.event_id', $this->filters->event_filter);
        }

        if ($this->filters->type_filter !== null) {
            $select->where->equalTo('e.type_id', $this->filters->type_filter);
        }

        if ($this->filters->name_filter !== null && $this->filters->name_filter !== '') {
            $select->where->equalTo('e.name', $this->filters->name_filter);
        }

        if ($this->filters->date_from !== null && $this->filters->date_from !== '') {
            $select->where->greaterThanOrEqualTo('s.session_date', $this->filters->date_from);
        }

        if ($this->filters->date_to !== null && $this->filters->date_to !== '') {
            $select->where->lessThanOrEqualTo('s.session_date', $this->filters->date_to);
        }

        if ($this->filters->status_filter !== null && $this->filters->status_filter !== '') {
            $select->where->equalTo('s.status', $this->filters->status_filter);
        }

        // Group-based filtering: non-admin/staff always see only their groups' courses
        if (
            !$this->login->isAdmin()
            && !$this->login->isStaff()
            && $this->login->id !== null
        ) {
            $memberId = (int)$this->login->id;
            $nested = $select->where->nest();
            // Events with no group associations (open to everyone)
            $nested->addPredicate(
                new PredicateExpression(
                    'NOT EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg2 WHERE eg2.event_id = e.' . Event::PK . ')'
                )
            );
            // OR events with at least one group matching member's own groups
            $nested->addPredicate(
                new PredicateExpression(
                    'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg3'
                    . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm2 ON eg3.group_id = gm2.id_group'
                    . ' WHERE eg3.event_id = e.' . Event::PK . ' AND gm2.id_adh = ?)',
                    [$memberId]
                ),
                PredicateSet::OP_OR
            );
            // OR events with groups matching any of the member's children's groups
            $nested->addPredicate(
                new PredicateExpression(
                    'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg5'
                    . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm5 ON eg5.group_id = gm5.id_group'
                    . ' INNER JOIN ' . PREFIX_DB . 'adherents child ON child.id_adh = gm5.id_adh'
                    . ' WHERE eg5.event_id = e.' . Event::PK . ' AND child.parent_id = ?)',
                    [$memberId]
                ),
                PredicateSet::OP_OR
            );
            // OR events with groups managed by this member (for group managers)
            if ($this->login->isGroupManager()) {
                $nested->addPredicate(
                    new PredicateExpression(
                        'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg4'
                        . ' WHERE eg4.event_id = e.' . Event::PK
                        . ' AND (eg4.group_id IN (SELECT id_group FROM ' . PREFIX_DB . 'groups_managers WHERE id_adh = ?)'
                        . ' OR eg4.group_id IN (SELECT g2.id_group FROM ' . PREFIX_DB . 'groups g2'
                        . ' INNER JOIN ' . PREFIX_DB . 'groups_managers gm3 ON g2.parent_group = gm3.id_group'
                        . ' WHERE gm3.id_adh = ?)))',
                        [$memberId, $memberId]
                    ),
                    PredicateSet::OP_OR
                );
            }
            $nested->unnest();
        }
    }

    /**
     * Returns distinct event names (with type_id) accessible to the current user.
     * Filtered by type_filter if active. Used for the cascading filter dropdown.
     *
     * @return array<array{name: string, type_id: int}>
     */
    public function getAvailableNames(): array
    {
        try {
            $select = $this->zdb->select(Event::TABLE, 'e');
            $select->columns(['name', 'type_id']);
            $select->quantifier('DISTINCT');

            // Role-based access
            if (!$this->login->isAdmin() && !$this->login->isStaff()) {
                $select->where->equalTo('e.status', Event::STATUS_VALIDATED);

                if (!$this->login->isGroupManager() && $this->login->id !== null) {
                    $memberId = (int)$this->login->id;
                    $nested = $select->where->nest();
                    $nested->equalTo('e.is_restricted', 0);
                    $nested->addPredicate(
                        new PredicateExpression(
                            'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg'
                            . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm ON eg.group_id = gm.id_group'
                            . ' WHERE eg.event_id = e.' . Event::PK . ' AND gm.id_adh = ?)',
                            [$memberId]
                        ),
                        PredicateSet::OP_OR
                    );
                    $nested->addPredicate(
                        new PredicateExpression(
                            'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups eg_c'
                            . ' INNER JOIN ' . PREFIX_DB . 'groups_members gm_c ON eg_c.group_id = gm_c.id_group'
                            . ' INNER JOIN ' . PREFIX_DB . 'adherents child ON child.id_adh = gm_c.id_adh'
                            . ' WHERE eg_c.event_id = e.' . Event::PK . ' AND child.parent_id = ?)',
                            [$memberId]
                        ),
                        PredicateSet::OP_OR
                    );
                    $nested->unnest();
                }
            }

            // Filter by type if active
            if ($this->filters->type_filter !== null) {
                $select->where->equalTo('e.type_id', $this->filters->type_filter);
            }

            $select->order('e.name ASC');
            $results = $this->zdb->execute($select);

            $names = [];
            foreach ($results as $r) {
                $names[] = ['name' => $r->name, 'type_id' => (int)$r->type_id];
            }
            return $names;
        } catch (Throwable $e) {
            Analog::log('Error loading event names for sessions: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }

    private function buildOrderClause(): string
    {
        $order = match ($this->filters->orderby) {
            SessionsList::ORDERBY_DATE => 's.session_date',
            SessionsList::ORDERBY_EVENT => 'e.name',
            default => 's.session_date',
        };

        return $order . ' ' . $this->filters->getDirection();
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
