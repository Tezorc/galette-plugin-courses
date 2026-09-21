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
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Session;
use Throwable;

/**
 * Builds the human-readable, translated descriptions written to Galette
 * history (« Journaux »).
 *
 * History entries used to carry raw identifiers (`session #42 — member #17`):
 * untranslated, and unreadable without querying the database by hand. Every
 * description now goes through these helpers, which resolve identifiers to
 * names and dates in the language of the current request.
 *
 * All of them are best-effort: a row whose related data can no longer be read
 * degrades to its bare id instead of throwing. The history entry matters less
 * than the operation it describes.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class HistoryLabel
{
    /** Separator between the segments of a description. */
    public const SEP = ' — ';

    /** @var array<int, string> member id => display name, per request */
    private static array $member_names = [];

    /**
     * Join non-empty segments into a single description.
     */
    public static function join(?string ...$parts): string
    {
        return implode(
            self::SEP,
            array_filter(
                array_map(static fn(?string $p): string => trim((string)$p), $parts),
                static fn(string $p): bool => $p !== ''
            )
        );
    }

    /**
     * Describe a session: event name, day, time slot, and the id as a last
     * resort for support.
     */
    public static function session(Session $session): string
    {
        $id = (int)$session->getId();
        try {
            return sprintf(
                _T('session "%1$s" of %2$s, %3$s-%4$s (#%5$d)', 'courses'),
                $session->getEvent()->getName(),
                $session->getFormattedDateLong(),
                $session->getFormattedStartTime(),
                $session->getFormattedEndTime(),
                $id
            );
        } catch (Throwable $e) {
            Analog::log('HistoryLabel::session #' . $id . ': ' . $e->getMessage(), Analog::WARNING);
            return sprintf(_T('session #%d', 'courses'), $id);
        }
    }

    /**
     * Describe an event.
     */
    public static function event(Event $event): string
    {
        return sprintf(
            _T('event "%1$s" (#%2$d)', 'courses'),
            $event->getName(),
            (int)$event->getId()
        );
    }

    /**
     * Describe a member. The super admin has no member record and reaches
     * here as id 0 or null.
     */
    public static function member(Db $zdb, ?int $member_id): string
    {
        if ($member_id === null || $member_id <= 0) {
            return _T('super administrator', 'courses');
        }

        $name = self::memberName($zdb, $member_id);
        if ($name === '') {
            return sprintf(_T('member #%d (unknown)', 'courses'), $member_id);
        }

        return sprintf(_T('member %1$s (#%2$d)', 'courses'), $name, $member_id);
    }

    /**
     * Display name of a member, resolved with a single lightweight SELECT and
     * cached for the request. Recomposes Adherent::sname without paying for a
     * full Adherent load.
     */
    private static function memberName(Db $zdb, int $member_id): string
    {
        if (isset(self::$member_names[$member_id])) {
            return self::$member_names[$member_id];
        }

        $sname = '';
        try {
            $select = $zdb->select(Adherent::TABLE);
            $select->columns(['nom_adh', 'prenom_adh']);
            $select->limit(1)->where([Adherent::PK => $member_id]);
            $results = $zdb->execute($select);
            $row = $results->current();
            if ($row) {
                $sname = trim(
                    mb_strtoupper((string)($row->nom_adh ?? ''), 'UTF-8')
                    . ' '
                    . ucwords(mb_strtolower((string)($row->prenom_adh ?? ''), 'UTF-8'), " \t\r\n\f\v-")
                );
            }
        } catch (Throwable $e) {
            Analog::log('HistoryLabel::memberName #' . $member_id . ': ' . $e->getMessage(), Analog::WARNING);
        }

        self::$member_names[$member_id] = $sname;
        return $sname;
    }
}
