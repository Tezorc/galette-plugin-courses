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
use Galette\Core\Db;
use Galette\Core\Login;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\Entity\InstructorAvailability;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\InstructorPlanning;
use GaletteCourses\InstructorWithdrawal;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * An instructor declares the periods they are available or absent, from the
 * "My availability" tab of the My instructor sessions page.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class InstructorAvailabilityController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Who may declare availability: the potential instructors, i.e. group
     * managers, plus anyone already assigned to a session (staff may appoint
     * a member who manages no group), plus the group members who have a row
     * on the instructor planning.
     */
    public static function canDeclare(Db $zdb, Login $login): bool
    {
        $memberId = (int)$login->id;
        if ($memberId <= 0) {
            return false;
        }
        return $login->isGroupManager()
            || SessionInstructor::countSessionsForMember($zdb, $memberId) > 0
            || InstructorPlanning::isPlanningMember($zdb, $memberId);
    }

    public function doAdd(Request $request, Response $response): Response
    {
        $returnUrl = $this->routeparser->urlFor('coursesMyInstructorSessions') . '#tab=availability';
        if (!self::canDeclare($this->zdb, $this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have the right to declare availability.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $post = $request->getParsedBody();
        $post = is_array($post) ? $post : [];
        $dateFrom = trim((string)($post['date_from'] ?? ''));
        $dateTo = trim((string)($post['date_to'] ?? ''));
        // A single day may be entered with the start date only.
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }

        $availability = new InstructorAvailability($this->zdb);
        $availability->setMemberId((int)$this->login->id);
        $availability->setDateFrom($dateFrom);
        $availability->setDateTo($dateTo);
        $availability->setKind((string)($post['kind'] ?? ''));
        $availability->setComment((string)($post['comment'] ?? ''));

        $errors = $availability->check();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->flash->addMessage('error_detected', $error);
            }
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        if ($availability->store()) {
            $this->flash->addMessage(
                'success_detected',
                $availability->isAbsent()
                    ? _T('Your absence has been recorded.', 'courses')
                    : _T('Your availability has been recorded.', 'courses')
            );
            if ($availability->isAbsent()) {
                $this->withdrawFromCoveredSessions($availability);
            }
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        }

        return $response->withStatus(302)->withHeader('Location', $returnUrl);
    }

    /**
     * One day set from the instructor planning: available, absent, or
     * nothing declared. The day is taken out of the periods that say
     * otherwise, so the planning shows exactly what was chosen.
     *
     * An instructor sets their own days; staff may set any instructor's,
     * named by member_id.
     */
    public function doSetDay(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $post = is_array($post) ? $post : [];
        $from = trim((string)($post['from'] ?? ''));
        $returnUrl = $this->routeparser->urlFor('coursesInstructorPlanning')
            . (InstructorAvailability::isValidDate($from) ? '?from=' . $from : '');

        $memberId = (int)($post['member_id'] ?? 0);
        if ($memberId <= 0) {
            $memberId = (int)$this->login->id;
        }
        $forOther = $memberId !== (int)$this->login->id;
        if ($forOther) {
            if (!InstructorPlanningController::canFillInForOthers($this->login)) {
                $this->flash->addMessage('error_detected', _T('You do not have permission to perform this action.', 'courses'));
                return $response->withStatus(302)->withHeader('Location', $returnUrl);
            }
            if (!InstructorPlanning::isPlanningMember($this->zdb, $memberId)) {
                $this->flash->addMessage('error_detected', _T('This member is not an instructor.', 'courses'));
                return $response->withStatus(302)->withHeader('Location', $returnUrl);
            }
        } elseif (!self::canDeclare($this->zdb, $this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have the right to declare availability.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $date = trim((string)($post['date'] ?? ''));
        $kind = (string)($post['kind'] ?? '');
        if (!InstructorAvailability::isValidDate($date)) {
            $this->flash->addMessage('error_detected', _T('Please enter valid dates.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }
        if ($date < date('Y-m-d')) {
            $this->flash->addMessage('error_detected', _T('This period is already over.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        if ($kind === 'clear') {
            InstructorAvailability::clearDayOn($this->zdb, $memberId, $date);
            $this->flash->addMessage('success_detected', _T('Nothing is declared on that day any more.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        $day = new InstructorAvailability($this->zdb);
        $day->setMemberId($memberId);
        $day->setDateFrom($date);
        $day->setDateTo($date);
        $day->setKind($kind);
        $day->setComment((string)($post['comment'] ?? ''));
        $errors = $day->check();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->flash->addMessage('error_detected', $error);
            }
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        // The day leaves every period covering it and gets its own, so the
        // comment shown in the dialog is the one saved, edited or not.
        InstructorAvailability::clearDayOn($this->zdb, $memberId, $date);
        if (!$day->store()) {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        if ($forOther) {
            $message = $day->isAbsent()
                ? _T('The absence has been recorded.', 'courses')
                : _T('The availability has been recorded.', 'courses');
        } else {
            $message = $day->isAbsent()
                ? _T('Your absence has been recorded.', 'courses')
                : _T('Your availability has been recorded.', 'courses');
        }
        $this->flash->addMessage('success_detected', $message);
        if ($day->isAbsent()) {
            $this->withdrawFromCoveredSessions($day, $forOther);
        }
        return $response->withStatus(302)->withHeader('Location', $returnUrl);
    }

    /**
     * An absence period takes the instructor off the upcoming sessions they
     * lead in it, each alerting the managers when left without instructor —
     * as the "I will be absent" button does for one session.
     *
     * @param bool $forOther declared by staff in the instructor's place
     */
    private function withdrawFromCoveredSessions(InstructorAvailability $absence, bool $forOther = false): void
    {
        $memberId = $absence->getMemberId();
        $withdrawal = new InstructorWithdrawal($this->zdb, $this->preferences, $this->history);
        $withdrawn = 0;
        $alerted = 0;
        $alertFailed = 0;
        $failed = 0;
        foreach (SessionInstructor::getSessionIdsForMember($this->zdb, $memberId) as $sessionId) {
            $session = new Session($this->zdb, $sessionId);
            if (
                $session->getId() === null
                || $session->getStatus() === Session::STATUS_CANCELLED
                || $session->getSessionDate() < date('Y-m-d')
                || !$absence->covers($session->getSessionDate())
            ) {
                continue;
            }
            $entry = SessionInstructor::findEntry($this->zdb, $sessionId, $memberId);
            if ($entry === null) {
                continue;
            }
            $outcome = $withdrawal->withdraw($entry, $session, $absence->getComment());
            if ($outcome === InstructorWithdrawal::FAILED) {
                $failed++;
                continue;
            }
            $withdrawn++;
            if ($outcome === InstructorWithdrawal::MANAGERS_ALERTED) {
                $alerted++;
            } elseif ($outcome === InstructorWithdrawal::ALERT_FAILED) {
                $alertFailed++;
            }
        }

        if ($withdrawn > 0 && $forOther) {
            $this->flash->addMessage(
                'success_detected',
                $alerted > 0
                    ? sprintf(_T('The instructor has been withdrawn from %1$d session(s) during this period; %2$d of them have no instructor any more: the group managers have been informed.', 'courses'), $withdrawn, $alerted)
                    : sprintf(_T('The instructor has been withdrawn from %d session(s) during this period.', 'courses'), $withdrawn)
            );
        } elseif ($withdrawn > 0) {
            $this->flash->addMessage(
                'success_detected',
                $alerted > 0
                    ? sprintf(_T('You have been withdrawn from %1$d session(s) during this period; %2$d of them have no instructor any more: the group managers have been informed.', 'courses'), $withdrawn, $alerted)
                    : sprintf(_T('You have been withdrawn from %d session(s) during this period.', 'courses'), $withdrawn)
            );
        }
        if ($alertFailed > 0) {
            $this->flash->addMessage(
                'warning_detected',
                sprintf(_T('%d session(s) of this period have no instructor any more, but the group managers could not be informed: please warn them yourself.', 'courses'), $alertFailed)
            );
        }
        if ($failed > 0) {
            $this->flash->addMessage(
                'error_detected',
                $forOther
                    ? _T('Some sessions of this period could not be updated: remove the instructor from each of them on the session page.', 'courses')
                    : _T('Some sessions of this period could not be updated: use "I will be absent" on each of them.', 'courses')
            );
        }
    }

    public function doRemove(Request $request, Response $response, int $id): Response
    {
        $returnUrl = $this->routeparser->urlFor('coursesMyInstructorSessions') . '#tab=availability';

        $availability = new InstructorAvailability($this->zdb, $id);
        // Only the owner removes a period; a foreign or unknown id reads the same.
        if ($availability->getId() === null || $availability->getMemberId() !== (int)$this->login->id) {
            $this->flash->addMessage('error_detected', _T('Period not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $returnUrl);
        }

        if ($availability->remove()) {
            $this->flash->addMessage('success_detected', _T('The period has been removed.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        }

        return $response->withStatus(302)->withHeader('Location', $returnUrl);
    }
}
