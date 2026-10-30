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
use Galette\Entity\Adherent;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\InstructorSolicitation;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\HistoryLabel;
use GaletteCourses\MemberPreferences;
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\PluginPreferences;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * Ask an instructor to lead a session, and let them answer.
 *
 * The person looking for an instructor (group manager of the event, or a
 * session manager) solicits one of the eligible instructors from the session
 * page. The instructor accepts — they are assigned at once — or declines,
 * from the "Volunteer for a session" tab. Either way the requester is told.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class InstructorSolicitationController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * May the logged-in user solicit an instructor for this session?
     * Admin, staff, an instructor of the session, or a manager of one of the
     * event's groups (any group manager when the event is not restricted).
     */
    public static function canSolicit(\Galette\Core\Db $zdb, \Galette\Core\Login $login, Session $session, Event $event): bool
    {
        if ($login->isAdmin() || $login->isStaff()) {
            return true;
        }
        $memberId = (int)$login->id;
        if ($memberId <= 0) {
            return false;
        }
        if (SessionInstructor::isInstructor($zdb, (int)$session->getId(), $memberId)) {
            return true;
        }
        if (!$login->isGroupManager()) {
            return false;
        }
        $event->loadGroups();
        $eventGroups = $event->getGroups();
        if (empty($eventGroups)) {
            return true;
        }
        return !empty(array_intersect($eventGroups, $login->getManagedGroups()));
    }

    public function doSolicit(Request $request, Response $response, int $id): Response
    {
        $returnUrl = $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]);

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }
        $event = $session->getEvent();

        if (!self::canSolicit($this->zdb, $this->login, $session, $event)) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to perform this action.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $error = $this->sessionUnavailableMessage($session, $event);
        if ($error !== null) {
            $this->flash->addMessage('error_detected', $error);
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $post = $request->getParsedBody();
        $memberId = (int)(is_array($post) ? ($post['member_id'] ?? 0) : 0);
        $eligible = SessionInstructor::getEligibleInstructors($this->zdb, $event);
        if ($memberId <= 0 || !isset($eligible[$memberId])) {
            $this->flash->addMessage('error_detected', _T('This member cannot lead this session.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }
        if ($memberId === (int)$this->login->id) {
            $this->flash->addMessage('error_detected', _T('To lead this session yourself, volunteer as instructor.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }
        if (SessionInstructor::isInstructor($this->zdb, $id, $memberId)) {
            $this->flash->addMessage('warning_detected', _T('This member is already an instructor for this session.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        if (!InstructorSolicitation::solicit($this->zdb, $id, $memberId, (int)$this->login->id)) {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $this->history->add(
            _T('[Courses] Instructor solicited for session', 'courses'),
            HistoryLabel::join(
                HistoryLabel::session($session),
                HistoryLabel::member($this->zdb, $memberId)
            )
        );

        $requester = (int)$this->login->id > 0
            ? (string)(new Adherent($this->zdb, (int)$this->login->id))->sname
            : _T('The club', 'courses');
        $mailed = $this->notifier()->notifyInstructorSolicitation($session, $event, $memberId, $requester);

        $this->flash->addMessage(
            'success_detected',
            $mailed
                ? sprintf(_T('%s has been asked by email to lead this session.', 'courses'), $eligible[$memberId])
                : sprintf(_T('The request has been recorded for %s, who will see it on their page (no email could be sent).', 'courses'), $eligible[$memberId])
        );
        return $response->withStatus(302)->withHeader('Location', $returnUrl);
    }

    public function doAccept(Request $request, Response $response, int $id): Response
    {
        $returnUrl = $this->routeparser->urlFor('coursesMyInstructorSessions');

        $solicitation = $this->loadOwnPending($id);
        if ($solicitation === null) {
            return $response->withStatus(302)->withHeader('Location', $returnUrl . '#tab=browse');
        }

        $session = new Session($this->zdb, $solicitation->getSessionId());
        $event = $session->getEvent();
        $memberId = (int)$this->login->id;

        $error = $this->sessionUnavailableMessage($session, $event);
        if ($error === null && SessionInstructor::hasInstructor($this->zdb, (int)$session->getId())) {
            // Someone else took the session first: the request no longer stands.
            $solicitation->answer(InstructorSolicitation::STATUS_CANCELLED);
            $error = _T('Another instructor has been found for this session in the meantime.', 'courses');
        }
        if ($error === null && SessionInstructor::hasOverlappingSession($this->zdb, $memberId, $session->getSessionDate(), $session->getStartTime(), $session->getEndTime(), (int)$session->getId())) {
            $error = _T('You already run another session at the same time on this day.', 'courses');
        }
        if ($error !== null) {
            $this->flash->addMessage('error_detected', $error);
            return $response->withStatus(302)->withHeader('Location', $returnUrl . '#tab=browse');
        }

        $instructor = new SessionInstructor($this->zdb);
        $instructor->setSessionId((int)$session->getId());
        $instructor->setMemberId($memberId);
        $instructor->setAssignedBy($solicitation->getRequestedBy() ?? $memberId);
        try {
            $stored = $instructor->store();
        } catch (\Throwable $e) {
            $stored = false;
        }
        if (!$stored) {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl . '#tab=browse');
        }
        $solicitation->answer(InstructorSolicitation::STATUS_ACCEPTED);

        $this->history->add(
            _T('[Courses] Instructor accepted to lead session', 'courses'),
            HistoryLabel::join(
                HistoryLabel::session($session),
                HistoryLabel::member($this->zdb, $memberId)
            )
        );

        $instructorName = (string)(new Adherent($this->zdb, $memberId))->sname;
        $notifier = $this->notifier();
        // Same follow-up as a volunteer on an instructor-less session: the
        // members are told (weekly digest) that the session now has its instructor.
        $notifier->notifyInstructorAssigned($session, $event, $instructorName);
        if ($solicitation->getRequestedBy() !== null) {
            $notifier->notifySolicitationAnswer($session, $event, $solicitation->getRequestedBy(), $instructorName, true);
        }

        $this->flash->addMessage('success_detected', _T('You have been assigned as instructor.', 'courses'));
        return $response->withStatus(302)->withHeader('Location', $returnUrl . '#tab=mine');
    }

    public function doDecline(Request $request, Response $response, int $id): Response
    {
        $returnUrl = $this->routeparser->urlFor('coursesMyInstructorSessions') . '#tab=browse';

        $solicitation = $this->loadOwnPending($id);
        if ($solicitation === null) {
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        if (!$solicitation->answer(InstructorSolicitation::STATUS_DECLINED)) {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $session = new Session($this->zdb, $solicitation->getSessionId());
        $memberId = (int)$this->login->id;
        $this->history->add(
            _T('[Courses] Instructor declined to lead session', 'courses'),
            HistoryLabel::join(
                HistoryLabel::session($session),
                HistoryLabel::member($this->zdb, $memberId)
            )
        );
        if ($session->getId() !== null && $solicitation->getRequestedBy() !== null) {
            $this->notifier()->notifySolicitationAnswer(
                $session,
                $session->getEvent(),
                $solicitation->getRequestedBy(),
                (string)(new Adherent($this->zdb, $memberId))->sname,
                false
            );
        }

        $this->flash->addMessage('success_detected', _T('You declined the request. Whoever asked has been told.', 'courses'));
        return $response->withStatus(302)->withHeader('Location', $returnUrl);
    }

    /**
     * Load a pending request addressed to the logged-in member, or flash why not.
     */
    private function loadOwnPending(int $id): ?InstructorSolicitation
    {
        $solicitation = new InstructorSolicitation($this->zdb, $id);
        if ($solicitation->getId() === null || $solicitation->getMemberId() !== (int)$this->login->id) {
            $this->flash->addMessage('error_detected', _T('Request not found.', 'courses'));
            return null;
        }
        if (!$solicitation->isPending()) {
            $this->flash->addMessage('warning_detected', _T('You have already answered this request.', 'courses'));
            return null;
        }
        return $solicitation;
    }

    /**
     * Why nobody can be asked to lead this session any more, or null.
     */
    private function sessionUnavailableMessage(Session $session, Event $event): ?string
    {
        if ($session->getId() === null) {
            return _T('Session not found.', 'courses');
        }
        if ($session->getStatus() === Session::STATUS_CANCELLED) {
            return _T('This session has been cancelled.', 'courses');
        }
        if ($session->getSessionDate() < date('Y-m-d')) {
            return _T('This session is already over.', 'courses');
        }
        if ($event->isInstructorNotNeeded()) {
            return _T('This event does not need an instructor.', 'courses');
        }
        return null;
    }

    private function notifier(): CourseNotification
    {
        return new CourseNotification(
            $this->zdb,
            $this->preferences,
            new PluginPreferences($this->zdb),
            new MemberPreferences($this->zdb),
            $this->history
        );
    }
}
