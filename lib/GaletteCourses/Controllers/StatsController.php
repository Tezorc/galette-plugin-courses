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

namespace GaletteCourses\Controllers;

use Galette\Controllers\AbstractController;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Registration;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use Laminas\Db\Sql\Expression;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class StatsController extends AbstractController
{
    use PluginControllerTrait;
    use CoursesAclGuard;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Statuses counted as a presence: what the statistics track is who
     * actually came, registered beforehand (`attended`) or not
     * (`present_unregistered`). Registered-but-unmarked, absent and
     * cancelled rows are left out.
     *
     * @var array<int, string>
     */
    private const PRESENCE_STATUSES = [
        Registration::STATUS_ATTENDED,
        Registration::STATUS_PRESENT_UNREGISTERED,
    ];

    public function show(Request $request, Response $response): Response
    {
        // Route ACL is 'member' on purpose: a moniteur is neither staff nor
        // groupmanager, so the real gate lives here (admin / staff / instructor
        // of at least one session). Do not tighten the route back to 'staff'.
        $deny = $this->denyUnlessStaffOrInstructor(
            $response,
            $this->routeparser->urlFor('coursesSessions'),
            _T('You do not have permission to view statistics.', 'courses')
        );
        if ($deny !== null) {
            return $deny;
        }

        $params = $request->getQueryParams();
        $dateFrom = !empty($params['stats_from']) ? $params['stats_from'] : date('Y-01-01');
        $dateTo   = !empty($params['stats_to']) ? $params['stats_to'] : date('Y-m-d');

        // Clamp: dateTo >= dateFrom
        if ($dateTo < $dateFrom) {
            $dateTo = $dateFrom;
        }

        $memberActivity = $this->getMemberActivityByPeriod($dateFrom, $dateTo);

        $activeCount   = count($memberActivity['active']);
        $inactiveCount = count($memberActivity['inactive']);
        $totalAdherents = $activeCount + $inactiveCount;
        $participationRate = $totalAdherents > 0
            ? round($activeCount * 100 / $totalAdherents, 1)
            : 0.0;

        // Every figure on the page is scoped to the selected period, on the
        // session date (not the registration date) so the counters, charts
        // and member lists all describe the same set of sessions.
        $stats = [
            'fill_rates'             => $this->getAverageFillRates($dateFrom, $dateTo),
            'registrations_by_month' => $this->getRegistrationsByMonth($dateFrom, $dateTo),
            'top_events'             => $this->getTopEvents($dateFrom, $dateTo),
            'top_members'            => $this->getTopMembers($dateFrom, $dateTo),
            'summary'                => $this->getSummary($dateFrom, $dateTo),
            'active_members'         => $memberActivity['active'],
            'inactive_members'       => $memberActivity['inactive'],
            'participation_rate'     => $participationRate,
            'total_adherents'        => $totalAdherents,
            'instructor_activity'    => $this->getInstructorActivityByPeriod($dateFrom, $dateTo),
        ];

        $this->view->render(
            $response,
            $this->getTemplate('pages/stats'),
            [
                'page_title'    => _T('Statistics', 'courses'),
                'stats'         => $stats,
                'stats_from'    => $dateFrom,
                'stats_to'      => $dateTo,
                // Member fiches are staff-only in the core: for a moniteur the
                // names are rendered as plain text rather than dead links.
                'can_view_members' => $this->login->isAdmin() || $this->login->isStaff(),
                'require_charts' => true,
            ]
        );
        return $response;
    }

    /**
     * Get average fill rate per event (events with capacity) over the sessions of the period
     *
     * @return array<int, array{name: string, avg_fill: float, session_count: int}>
     */
    private function getAverageFillRates(string $dateFrom, string $dateTo): array
    {
        $rates = [];
        try {
            $select = $this->zdb->select(Session::TABLE, 's');
            $select->join(
                ['e' => PREFIX_DB . Event::TABLE],
                's.event_id = e.' . Event::PK,
                ['name']
            );
            $select->columns([
                'event_id',
                'avg_fill' => new Expression('ROUND(AVG(s.current_registrations * 100.0 / s.max_capacity), 1)'),
                'session_count' => new Expression('COUNT(*)'),
            ]);
            $select->where->isNotNull('s.max_capacity');
            $select->where->greaterThan('s.max_capacity', 0);
            $select->where->between('s.session_date', $dateFrom, $dateTo);
            $select->group(['s.event_id', 'e.name']);
            $select->order('avg_fill DESC');
            $select->limit(10);

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $rates[(int)$r->event_id] = [
                    'name' => (string)$r->name,
                    'avg_fill' => (float)$r->avg_fill,
                    'session_count' => (int)$r->session_count,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting fill rates: ' . $e->getMessage(), Analog::ERROR);
        }
        return $rates;
    }

    /**
     * Get presences count grouped by session month over the period
     *
     * @return array<string, int>
     */
    private function getRegistrationsByMonth(string $dateFrom, string $dateTo): array
    {
        $data = [];
        try {
            // DATE_FORMAT is MySQL-only; TO_CHAR is the PostgreSQL equivalent.
            // Built once and reused for SELECT and GROUP BY so the two stay aligned.
            $monthExpr = $this->zdb->isPostgres()
                ? "TO_CHAR(s.session_date, 'YYYY-MM')"
                : "DATE_FORMAT(s.session_date, '%Y-%m')";

            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                []
            );
            $select->columns([
                'month' => new Expression($monthExpr),
                'count' => new Expression('COUNT(*)'),
            ]);
            $select->where->in('r.status', self::PRESENCE_STATUSES);
            $select->where->between('s.session_date', $dateFrom, $dateTo);
            $select->group([new Expression($monthExpr)]);
            $select->order('month ASC');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $data[(string)$r->month] = (int)$r->count;
            }
        } catch (Throwable $e) {
            Analog::log('Error getting presences by month: ' . $e->getMessage(), Analog::ERROR);
        }
        return $data;
    }

    /**
     * Get top events by presences on the sessions of the period
     *
     * @return array<int, array{name: string, total_presences: int}>
     */
    private function getTopEvents(string $dateFrom, string $dateTo): array
    {
        $events = [];
        try {
            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                ['event_id']
            );
            $select->join(
                ['e' => PREFIX_DB . Event::TABLE],
                's.event_id = e.' . Event::PK,
                ['name']
            );
            $select->columns([
                'total_presences' => new Expression('COUNT(*)'),
            ]);
            $select->where->in('r.status', self::PRESENCE_STATUSES);
            $select->where->between('s.session_date', $dateFrom, $dateTo);
            $select->group(['s.event_id', 'e.name']);
            $select->order('total_presences DESC');
            $select->limit(10);

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $events[(int)$r->event_id] = [
                    'name' => (string)$r->name,
                    'total_presences' => (int)$r->total_presences,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting top events: ' . $e->getMessage(), Analog::ERROR);
        }
        return $events;
    }

    /**
     * Get the 10 most regular members over the period: most presences first,
     * then the most recent presence to break ties
     *
     * @return array<int, array{member_id: int, member_name: string, last_date: string, total_sessions: int}>
     */
    private function getTopMembers(string $dateFrom, string $dateTo): array
    {
        $members = [];
        try {
            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                []
            );
            $select->join(
                ['a' => PREFIX_DB . \Galette\Entity\Adherent::TABLE],
                'r.member_id = a.id_adh',
                ['nom_adh', 'prenom_adh']
            );
            $select->columns([
                'member_id',
                'last_date' => new Expression('MAX(s.session_date)'),
                'total_sessions' => new Expression('COUNT(DISTINCT r.session_id)'),
            ]);
            // Upper bound = min(dateTo, today): a participation is a session
            // that already took place, even if the period runs into the future.
            $today = date('Y-m-d');
            $select->where->in('r.status', self::PRESENCE_STATUSES);
            $select->where->between('s.session_date', $dateFrom, $dateTo < $today ? $dateTo : $today);
            $select->group(['r.member_id', 'a.nom_adh', 'a.prenom_adh']);
            $select->order(['total_sessions DESC', 'last_date DESC', 'a.nom_adh ASC', 'a.prenom_adh ASC']);
            $select->limit(10);

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                $members[] = [
                    'member_id' => (int)$r->member_id,
                    'member_name' => $name ?: _T('Unknown member', 'courses'),
                    'last_date' => (string)$r->last_date,
                    'total_sessions' => (int)$r->total_sessions,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting top members: ' . $e->getMessage(), Analog::ERROR);
        }
        return $members;
    }

    /**
     * Get all active adherents split into active/inactive for the given period.
     * A single LEFT JOIN query guarantees every active adherent appears in exactly one list.
     *
     * Active  = at least one presence (attended or present_unregistered) on a
     *           session in the period; session_count = sessions attended.
     * Inactive = no such presence, even if registered (absent, not marked).
     *
     * @return array{active: array<int, array{member_id: int, member_name: string, nickname: string, session_count: int, attended_count: int, present_unregistered_count: int, events: string}>, inactive: array<int, array{member_id: int, member_name: string, nickname: string}>}
     */
    private function getMemberActivityByPeriod(string $dateFrom, string $dateTo): array
    {
        $result = ['active' => [], 'inactive' => []];
        try {
            $tAdh   = PREFIX_DB . \Galette\Entity\Adherent::TABLE;
            $tReg   = PREFIX_DB . Registration::TABLE;
            $tSess  = PREFIX_DB . Session::TABLE;
            $tEvt   = PREFIX_DB . Event::TABLE;
            $pkSess = Session::PK;
            $pkEvt  = Event::PK;

            $statusAttended           = Registration::STATUS_ATTENDED;
            $statusPresentUnregistered = Registration::STATUS_PRESENT_UNREGISTERED;

            // GROUP_CONCAT is MySQL-only; STRING_AGG is the PostgreSQL equivalent
            // (supports ORDER BY inside the aggregate since PG 9.x).
            $concatExpr = $this->zdb->isPostgres()
                ? "STRING_AGG(DISTINCT e.name, ', ' ORDER BY e.name)"
                : "GROUP_CONCAT(DISTINCT e.name ORDER BY e.name SEPARATOR ', ')";

            // `WHERE a.activite_adh` (no `= 1`) is truthy in both MySQL (tinyint(1))
            // and PostgreSQL (boolean) — `= 1` would fail under PostgreSQL's strict typing.
            // attended_count = membre inscrit ET venu (status attended).
            // present_unregistered_count = présent sans inscription préalable (walk-in).
            $sql = "SELECT a.id_adh, a.nom_adh, a.prenom_adh, a.pseudo_adh,
                        COUNT(DISTINCT s.$pkSess) AS session_count,
                        COUNT(DISTINCT CASE WHEN r.status = '$statusAttended'
                            THEN s.$pkSess END) AS attended_count,
                        COUNT(DISTINCT CASE WHEN r.status = '$statusPresentUnregistered'
                            THEN s.$pkSess END) AS present_unregistered_count,
                        $concatExpr AS events
                    FROM $tAdh a
                    LEFT JOIN $tReg r
                        ON r.member_id = a.id_adh
                        AND r.status IN (?, ?)
                    LEFT JOIN $tSess s
                        ON s.$pkSess = r.session_id
                        AND s.session_date BETWEEN ? AND ?
                    LEFT JOIN $tEvt e
                        ON e.$pkEvt = s.event_id
                    WHERE a.activite_adh
                    GROUP BY a.id_adh, a.nom_adh, a.prenom_adh, a.pseudo_adh
                    ORDER BY a.nom_adh ASC, a.prenom_adh ASC";

            $rows = $this->zdb->db->query($sql, [
                ...self::PRESENCE_STATUSES,
                $dateFrom,
                $dateTo,
            ]);

            foreach ($rows as $r) {
                $name  = trim(($r['prenom_adh'] ?? '') . ' ' . ($r['nom_adh'] ?? ''));
                $label = $name ?: _T('Unknown member', 'courses');
                $id    = (int)$r['id_adh'];
                $nickname = (string)($r['pseudo_adh'] ?? '');
                $count    = (int)$r['session_count'];

                if ($count > 0) {
                    $result['active'][] = [
                        'member_id'                  => $id,
                        'member_name'                => $label,
                        'nickname'                   => $nickname,
                        'session_count'              => $count,
                        'attended_count'             => (int)$r['attended_count'],
                        'present_unregistered_count' => (int)$r['present_unregistered_count'],
                        'events'                     => (string)($r['events'] ?? ''),
                    ];
                } else {
                    $result['inactive'][] = [
                        'member_id'   => $id,
                        'member_name' => $label,
                        'nickname'    => $nickname,
                    ];
                }
            }
        } catch (Throwable $e) {
            Analog::log('Error getting member activity by period: ' . $e->getMessage(), Analog::ERROR);
        }
        return $result;
    }

    /**
     * Get instructor involvement for the given period.
     * Lists every member assigned as instructor on at least one non-cancelled
     * session in the period, with the number of sessions run, the number of
     * distinct months in which they ran sessions (a regularity indicator over
     * the year) and the events they covered. Ordered by session count desc.
     *
     * @return array<int, array{member_id: int, member_name: string, nickname: string, session_count: int, active_months: int, events: string}>
     */
    private function getInstructorActivityByPeriod(string $dateFrom, string $dateTo): array
    {
        $instructors = [];
        try {
            $tAdh   = PREFIX_DB . \Galette\Entity\Adherent::TABLE;
            $tInstr = PREFIX_DB . SessionInstructor::TABLE;
            $tSess  = PREFIX_DB . Session::TABLE;
            $tEvt   = PREFIX_DB . Event::TABLE;
            $pkSess = Session::PK;
            $pkEvt  = Event::PK;

            $statusCancelled = Session::STATUS_CANCELLED;

            // DATE_FORMAT is MySQL-only; TO_CHAR is the PostgreSQL equivalent.
            $monthExpr = $this->zdb->isPostgres()
                ? "TO_CHAR(s.session_date, 'YYYY-MM')"
                : "DATE_FORMAT(s.session_date, '%Y-%m')";

            // GROUP_CONCAT is MySQL-only; STRING_AGG is the PostgreSQL equivalent.
            $concatExpr = $this->zdb->isPostgres()
                ? "STRING_AGG(DISTINCT e.name, ', ' ORDER BY e.name)"
                : "GROUP_CONCAT(DISTINCT e.name ORDER BY e.name SEPARATOR ', ')";

            // Borne haute = min(dateTo, today) : on ne compte que les seances
            // effectivement assurees (passees), meme si le filtre deborde sur le futur.
            $today    = date('Y-m-d');
            $dateToEff = $dateTo < $today ? $dateTo : $today;

            $sql = "SELECT a.id_adh, a.nom_adh, a.prenom_adh, a.pseudo_adh,
                        COUNT(DISTINCT s.$pkSess) AS session_count,
                        COUNT(DISTINCT $monthExpr) AS active_months,
                        $concatExpr AS events
                    FROM $tInstr si
                    JOIN $tSess s
                        ON s.$pkSess = si.session_id
                        AND s.session_date BETWEEN ? AND ?
                        AND s.status != ?
                    JOIN $tAdh a
                        ON a.id_adh = si.member_id
                    LEFT JOIN $tEvt e
                        ON e.$pkEvt = s.event_id
                    GROUP BY a.id_adh, a.nom_adh, a.prenom_adh, a.pseudo_adh
                    ORDER BY session_count DESC, a.nom_adh ASC, a.prenom_adh ASC";

            $rows = $this->zdb->db->query($sql, [
                $dateFrom,
                $dateToEff,
                $statusCancelled,
            ]);

            foreach ($rows as $r) {
                $name  = trim(($r['prenom_adh'] ?? '') . ' ' . ($r['nom_adh'] ?? ''));
                $label = $name ?: _T('Unknown member', 'courses');
                $instructors[] = [
                    'member_id'     => (int)$r['id_adh'],
                    'member_name'   => $label,
                    'nickname'      => (string)($r['pseudo_adh'] ?? ''),
                    'session_count' => (int)$r['session_count'],
                    'active_months' => (int)$r['active_months'],
                    'events'        => (string)($r['events'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting instructor activity by period: ' . $e->getMessage(), Analog::ERROR);
        }
        return $instructors;
    }

    /**
     * Get summary stats over the period
     *
     * Events = events with at least one session in the period. Upcoming =
     * open sessions between max(dateFrom, today) and dateTo, hence 0 for a
     * period entirely in the past.
     *
     * @return array{total_events: int, total_sessions: int, total_presences: int, upcoming_sessions: int}
     */
    private function getSummary(string $dateFrom, string $dateTo): array
    {
        $summary = [
            'total_events' => 0,
            'total_sessions' => 0,
            'total_presences' => 0,
            'upcoming_sessions' => 0,
        ];
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->columns([
                'events' => new Expression('COUNT(DISTINCT event_id)'),
                'sessions' => new Expression('COUNT(*)'),
            ]);
            $select->where->between('session_date', $dateFrom, $dateTo);
            $row = $this->zdb->execute($select)->current();
            $summary['total_events'] = (int)$row->events;
            $summary['total_sessions'] = (int)$row->sessions;

            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                []
            );
            $select->columns(['count' => new Expression('COUNT(*)')]);
            $select->where->in('r.status', self::PRESENCE_STATUSES);
            $select->where->between('s.session_date', $dateFrom, $dateTo);
            $results = $this->zdb->execute($select);
            $summary['total_presences'] = (int)$results->current()->count;

            $today = date('Y-m-d');
            $upcomingFrom = $dateFrom > $today ? $dateFrom : $today;
            if ($upcomingFrom <= $dateTo) {
                $select = $this->zdb->select(Session::TABLE);
                $select->columns(['count' => new Expression('COUNT(*)')]);
                $select->where->between('session_date', $upcomingFrom, $dateTo);
                $select->where->equalTo('status', Session::STATUS_OPEN);
                $results = $this->zdb->execute($select);
                $summary['upcoming_sessions'] = (int)$results->current()->count;
            }
        } catch (Throwable $e) {
            Analog::log('Error getting summary stats: ' . $e->getMessage(), Analog::ERROR);
        }
        return $summary;
    }
}
