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

namespace GaletteCourses\Entity;

use Analog\Analog;
use Galette\Core\Db;
use Galette\Entity\Adherent;
use Throwable;

/**
 * Household: the set of member records linked to each other through `parent_id`.
 *
 * The plugin used to know one direction only — a parent acting for their own
 * child records (`Adherent::children`). A child record logging in could do
 * nothing for its parent nor for a sibling, although a household shares a single
 * mailbox and a single person doing the registrations.
 *
 * The rule is now symmetric:
 *
 *   head H    = the member's `parent_id` when set, the member otherwise
 *   household = { H } ∪ { x | x.parent_id = H }
 *
 * A parent therefore gets exactly their children back (previous behaviour), and
 * a child record additionally gets its parent and its siblings. Depth stays at
 * one level on purpose: Galette allows longer chains, but walking them up would
 * make the registration scope depend on a tree nobody sees in the interface.
 *
 * Household membership grants nothing by itself — it only says *for whom* a
 * member may act. Eligibility (active account, status, up-to-date membership)
 * and the event group restriction are still checked per member downstream.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class Household
{
    /**
     * Per-request cache: [member_id => int[] household ids].
     *
     * `Event::canAccess()` asks for the household once per displayed event; with
     * no cache a 40-event list would issue 80 SELECT for a constant result. The
     * static is reset between HTTP requests by PHP itself, and can be cleared
     * explicitly for tests.
     *
     * @var array<int, int[]>
     */
    private static array $cache = [];

    /**
     * Household head id: the parent when there is one, self otherwise.
     *
     * Falls back to $memberId as soon as the record is missing or the SELECT
     * fails — a household reduced to self is the safe fallback, it opens no
     * extra right.
     */
    public static function headId(Db $zdb, int $memberId): int
    {
        if ($memberId <= 0) {
            return 0;
        }

        try {
            $select = $zdb->select(Adherent::TABLE, 'a');
            $select->columns(['parent_id']);
            $select->where(['a.id_adh' => $memberId]);
            $row = $zdb->execute($select)->current();
            if (!$row) {
                return $memberId;
            }
            $parentId = (int)($row->parent_id ?? 0);
            return $parentId > 0 ? $parentId : $memberId;
        } catch (Throwable $e) {
            Analog::log(
                'Error resolving household head for member #' . $memberId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return $memberId;
        }
    }

    /**
     * Every member of the household, self included, ordered by name.
     *
     * @return int[]
     */
    public static function memberIds(Db $zdb, int $memberId): array
    {
        if ($memberId <= 0) {
            return [];
        }
        if (isset(self::$cache[$memberId])) {
            return self::$cache[$memberId];
        }

        $ids = [];
        try {
            $head = self::headId($zdb, $memberId);
            $select = $zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh']);
            $select->where->expression('(a.id_adh = ? OR a.parent_id = ?)', [$head, $head]);
            $select->order(['a.nom_adh ASC', 'a.prenom_adh ASC']);
            foreach ($zdb->execute($select) as $r) {
                $id = (int)$r->id_adh;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading household for member #' . $memberId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            // Safe fallback: the member alone, never a partial household that
            // would look complete in a dropdown.
            $ids = [];
        }

        if (!in_array($memberId, $ids, true)) {
            $ids[] = $memberId;
        }

        self::$cache[$memberId] = $ids;
        return $ids;
    }

    /**
     * Household members *other than* the member themselves: those they may
     * register, unregister or put on a waitlist.
     *
     * @return int[]
     */
    public static function linkedMemberIds(Db $zdb, int $memberId): array
    {
        return array_values(
            array_filter(
                self::memberIds($zdb, $memberId),
                static fn(int $id): bool => $id !== $memberId
            )
        );
    }

    /**
     * True when $otherId belongs to $memberId's household and is not $memberId.
     */
    public static function isLinked(Db $zdb, int $memberId, int $otherId): bool
    {
        if ($memberId <= 0 || $otherId <= 0 || $memberId === $otherId) {
            return false;
        }
        return in_array($otherId, self::memberIds($zdb, $memberId), true);
    }

    /**
     * `EXISTS (...)` SQL fragment, true when event `e` is restricted to a group
     * one of the logged-in member's household members belongs to.
     *
     * Takes a **single** bound parameter: the logged-in member id. The household
     * head is resolved inside the query (`COALESCE(parent_id, id_adh)`) rather
     * than in PHP, so the repositories stay at one SQL round-trip and the
     * predicate keeps the signature it had as a children-only clause.
     *
     * Assumes the events table is aliased `e`, which holds everywhere in
     * Repository\Events and Repository\Sessions.
     *
     * @param string $suffix alias suffix, unique within the enclosing query
     */
    public static function eventGroupsExistsSql(string $suffix): string
    {
        $eg  = 'eg_' . $suffix;
        $gm  = 'gm_' . $suffix;
        $rel = 'rel_' . $suffix;
        $me  = 'me_' . $suffix;

        return 'EXISTS (SELECT 1 FROM ' . PREFIX_DB . 'courses_events_groups ' . $eg
            . ' INNER JOIN ' . PREFIX_DB . 'groups_members ' . $gm
            . ' ON ' . $eg . '.group_id = ' . $gm . '.id_group'
            . ' INNER JOIN ' . PREFIX_DB . 'adherents ' . $rel
            . ' ON ' . $rel . '.id_adh = ' . $gm . '.id_adh'
            . ' INNER JOIN ' . PREFIX_DB . 'adherents ' . $me . ' ON ' . $me . '.id_adh = ?'
            . ' WHERE ' . $eg . '.event_id = e.' . Event::PK
            . ' AND (' . $rel . '.parent_id = COALESCE(' . $me . '.parent_id, ' . $me . '.id_adh)'
            . ' OR ' . $rel . '.id_adh = COALESCE(' . $me . '.parent_id, ' . $me . '.id_adh)))';
    }

    /**
     * Clear the household cache. Test-only.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
