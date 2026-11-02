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
use GaletteCourses\Entity\InstructorAvailability;
use GaletteCourses\Entity\Session;
use GaletteCourses\Filters\SessionsList;
use GaletteCourses\InstructorPlanning;
use GaletteCourses\Repository\Sessions;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * The instructor planning page, see InstructorPlanning: open to every
 * instructor, each one declaring their own days on it.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class InstructorPlanningController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Instructors, potential or assigned, plus the staff who appoint them.
     */
    public function canView(): bool
    {
        return $this->login->isAdmin()
            || $this->login->isStaff()
            || InstructorAvailabilityController::canDeclare($this->zdb, $this->login);
    }

    public function show(Request $request, Response $response): Response
    {
        if (!$this->canView()) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to perform this action.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $today = date('Y-m-d');
        $from = (string)($request->getQueryParams()['from'] ?? '');
        if (!InstructorAvailability::isValidDate($from) || $from < $today) {
            $from = $today;
        }
        $to = date('Y-m-d', (int)strtotime($from . ' +' . (InstructorPlanning::WEEKS * 7 - 1) . ' days'));

        $filters = new SessionsList();
        $filters->date_from = $from;
        $filters->date_to = $to;
        $filters->show = 0; // every session of the weeks, no paging
        $repo = new Sessions($this->zdb, $this->login, $filters);
        $list = $repo->getList();

        $viewerId = (int)$this->login->id;
        $instructorIds = InstructorPlanning::getInstructorIds($this->zdb, array_keys($list));
        $canDeclare = InstructorAvailabilityController::canDeclare($this->zdb, $this->login);
        $canVolunteer = $viewerId > 0
            && ($this->login->isAdmin() || $this->login->isStaff() || $this->login->isGroupManager());
        $managedGroups = $canVolunteer ? $this->login->getManagedGroups() : [];

        $events = [];
        $sessions = [];
        foreach ($list as $sid => $s) {
            $eid = $s->getEventId();
            if (!isset($events[$eid])) {
                $events[$eid] = new Event($this->zdb, $eid);
                $events[$eid]->loadGroups();
            }
            $event = $events[$eid];
            $cancelled = $s->getStatus() === Session::STATUS_CANCELLED;
            $needsInstructor = !$event->isInstructorNotNeeded();
            $sessions[] = [
                'id'               => (int)$sid,
                'date'             => $s->getSessionDate(),
                'start'            => $s->getStartTime(),
                'end'              => $s->getEndTime(),
                'event_name'       => $event->getName(),
                'cancelled'        => $cancelled,
                'needs_instructor' => $needsInstructor,
                'registrations'    => $s->getCurrentRegistrations(),
                'capacity'         => $s->getMaxCapacity(),
                'can_volunteer'    => $canVolunteer
                    && !$cancelled
                    && $needsInstructor
                    && $s->getSessionDate() >= $today
                    && !in_array($viewerId, $instructorIds[$sid] ?? [], true)
                    && $this->mayLead($event, $managedGroups),
            ];
        }

        $memberIds = InstructorPlanning::getGroupManagerIds($this->zdb);
        foreach ($instructorIds as $ids) {
            $memberIds = array_merge($memberIds, $ids);
        }
        if ($canDeclare) {
            $memberIds[] = $viewerId;
        }
        $members = InstructorPlanning::getMemberNames($this->zdb, $memberIds);
        $dates = array_values(array_unique(array_column($sessions, 'date')));
        $status = InstructorAvailability::getStatusOnDays($this->zdb, array_keys($members), $dates);

        $planning = InstructorPlanning::build($sessions, $instructorIds, $members, $status, $viewerId);

        $previous = date('Y-m-d', (int)strtotime($from . ' -' . (InstructorPlanning::WEEKS * 7) . ' days'));
        $this->view->render(
            $response,
            $this->getTemplate('pages/instructor_planning'),
            [
                'page_title'  => _T('Instructor planning', 'courses'),
                'days'        => $planning['days'],
                'rows'        => $planning['rows'],
                'from'        => $from,
                'to'          => $to,
                'today'       => $today,
                'previous'    => $from > $today ? max($previous, $today) : null,
                'next'        => date('Y-m-d', (int)strtotime($to . ' +1 day')),
                'can_declare' => $canDeclare,
                'viewer_id'   => $viewerId,
            ]
        );
        return $response;
    }

    /**
     * Same rule as volunteering: staff lead anything, a group manager the
     * events of their groups or open to all.
     *
     * @param int[] $managedGroups
     */
    private function mayLead(Event $event, array $managedGroups): bool
    {
        if ($this->login->isAdmin() || $this->login->isStaff()) {
            return true;
        }
        $eventGroups = $event->getGroups();
        return empty($eventGroups) || !empty(array_intersect($eventGroups, $managedGroups));
    }
}
