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

namespace GaletteCourses;

use Analog\Analog;
use Galette\Core\Db;
use Galette\Entity\Adherent;
use GaletteCourses\Entity\SessionInstructor;
use Throwable;

/**
 * The instructor planning: one row per instructor, one column per day with
 * sessions, so that who leads what, who is available and who is away over
 * several weeks reads at a glance.
 *
 * The database work stays in the small loaders; build() is pure so the grid
 * is testable without a database.
 *
 * @phpstan-type PlanningSession array{
 *     id: int,
 *     date: string,
 *     start: string,
 *     end: string,
 *     event_name: string,
 *     cancelled: bool,
 *     needs_instructor: bool,
 *     registrations: int,
 *     capacity: ?int,
 *     can_volunteer: bool
 * }
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorPlanning
{
    /** Weeks shown at once. */
    public const WEEKS = 8;

    /**
     * Too few registrations to keep the session going: under a third of the
     * capacity, or nobody at all when the capacity is unlimited.
     */
    public static function isLowRegistrations(int $registrations, ?int $capacity): bool
    {
        if ($capacity === null || $capacity <= 0) {
            return $registrations === 0;
        }
        return $registrations * 3 < $capacity;
    }

    /**
     * Lay out the planning.
     *
     * Days keep only the dates that have sessions. An instructor's cell lists
     * the sessions they lead that day, and their declared status otherwise.
     * The viewer's row comes first, then the others by name.
     *
     * @param list<PlanningSession>                                          $sessions
     * @param array<int, int[]>                                              $instructorIds session id => member ids
     * @param array<int, string>                                             $members       member id => name
     * @param array<string, array<int, array{kind: string, comment: ?string}>> $status        date => member id => status
     * @return array{
     *     days: array<string, array{sessions: list<array<string, mixed>>, missing: bool, low: bool}>,
     *     rows: array<int, array{name: string, is_me: bool, cells: array<string, array{leads: list<array{id: int, label: string}>, kind: ?string, comment: ?string}>}>
     * }
     */
    public static function build(array $sessions, array $instructorIds, array $members, array $status, int $viewerId): array
    {
        usort($sessions, static fn(array $a, array $b): int => strcmp($a['date'] . $a['start'], $b['date'] . $b['start']));

        $days = [];
        $leads = [];
        foreach ($sessions as $s) {
            $ids = $instructorIds[$s['id']] ?? [];
            $low = !$s['cancelled'] && self::isLowRegistrations($s['registrations'], $s['capacity']);
            $missing = !$s['cancelled'] && $s['needs_instructor'] && empty($ids);
            $days[$s['date']] ??= ['sessions' => [], 'missing' => false, 'low' => false];
            $days[$s['date']]['sessions'][] = $s + [
                'instructor_count' => count($ids),
                'missing'          => $missing,
                'low'              => $low,
            ];
            $days[$s['date']]['missing'] = $days[$s['date']]['missing'] || $missing;
            $days[$s['date']]['low'] = $days[$s['date']]['low'] || $low;
            if ($s['cancelled']) {
                continue;
            }
            foreach ($ids as $mid) {
                $leads[$mid][$s['date']][] = [
                    'id'    => $s['id'],
                    'label' => $s['event_name'] . ' ' . substr($s['start'], 0, 5),
                ];
            }
        }

        $rows = [];
        foreach ($members as $mid => $name) {
            $cells = [];
            foreach (array_keys($days) as $date) {
                $declared = $status[$date][$mid] ?? null;
                $cells[$date] = [
                    'leads'   => $leads[$mid][$date] ?? [],
                    'kind'    => $declared['kind'] ?? null,
                    'comment' => $declared['comment'] ?? null,
                ];
            }
            $rows[$mid] = ['name' => $name, 'is_me' => $mid === $viewerId, 'cells' => $cells];
        }
        uksort($rows, static function (int $a, int $b) use ($rows): int {
            if ($rows[$a]['is_me'] !== $rows[$b]['is_me']) {
                return $rows[$a]['is_me'] ? -1 : 1;
            }
            return strcasecmp($rows[$a]['name'], $rows[$b]['name']);
        });

        return ['days' => $days, 'rows' => $rows];
    }

    /**
     * Who may lead a session at all: every group manager.
     *
     * @return int[]
     */
    public static function getGroupManagerIds(Db $zdb): array
    {
        try {
            $select = $zdb->select('groups_managers');
            $select->columns(['id_adh']);
            $select->quantifier('DISTINCT');
            $ids = [];
            foreach ($zdb->execute($select) as $row) {
                $ids[] = (int)$row->id_adh;
            }
            return $ids;
        } catch (Throwable $e) {
            Analog::log('Error loading group managers: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }

    /**
     * Instructors of each session, in one query.
     *
     * @param int[] $sessionIds
     * @return array<int, int[]> session id => member ids
     */
    public static function getInstructorIds(Db $zdb, array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }
        try {
            $select = $zdb->select(SessionInstructor::TABLE);
            $select->columns(['session_id', 'member_id']);
            $select->where->in('session_id', array_values($sessionIds));
            $ids = [];
            foreach ($zdb->execute($select) as $row) {
                $ids[(int)$row->session_id][] = (int)$row->member_id;
            }
            return $ids;
        } catch (Throwable $e) {
            Analog::log('Error loading session instructors: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }

    /**
     * Display names of active members, "First Last".
     *
     * @param int[] $memberIds
     * @return array<int, string>
     */
    public static function getMemberNames(Db $zdb, array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }
        try {
            $select = $zdb->select(Adherent::TABLE);
            $select->columns([Adherent::PK, 'nom_adh', 'prenom_adh']);
            $select->where->in(Adherent::PK, array_values(array_unique($memberIds)));
            $select->where(['activite_adh' => true]);
            $names = [];
            foreach ($zdb->execute($select) as $row) {
                $names[(int)$row->{Adherent::PK}] = trim(($row->prenom_adh ?? '') . ' ' . ($row->nom_adh ?? ''));
            }
            return $names;
        } catch (Throwable $e) {
            Analog::log('Error loading member names: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }
}
