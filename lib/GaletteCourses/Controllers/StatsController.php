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

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function show(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = !empty($params['stats_from']) ? $params['stats_from'] : date('Y-01-01');
        $dateTo   = !empty($params['stats_to']) ? $params['stats_to'] : date('Y-m-d');

        // Clamp: dateTo >= dateFrom
        if ($dateTo < $dateFrom) {
            $dateTo = $dateFrom;
        }

        $memberActivity = $this->getMemberActivityByPeriod($dateFrom, $dateTo);

        $stats = [
            'fill_rates'             => $this->getAverageFillRates(),
            'registrations_by_month' => $this->getRegistrationsByMonth(),
            'top_events'             => $this->getTopEvents(),
            'recent_activity'        => $this->getRecentActivity(),
            'summary'                => $this->getSummary(),
            'active_members'         => $memberActivity['active'],
            'inactive_members'       => $memberActivity['inactive'],
        ];

        $this->view->render(
            $response,
            $this->getTemplate('pages/stats'),
            [
                'page_title'    => _T('Statistics', 'courses'),
                'stats'         => $stats,
                'stats_from'    => $dateFrom,
                'stats_to'      => $dateTo,
                'require_charts' => true,
            ]
        );
        return $response;
    }

    /**
     * Get average fill rate per event (events with capacity)
     *
     * @return array<int, array{name: string, avg_fill: float, session_count: int}>
     */
    private function getAverageFillRates(): array
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
     * Get registrations count grouped by month (last 12 months)
     *
     * @return array<string, int>
     */
    private function getRegistrationsByMonth(): array
    {
        $data = [];
        try {
            // DATE_FORMAT is MySQL-only; TO_CHAR is the PostgreSQL equivalent.
            // Built once and reused for SELECT and GROUP BY so the two stay aligned.
            $monthExpr = $this->zdb->isPostgres()
                ? "TO_CHAR(registration_date, 'YYYY-MM')"
                : "DATE_FORMAT(registration_date, '%Y-%m')";

            $select = $this->zdb->select(Registration::TABLE);
            $select->columns([
                'month' => new Expression($monthExpr),
                'count' => new Expression('COUNT(*)'),
            ]);
            $select->where->equalTo('status', Registration::STATUS_REGISTERED);
            $select->where->greaterThanOrEqualTo(
                'registration_date',
                date('Y-m-d', strtotime('-12 months'))
            );
            $select->group([new Expression($monthExpr)]);
            $select->order('month ASC');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $data[(string)$r->month] = (int)$r->count;
            }
        } catch (Throwable $e) {
            Analog::log('Error getting registrations by month: ' . $e->getMessage(), Analog::ERROR);
        }
        return $data;
    }

    /**
     * Get top events by total registrations
     *
     * @return array<int, array{name: string, total_registrations: int}>
     */
    private function getTopEvents(): array
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
                'total_registrations' => new Expression('COUNT(*)'),
            ]);
            $select->where->equalTo('r.status', Registration::STATUS_REGISTERED);
            $select->group(['s.event_id', 'e.name']);
            $select->order('total_registrations DESC');
            $select->limit(10);

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $events[(int)$r->event_id] = [
                    'name' => (string)$r->name,
                    'total_registrations' => (int)$r->total_registrations,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting top events: ' . $e->getMessage(), Analog::ERROR);
        }
        return $events;
    }

    /**
     * Get recent member activity (last participation date per member)
     *
     * @return array<int, array{member_id: int, member_name: string, last_date: string, total_sessions: int}>
     */
    private function getRecentActivity(): array
    {
        $activity = [];
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
            $select->where->equalTo('r.status', Registration::STATUS_REGISTERED);
            $select->where->lessThanOrEqualTo('s.session_date', date('Y-m-d'));
            $select->group(['r.member_id', 'a.nom_adh', 'a.prenom_adh']);
            $select->order('last_date DESC');
            $select->limit(20);

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                $activity[] = [
                    'member_id' => (int)$r->member_id,
                    'member_name' => $name ?: _T('Unknown member', 'courses'),
                    'last_date' => (string)$r->last_date,
                    'total_sessions' => (int)$r->total_sessions,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting recent activity: ' . $e->getMessage(), Analog::ERROR);
        }
        return $activity;
    }

    /**
     * Get all active adherents split into active/inactive for the given period.
     * A single LEFT JOIN query guarantees every active adherent appears in exactly one list.
     *
     * Active  = at least one non-cancelled registration on a session in the period.
     * Inactive = no such registration.
     *
     * @return array{active: array<int, array{member_id: int, member_name: string, nickname: string, session_count: int, events: string}>, inactive: array<int, array{member_id: int, member_name: string, nickname: string}>}
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
            $sql = "SELECT a.id_adh, a.nom_adh, a.prenom_adh, a.pseudo_adh,
                        COUNT(DISTINCT s.$pkSess) AS session_count,
                        COUNT(DISTINCT CASE WHEN r.status IN ('$statusAttended', '$statusPresentUnregistered')
                            THEN s.$pkSess END) AS attendance_count,
                        $concatExpr AS events
                    FROM $tAdh a
                    LEFT JOIN $tReg r
                        ON r.member_id = a.id_adh
                        AND r.status != ?
                    LEFT JOIN $tSess s
                        ON s.$pkSess = r.session_id
                        AND s.session_date BETWEEN ? AND ?
                    LEFT JOIN $tEvt e
                        ON e.$pkEvt = s.event_id
                    WHERE a.activite_adh
                    GROUP BY a.id_adh, a.nom_adh, a.prenom_adh, a.pseudo_adh
                    ORDER BY a.nom_adh ASC, a.prenom_adh ASC";

            $rows = $this->zdb->db->query($sql, [
                Registration::STATUS_CANCELLED,
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
                        'member_id'        => $id,
                        'member_name'      => $label,
                        'nickname'         => $nickname,
                        'session_count'    => $count,
                        'attendance_count' => (int)$r['attendance_count'],
                        'events'           => (string)($r['events'] ?? ''),
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
     * Get global summary stats
     *
     * @return array{total_events: int, total_sessions: int, total_registrations: int, upcoming_sessions: int}
     */
    private function getSummary(): array
    {
        $summary = [
            'total_events' => 0,
            'total_sessions' => 0,
            'total_registrations' => 0,
            'upcoming_sessions' => 0,
        ];
        try {
            $select = $this->zdb->select(Event::TABLE);
            $select->columns(['count' => new Expression('COUNT(*)')]);
            $results = $this->zdb->execute($select);
            $summary['total_events'] = (int)$results->current()->count;

            $select = $this->zdb->select(Session::TABLE);
            $select->columns(['count' => new Expression('COUNT(*)')]);
            $results = $this->zdb->execute($select);
            $summary['total_sessions'] = (int)$results->current()->count;

            $select = $this->zdb->select(Registration::TABLE);
            $select->columns(['count' => new Expression('COUNT(*)')]);
            $select->where->equalTo('status', Registration::STATUS_REGISTERED);
            $results = $this->zdb->execute($select);
            $summary['total_registrations'] = (int)$results->current()->count;

            $select = $this->zdb->select(Session::TABLE);
            $select->columns(['count' => new Expression('COUNT(*)')]);
            $select->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));
            $select->where->equalTo('status', 'open');
            $results = $this->zdb->execute($select);
            $summary['upcoming_sessions'] = (int)$results->current()->count;
        } catch (Throwable $e) {
            Analog::log('Error getting summary stats: ' . $e->getMessage(), Analog::ERROR);
        }
        return $summary;
    }
}
