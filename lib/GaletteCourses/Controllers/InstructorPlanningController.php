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
use Galette\Core\Login;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\InstructorAvailability;
use GaletteCourses\Entity\PlanningNote;
use GaletteCourses\Entity\Session;
use GaletteCourses\Filters\SessionsList;
use GaletteCourses\InstructorPlanning;
use GaletteCourses\PluginPreferences;
use GaletteCourses\Repository\Sessions;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * The instructor planning page, see InstructorPlanning: open to every
 * instructor, each one declaring their own days on it. Staff fill in any
 * instructor's days in their place.
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
        return self::canFillInForOthers($this->login)
            || InstructorAvailabilityController::canDeclare($this->zdb, $this->login);
    }

    /**
     * Staff declare days and assign sessions on any instructor's line.
     */
    public static function canFillInForOthers(Login $login): bool
    {
        return $login->isAdmin() || $login->isStaff();
    }

    /**
     * Who writes a note under the days: the instructors on the planning and
     * the staff, each their own note. The super admin has no member record
     * to sign it with.
     */
    private function canWriteNote(): bool
    {
        $memberId = (int)$this->login->id;
        return $memberId > 0
            && (self::canFillInForOthers($this->login) || InstructorPlanning::isPlanningMember($this->zdb, $memberId));
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
        $canFillIn = self::canFillInForOthers($this->login);
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
            $assignable = !$cancelled && $needsInstructor && $s->getSessionDate() >= $today;
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
                    && $assignable
                    && !in_array($viewerId, $instructorIds[$sid] ?? [], true)
                    && $this->mayLead($event, $managedGroups),
                'assignable'       => $assignable,
            ];
        }

        // Rows are the managers of the instructor groups chosen in the
        // preferences; anyone else views the page without appearing on it.
        $members = InstructorPlanning::getMemberNames(
            $this->zdb,
            InstructorPlanning::getInstructorMemberIds($this->zdb)
        );
        $hasOwnRow = isset($members[$viewerId]);
        $dates = array_values(array_unique(array_column($sessions, 'date')));
        $status = InstructorAvailability::getStatusOnDays($this->zdb, array_keys($members), $dates);

        $planning = InstructorPlanning::build($sessions, $instructorIds, $members, $status, $viewerId);
        $notes = PlanningNote::getForDates($this->zdb, $dates);
        $myNotes = [];
        foreach ($notes as $date => $dayNotes) {
            foreach ($dayNotes as $note) {
                if ($note['member_id'] === $viewerId) {
                    $myNotes[$date] = $note['note'];
                }
            }
        }

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
                'can_declare' => $canDeclare && $hasOwnRow,
                'can_fill_in' => $canFillIn,
                'can_note'    => $this->canWriteNote(),
                'no_instructor_groups' => empty((new PluginPreferences($this->zdb))->getInstructorGroupIds()),
                'notes'       => $notes,
                'my_notes'    => $myNotes,
                'viewer_id'   => $viewerId,
            ]
        );
        return $response;
    }

    /**
     * Save, replace or (empty) remove the viewer's own note on a day.
     */
    public function doSetNote(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $post = is_array($post) ? $post : [];
        $from = trim((string)($post['from'] ?? ''));
        $returnUrl = $this->routeparser->urlFor('coursesInstructorPlanning')
            . (InstructorAvailability::isValidDate($from) ? '?from=' . $from : '');

        if (!$this->canWriteNote()) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to perform this action.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }
        $date = trim((string)($post['date'] ?? ''));
        if (!InstructorAvailability::isValidDate($date)) {
            $this->flash->addMessage('error_detected', _T('Please enter valid dates.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }
        if ($date < date('Y-m-d')) {
            $this->flash->addMessage('error_detected', _T('This period is already over.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $note = PlanningNote::normalize((string)($post['note'] ?? ''));
        if (!PlanningNote::set($this->zdb, $date, (int)$this->login->id, $note)) {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        } else {
            $this->flash->addMessage(
                'success_detected',
                $note === '' ? _T('Your comment has been removed.', 'courses') : _T('Your comment has been saved.', 'courses')
            );
        }
        return $response->withStatus(302)->withHeader('Location', $returnUrl);
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
