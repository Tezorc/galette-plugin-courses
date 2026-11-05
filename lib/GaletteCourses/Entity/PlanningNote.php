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

use Galette\Core\Db;
use Galette\Entity\Adherent;
use Analog\Analog;
use Throwable;

/**
 * A free note on one day of the instructor planning ("field booked",
 * "I can only stay until 11"...). Each member writes their own note for the
 * day; the planning shows them all under the day, each with the writer's
 * first name. Saving replaces one's note, an empty note removes it.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class PlanningNote
{
    public const TABLE = 'courses_planning_notes';

    public const MAX_LENGTH = 255;

    /**
     * The note as stored: trimmed, on one line, cut to the column size.
     */
    public static function normalize(string $note): string
    {
        $note = trim((string)preg_replace('/\s+/u', ' ', $note));
        return mb_substr($note, 0, self::MAX_LENGTH);
    }

    /**
     * Notes of the given days, in the order they were last written, with the
     * writer's first name (the full name when there is none).
     *
     * @param string[] $dates Y-m-d
     * @return array<string, list<array{member_id: int, name: string, note: string}>> date => notes
     */
    public static function getForDates(Db $zdb, array $dates): array
    {
        if (empty($dates)) {
            return [];
        }
        try {
            $select = $zdb->select(self::TABLE, 'pn');
            $select->columns(['note_date', 'member_id', 'note']);
            $select->join(
                ['a' => PREFIX_DB . Adherent::TABLE],
                'a.' . Adherent::PK . ' = pn.member_id',
                ['prenom_adh', 'nom_adh']
            );
            $select->where->in('pn.note_date', array_values($dates));
            $select->order(['pn.updated_date ASC']);
            $notes = [];
            foreach ($zdb->execute($select) as $row) {
                $name = trim((string)($row->prenom_adh ?? ''));
                $notes[(string)$row->note_date][] = [
                    'member_id' => (int)$row->member_id,
                    'name'      => $name !== '' ? $name : trim((string)($row->nom_adh ?? '')),
                    'note'      => (string)$row->note,
                ];
            }
            return $notes;
        } catch (Throwable $e) {
            Analog::log('Error loading planning notes: ' . $e->getMessage(), Analog::ERROR);
            return [];
        }
    }

    /**
     * Replace a member's note on a day; an empty note removes it.
     */
    public static function set(Db $zdb, string $date, int $memberId, string $note): bool
    {
        if ($memberId <= 0) {
            return false;
        }
        $note = self::normalize($note);
        try {
            $zdb->connection->beginTransaction();
            $delete = $zdb->delete(self::TABLE);
            $delete->where(['note_date' => $date, 'member_id' => $memberId]);
            $zdb->execute($delete);
            if ($note !== '') {
                $insert = $zdb->insert(self::TABLE);
                $insert->values([
                    'note_date'    => $date,
                    'member_id'    => $memberId,
                    'note'         => $note,
                    'updated_date' => date('Y-m-d H:i:s'),
                ]);
                $zdb->execute($insert);
            }
            $zdb->connection->commit();
            return true;
        } catch (Throwable $e) {
            $zdb->connection->rollBack();
            Analog::log(
                'Error saving the planning note of member #' . $memberId . ' on ' . $date . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }
}
