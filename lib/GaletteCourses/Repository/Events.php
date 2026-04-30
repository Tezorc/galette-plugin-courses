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
use GaletteCourses\Filters\EventsList;
use Analog\Analog;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Predicate\Expression as PredicateExpression;
use Laminas\Db\Sql\Predicate\PredicateSet;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class Events
{
    private int $count = 0;

    public function __construct(
        private Db $zdb,
        private Login $login,
        private ?EventsList $filters = null
    ) {
        if ($this->filters === null) {
            $this->filters = new EventsList();
        }
    }

    /**
     * @return array<int, Event>
     */
    public function getList(): array
    {
        try {
            $select = $this->zdb->select(Event::TABLE, 'e');

            $this->buildWhereClause($select);

            // Count total before pagination
            $countSelect = clone $select;
            $countSelect->reset('columns');
            $countSelect->columns(['count' => new Expression('COUNT(*)')]);
            $results = $this->zdb->execute($countSelect);
            $this->count = (int)$results->current()->count;
            $this->filters->setCounter($this->count);

            // Ordering
            $select->order($this->buildOrderClause());

            // Pagination
            $this->filters->setLimits($select);

            $results = $this->zdb->execute($select);
            $events = [];
            foreach ($results as $r) {
                $events[(int)$r->{Event::PK}] = new Event($this->zdb, $r);
            }
            return $events;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading events list: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    private function buildWhereClause($select): void
    {
        // Role-based access filtering
        if (!$this->login->isAdmin() && !$this->login->isStaff()) {
            if ($this->login->isGroupManager()) {
                // Group managers see their own events + validated events
                $select->where->nest()
                    ->equalTo('e.creator_id', (int)$this->login->id)
                    ->or
                    ->equalTo('e.status', Event::STATUS_VALIDATED)
                    ->unnest();
            } else {
                // Regular members see only validated events
                $select->where->equalTo('e.status', Event::STATUS_VALIDATED);

                // Group restriction filtering for regular members
                if ($this->login->id !== null) {
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
        if ($this->filters->filter_str !== null && $this->filters->filter_str !== '') {
            $token = '%' . $this->filters->filter_str . '%';
            $select->where->nest()
                ->like('e.name', $token)
                ->or
                ->like('e.description', $token)
                ->or
                ->like('e.location', $token)
                ->unnest();
        }

        if ($this->filters->type_filter !== null) {
            $select->where->equalTo('e.type_id', $this->filters->type_filter);
        }

        if ($this->filters->status_filter !== null && $this->filters->status_filter !== '') {
            $select->where->equalTo('e.status', $this->filters->status_filter);
        }

        if ($this->filters->name_filter !== null && $this->filters->name_filter !== '') {
            $select->where->equalTo('e.name', $this->filters->name_filter);
        }
    }

    /**
     * Returns distinct event names with their type_id, accessible to the current user.
     * Used for the cascading name/type filter dropdown.
     *
     * @return array<array{name: string, type_id: int}>
     */
    public function getAvailableNames(): array
    {
        try {
            $select = $this->zdb->select(Event::TABLE, 'e');
            $select->columns(['name', 'type_id']);
            $select->quantifier('DISTINCT');

            // Apply only role-based access filtering
            if (!$this->login->isAdmin() && !$this->login->isStaff()) {
                if ($this->login->isGroupManager()) {
                    $select->where->nest()
                        ->equalTo('e.creator_id', (int)$this->login->id)
                        ->or
                        ->equalTo('e.status', Event::STATUS_VALIDATED)
                        ->unnest();
                } else {
                    $select->where->equalTo('e.status', Event::STATUS_VALIDATED);
                    if ($this->login->id !== null) {
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
            }

            // Filtrer par type si actif
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
            Analog::log('Error loading event names: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }

    private function buildOrderClause(): string
    {
        $order = match ($this->filters->orderby) {
            EventsList::ORDERBY_NAME => 'e.name',
            EventsList::ORDERBY_DATE => 'e.creation_date',
            EventsList::ORDERBY_STATUS => 'e.status',
            default => 'e.creation_date',
        };

        return $order . ' ' . $this->filters->getDirection();
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
