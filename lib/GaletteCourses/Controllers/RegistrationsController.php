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
use GaletteCourses\Entity\EventType;
use GaletteCourses\Entity\Registration;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\Entity\Waitlist;
use GaletteCourses\Filters\RegistrationsList;
use GaletteCourses\Filters\SessionsList;
use GaletteCourses\MemberPreferences;
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\PluginPreferences;
use GaletteCourses\Repository\Registrations;
use GaletteCourses\Repository\Sessions;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;
use Analog\Analog;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class RegistrationsController extends AbstractController
{
    use PluginControllerTrait;
    use CoursesAclGuard;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Resolve the post-action redirect URL.
     *
     * Register / unregister / waitlist actions triggered from the
     * "My registrations" page carry redirect_to=my_registrations so the page
     * reloads with fresh data (both the "Find a session" and "My registrations"
     * tabs reflect the change). Everything else falls back to the session
     * detail page, as before.
     */
    private function resolveReturnUrl(Request $request, int $sessionId): string
    {
        $post = $request->getParsedBody();
        if (is_array($post) && ($post['redirect_to'] ?? '') === 'my_registrations') {
            return $this->routeparser->urlFor('coursesMyRegistrations');
        }
        return $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$sessionId]);
    }

    public function doRegister(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $member_id = (int)$this->login->id;
        if ($member_id <= 0) {
            return $response->withStatus(302)->withHeader("Location", $this->routeparser->urlFor("coursesSessions"));
        }

        $returnUrl = $this->resolveReturnUrl($request, $id);

        // Phase 47.2: enforce active + status + cotisation in one call.
        $err = $this->getMemberEligibilityError($member_id);
        if ($err !== null) {
            $this->flash->addMessage('error_detected', $err);
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check session is open
        if (!$session->isOpen()) {
            $this->flash->addMessage('error_detected', _T('This session is not open for registration.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Block registration when no instructor is assigned, unless the event explicitly allows it.
        if (
            !$session->getEvent()->isRegistrationAllowedWithoutInstructor()
            && !SessionInstructor::hasInstructor($this->zdb, $id)
        ) {
            $this->flash->addMessage('error_detected', _T('No instructor assigned to this session. Registration is not yet possible.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check group access for self-registration (own groups only, not family).
        // canRegisterSelf() uses group entries presence — no need for an isRestricted() guard.
        $event = $session->getEvent();
        if (!$event->canRegisterSelf($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have access to this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check not already registered
        if (Registration::isRegistered($this->zdb, $id, $member_id)) {
            $this->flash->addMessage('warning_detected', _T('You are already registered for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Warn if another session overlaps on the same day
        if (Registration::hasOverlappingSession($this->zdb, $member_id, $session->getSessionDate(), $session->getStartTime(), $session->getEndTime(), $id)) {
            $this->flash->addMessage('warning_detected', _T('Warning: you are already registered for another session at the same time on this day.', 'courses'));
        }

        // Check capacity - redirect to waitlist if full
        if ($session->isFull()) {
            $this->flash->addMessage('warning_detected', _T('This session is full. You can join the waitlist.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Register
        $registration = new Registration($this->zdb);
        $registration->setSessionId($id);
        $registration->setMemberId($member_id);

        if ($registration->store($session)) {
            $this->history->add(
                _T('[Courses] Member registered to session', 'courses'),
                sprintf('session #%d — member #%d', $id, $member_id)
            );
            $this->flash->addMessage('success_detected', _T('You have been registered successfully.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred during registration.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $returnUrl);
    }

    public function doParentUnregister(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $returnUrl = $this->resolveReturnUrl($request, $id);

        $post = $request->getParsedBody();
        $child_id = (int)($post['member_id'] ?? 0);
        $parent_id = (int)$this->login->id;
        if ($parent_id <= 0) {
            $this->flash->addMessage('error_detected', _T('Invalid request.', 'courses'));
            return $response->withStatus(302)->withHeader("Location", $returnUrl);
        }

        if ($child_id <= 0) {
            $this->flash->addMessage('error_detected', _T('Invalid request.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Verify parent-child relationship
        try {
            if (!$this->isChildOf($parent_id, $child_id)) {
                $this->flash->addMessage('error_detected', _T('You can only unregister your own linked members.', 'courses'));
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $returnUrl);
            }
        } catch (\Throwable $e) {
            $this->flash->addMessage('error_detected', _T('An error occurred during unregistration.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $registration = Registration::findRegistration($this->zdb, $id, $child_id);
        if ($registration === null) {
            $this->flash->addMessage('error_detected', _T('Registration not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $event = $session->getEvent();

        $result = $registration->cancel($session);
        if ($result !== false) {
            $this->history->add(
                _T('[Courses] Linked member unregistered from session', 'courses'),
                sprintf('session #%d — member #%d (by parent #%d)', $id, $child_id, $parent_id)
            );
            $this->flash->addMessage('success_detected', _T('The linked member has been unregistered successfully.', 'courses'));
            if (is_int($result)) {
                $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
                $notification->notifyWaitlistPromotion($session, $event, $result);
            }
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred during unregistration.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $returnUrl);
    }

    public function doUnregister(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $returnUrl = $this->resolveReturnUrl($request, $id);

        $member_id = (int)$this->login->id;
        $registration = Registration::findRegistration($this->zdb, $id, $member_id);

        if ($registration === null) {
            $this->flash->addMessage('error_detected', _T('Registration not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $event = $session->getEvent();

        $result = $registration->cancel($session);
        if ($result !== false) {
            $this->history->add(
                _T('[Courses] Member unregistered from session', 'courses'),
                sprintf('session #%d — member #%d', $id, $member_id)
            );
            $this->flash->addMessage('success_detected', _T('You have been unregistered successfully.', 'courses'));

            // If someone was promoted from waitlist, notify them
            if (is_int($result)) {
                $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
                $notification->notifyWaitlistPromotion($session, $event, $result);
            }
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred during unregistration.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $returnUrl);
    }

    public function doWaitlist(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $member_id = (int)$this->login->id;
        if ($member_id <= 0) {
            return $response->withStatus(302)->withHeader("Location", $this->routeparser->urlFor("coursesSessions"));
        }

        $returnUrl = $this->resolveReturnUrl($request, $id);

        // Phase 47.2: enforce active + status + cotisation in one call.
        $err = $this->getMemberEligibilityError($member_id);
        if ($err !== null) {
            $this->flash->addMessage('error_detected', $err);
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check session is open
        if (!$session->isOpen()) {
            $this->flash->addMessage('error_detected', _T('This session is not open for registration.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Block registration when no instructor is assigned, unless the event explicitly allows it.
        if (
            !$session->getEvent()->isRegistrationAllowedWithoutInstructor()
            && !SessionInstructor::hasInstructor($this->zdb, $id)
        ) {
            $this->flash->addMessage('error_detected', _T('No instructor assigned to this session. Registration is not yet possible.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check group access first (blocking) before non-blocking warnings
        $event = $session->getEvent();
        if (!$event->canRegisterSelf($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have access to this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check not already registered
        if (Registration::isRegistered($this->zdb, $id, $member_id)) {
            $this->flash->addMessage('warning_detected', _T('You are already registered for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check not already on waitlist
        if (Waitlist::isOnWaitlist($this->zdb, $id, $member_id)) {
            $this->flash->addMessage('warning_detected', _T('You are already on the waitlist for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Non-blocking overlap warning
        if (Registration::hasOverlappingSession($this->zdb, $member_id, $session->getSessionDate(), $session->getStartTime(), $session->getEndTime(), $id)) {
            $this->flash->addMessage('warning_detected', _T('Warning: you are already registered for another session at the same time on this day.', 'courses'));
        }

        $waitlist = new Waitlist($this->zdb);
        $waitlist->setSessionId($id);
        $waitlist->setMemberId($member_id);

        if ($waitlist->store()) {
            $this->history->add(
                _T('[Courses] Member joined waitlist', 'courses'),
                sprintf('session #%d — member #%d — position %d', $id, $member_id, $waitlist->getPosition())
            );
            $this->flash->addMessage(
                'success_detected',
                sprintf(_T('You have been added to the waitlist (position %d).', 'courses'), $waitlist->getPosition())
            );
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred joining the waitlist.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $returnUrl);
    }

    public function doLeaveWaitlist(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $member_id = (int)$this->login->id;
        $entry = Waitlist::findEntry($this->zdb, $id, $member_id);

        if ($entry === null) {
            $this->flash->addMessage('error_detected', _T('You are not on the waitlist for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        if ($entry->remove()) {
            $this->history->add(
                _T('[Courses] Member left waitlist', 'courses'),
                sprintf('session #%d — member #%d', $id, $member_id)
            );
            $this->flash->addMessage('success_detected', _T('You have been removed from the waitlist.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred leaving the waitlist.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function myRegistrations(Request $request, Response $response): Response
    {
        $member_id = (int)$this->login->id;

        // Phase 47.1: super admin / non-member accounts have no Adherent record
        // (login.id === 0). The page is meaningless for them and exposes false
        // signals (e.g. children/cotisation banners on a non-existent member).
        if ($this->login->isSuperAdmin() || $member_id <= 0) {
            $this->flash->addMessage(
                'warning_detected',
                _T('This page is reserved for member accounts.', 'courses')
            );
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        // Collect member IDs to load: parent + children
        $member_ids  = [$member_id];
        $children_ids = []; // IDs of linked members (children) only
        $reg_members = []; // memberId => ['name' => ..., 'nickname' => ...]
        // Phase 47.2: ineligible members (active=0 / non-member status / cotisation
        // not up to date). Stored as a flat list of pre-formatted reasons:
        //  ["Membership not up to date. (Marie)", "Account not active. (Paul)"]
        $ineligible_members = [];

        try {
            $currentAdherent = new Adherent($this->zdb, $member_id, ['children' => true]);
            $reg_members[$member_id] = [
                'name'     => $currentAdherent->sname ?? '',
                'nickname' => !empty($currentAdherent->nickname) ? (string)$currentAdherent->nickname : '',
            ];
            $parentDisplay = $this->formatMemberDisplayName(
                $reg_members[$member_id]['name'],
                $reg_members[$member_id]['nickname']
            );
            $err = $this->getMemberEligibilityError($member_id, $parentDisplay, true);
            if ($err !== null) {
                $ineligible_members[] = $err;
            }
            foreach ($currentAdherent->children as $child) {
                $childId = is_object($child) ? (int)$child->id : (int)$child;
                if ($childId <= 0) {
                    continue;
                }
                $member_ids[]   = $childId;
                $children_ids[] = $childId;
                $childAdherent = new Adherent($this->zdb, $childId);
                $reg_members[$childId] = [
                    'name'     => $childAdherent->sname ?? '',
                    'nickname' => !empty($childAdherent->nickname) ? (string)$childAdherent->nickname : '',
                ];
                $childDisplay = $this->formatMemberDisplayName(
                    $reg_members[$childId]['name'],
                    $reg_members[$childId]['nickname']
                );
                $err = $this->getMemberEligibilityError($childId, $childDisplay, true);
                if ($err !== null) {
                    $ineligible_members[] = $err;
                }
            }
        } catch (\Throwable $e) {
            Analog::log('Error loading children for my_registrations: ' . $e->getMessage(), Analog::ERROR);
            $reg_members[$member_id] = ['name' => '', 'nickname' => ''];
        }

        $regs_repo = new Registrations($this->zdb);
        $registrations = $regs_repo->getForMembers($member_ids);

        // Load session and event info for each registration
        $sessions = [];
        $events = [];
        foreach ($registrations as $reg) {
            if (!isset($sessions[$reg->getSessionId()])) {
                $session = new Session($this->zdb, $reg->getSessionId());
                $sessions[$reg->getSessionId()] = $session;
                if (!isset($events[$session->getEventId()])) {
                    $events[$session->getEventId()] = new Event($this->zdb, $session->getEventId());
                }
            }
        }

        // Batch-load instructor names for registered sessions
        $mine_instructor_names = SessionInstructor::getInstructorNamesForSessions($this->zdb, array_keys($sessions));

        // Detect out-of-group registrations: upcoming non-cancelled sessions on
        // events restricted to groups the member no longer belongs to. Flags
        // are computed per registration id and consumed by the template to show
        // an "out of group" warning + emphasised unregister action.
        $out_of_group_regs = []; // [registration_id => true]
        $today = date('Y-m-d');
        $event_groups_map = []; // [event_id => [group_id, ...]]
        foreach ($events as $eid => $ev) {
            $ev->loadGroups();
            $g = $ev->getGroups();
            if (!empty($g)) {
                $event_groups_map[$eid] = $g;
            }
        }
        if (!empty($event_groups_map)) {
            $allRequiredGroups = [];
            foreach ($event_groups_map as $g) {
                foreach ($g as $gid) {
                    $allRequiredGroups[$gid] = true;
                }
            }
            $member_groups = []; // [member_id => [group_id => true]]
            try {
                $sel = $this->zdb->select('groups_members');
                $sel->columns(['id_adh', 'id_group']);
                $sel->where->in('id_adh', $member_ids);
                $sel->where->in('id_group', array_keys($allRequiredGroups));
                foreach ($this->zdb->execute($sel) as $r) {
                    $member_groups[(int)$r->id_adh][(int)$r->id_group] = true;
                }
            } catch (\Throwable $e) {
                Analog::log(
                    'Error checking member groups for out-of-group flag: ' . $e->getMessage(),
                    Analog::ERROR
                );
            }
            foreach ($registrations as $reg) {
                $sid = $reg->getSessionId();
                $session = $sessions[$sid] ?? null;
                if ($session === null) {
                    continue;
                }
                if ($session->getSessionDate() < $today) {
                    continue; // past
                }
                if ($session->getStatus() === Session::STATUS_CANCELLED) {
                    continue; // already cancelled, no actionable signal
                }
                $eid = $session->getEventId();
                if (!isset($event_groups_map[$eid])) {
                    continue; // event has no group restriction
                }
                $mid = $reg->getMemberId();
                $inAnyGroup = false;
                foreach ($event_groups_map[$eid] as $gid) {
                    if (isset($member_groups[$mid][$gid])) {
                        $inAnyGroup = true;
                        break;
                    }
                }
                if (!$inAnyGroup && $reg->getId() !== null) {
                    $out_of_group_regs[$reg->getId()] = true;
                }
            }
        }

        // Build registered/waitlisted session ID sets (parent only, for self-registration status)
        $registered_session_ids = [];
        foreach ($registrations as $reg) {
            if ($reg->getMemberId() === $member_id) {
                $registered_session_ids[] = $reg->getSessionId();
            }
        }

        // Load upcoming open sessions for the "Browse" tab
        // Always filtered by the member's own groups and children, regardless of role (staff/monitor/admin)
        $browse_filters = new SessionsList();
        $browse_filters->date_from = date('Y-m-d');
        $browse_filters->status_filter = 'open';
        $sessions_repo = new Sessions($this->zdb, $this->login, $browse_filters);
        $sessions_repo->setPersonalMemberId($member_id);
        $available_sessions = $sessions_repo->getList();
        $browse_available_names = $sessions_repo->getAvailableNames();

        $browse_events          = [];
        $browse_has_instructor  = [];
        $browse_instructor_names = [];
        $browse_on_waitlist     = [];

        // Collect all session IDs for batch queries
        $browse_session_ids = [];
        foreach ($available_sessions as $s) {
            $browse_session_ids[] = $s->getId();
            $eid = $s->getEventId();
            if (!isset($browse_events[$eid])) {
                $browse_events[$eid] = new Event($this->zdb, $eid);
            }
        }

        // Batch-load instructor names and waitlist status for all browse sessions
        $batch_instructor_names = SessionInstructor::getInstructorNamesForSessions($this->zdb, $browse_session_ids);
        foreach ($available_sessions as $s) {
            $sid = $s->getId();
            $browse_instructor_names[$sid] = $batch_instructor_names[$sid] ?? '';
            $browse_has_instructor[$sid]   = isset($batch_instructor_names[$sid]);
            $browse_on_waitlist[$sid]      = Waitlist::isOnWaitlist($this->zdb, $sid, $member_id);
        }

        // For each browse session: can the member self-register? which children are eligible?
        // canRegisterSelf() is the single source of truth — same check as doRegister()/doWaitlist().
        // It loads event groups internally; getGroups() is valid immediately after.
        $browse_can_self_register = []; // [sid => bool]
        $browse_eligible_children = []; // [sid => [child_id => child_info]]

        // Phase 47.2 follow-up: pre-compute eligibility (active + status + cotisation)
        // for parent + all children in ONE SQL query, then use it to filter the
        // self-register button and the children dropdown. Members who fail any
        // condition are not offered as a choice (the handler would block them).
        $eligibility_set = self::batchEligibleMemberIds(
            $this->zdb,
            array_merge([$member_id], $children_ids)
        );

        foreach ($available_sessions as $s) {
            $sid = $s->getId();
            $ev  = $browse_events[$s->getEventId()];

            $browse_can_self_register[$sid] = $ev->canRegisterSelf($this->login)
                && isset($eligibility_set[$member_id]);
            $eventGroups = $ev->getGroups(); // already loaded by canRegisterSelf()

            // Which children are eligible (in the required group, not already registered,
            // and passing the 3 eligibility conditions)?
            // One batch query per session instead of one per child.
            $eligible = [];
            if (!empty($children_ids)) {
                $childrenInGroup = [];
                if (!empty($eventGroups)) {
                    try {
                        $chkSelect = $this->zdb->select('groups_members');
                        $chkSelect->columns(['id_adh']);
                        $chkSelect->where->in('id_adh', $children_ids);
                        $chkSelect->where->in('id_group', $eventGroups);
                        $chkSelect->quantifier('DISTINCT');
                        foreach ($this->zdb->execute($chkSelect) as $r) {
                            $childrenInGroup[(int)$r->id_adh] = true;
                        }
                    } catch (\Throwable $e) {
                        Analog::log('Error checking children groups for session #' . $sid . ': ' . $e->getMessage(), Analog::ERROR);
                    }
                }
                foreach ($children_ids as $childId) {
                    if (Registration::isRegistered($this->zdb, $sid, $childId)) {
                        continue;
                    }
                    if (!empty($eventGroups) && !isset($childrenInGroup[$childId])) {
                        continue;
                    }
                    if (!isset($eligibility_set[$childId])) {
                        continue;
                    }
                    $eligible[$childId] = $reg_members[$childId] ?? ['name' => '', 'nickname' => ''];
                }
            }
            $browse_eligible_children[$sid] = $eligible;
        }

        // Phase 52: surface cancelled upcoming sessions in the "Browse" tab so
        // members are informed when a session they might look for was cancelled.
        // Same group scoping as the open list (setPersonalMemberId). Sessions the
        // member (or a child) is registered to are skipped — those already appear
        // in the "My registrations" cancelled section.
        $browse_cancelled_filters = new SessionsList();
        $browse_cancelled_filters->date_from = date('Y-m-d');
        $browse_cancelled_filters->status_filter = Session::STATUS_CANCELLED;
        $cancelled_repo = new Sessions($this->zdb, $this->login, $browse_cancelled_filters);
        $cancelled_repo->setPersonalMemberId($member_id);
        $browse_cancelled_sessions = [];
        foreach ($cancelled_repo->getList() as $cs) {
            if (isset($sessions[$cs->getId()])) {
                continue; // already shown in the "My registrations" tab
            }
            $browse_cancelled_sessions[] = $cs;
            $eid = $cs->getEventId();
            if (!isset($browse_events[$eid])) {
                $browse_events[$eid] = new Event($this->zdb, $eid);
            }
        }

        $this->view->render(
            $response,
            $this->getTemplate('pages/my_registrations'),
            [
                'page_title'              => _T('My sessions', 'courses'),
                'registrations'           => $registrations,
                'sessions'                => $sessions,
                'events'                  => $events,
                'mine_instructor_names'   => $mine_instructor_names,
                'reg_members'             => $reg_members,
                'current_member_id'       => $member_id,
                'registered_session_ids'  => $registered_session_ids,
                'available_sessions'      => $available_sessions,
                'browse_events'           => $browse_events,
                'browse_has_instructor'        => $browse_has_instructor,
                'browse_instructor_names'     => $browse_instructor_names,
                'browse_on_waitlist'          => $browse_on_waitlist,
                'browse_can_self_register'    => $browse_can_self_register,
                'browse_eligible_children'    => $browse_eligible_children,
                'browse_event_types'          => EventType::getList($this->zdb),
                'browse_available_names'      => $browse_available_names,
                'browse_cancelled_sessions'   => $browse_cancelled_sessions,
                'member_is_up2date'       => $this->login->isUp2Date()
                                             || $this->login->isAdmin()
                                             || $this->login->isStaff()
                                             || $this->login->isGroupManager(),
                'ineligible_members'      => $ineligible_members,
                'out_of_group_regs'       => $out_of_group_regs,
            ]
        );
        return $response;
    }

    public function list(Request $request, Response $response, ?string $option = null, int|string|null $value = null): Response
    {
        $filter_name = $this->getFilterName('registrations');
        if (isset($this->session->$filter_name)) {
            $filters = $this->session->$filter_name;
        } else {
            $filters = new RegistrationsList();
        }

        if ($option !== null) {
            switch ($option) {
                case 'page':
                    $filters->current_page = (int)$value;
                    break;
            }
        }

        $regs_repo = new Registrations($this->zdb, $filters);
        $registrations = $regs_repo->getList();
        $available_names = $regs_repo->getAvailableNames();

        // Load session and event info
        $sessions = [];
        $events = [];
        $members = [];
        $nicknames = [];
        foreach ($registrations as $reg) {
            if (!isset($sessions[$reg->getSessionId()])) {
                $session = new Session($this->zdb, $reg->getSessionId());
                $sessions[$reg->getSessionId()] = $session;
                if (!isset($events[$session->getEventId()])) {
                    $events[$session->getEventId()] = new Event($this->zdb, $session->getEventId());
                }
            }
            if (!isset($members[$reg->getMemberId()])) {
                try {
                    $adherent = new Adherent($this->zdb, $reg->getMemberId());
                    $members[$reg->getMemberId()] = $adherent->sname;
                    if (!empty($adherent->nickname)) {
                        $nicknames[$reg->getMemberId()] = $adherent->nickname;
                    }
                } catch (\Throwable $e) {
                    $members[$reg->getMemberId()] = _T('Unknown member', 'courses');
                }
            }
        }

        $this->session->$filter_name = $filters;

        $this->view->render(
            $response,
            $this->getTemplate('pages/registrations_list'),
            [
                'page_title' => _T('Registrations', 'courses'),
                'registrations' => $registrations,
                'sessions' => $sessions,
                'events' => $events,
                'members' => $members,
                'nicknames' => $nicknames,
                'event_types' => EventType::getList($this->zdb),
                'available_names' => $available_names,
                'nb' => $regs_repo->getCount(),
                'filters' => $filters,
            ]
        );
        return $response;
    }

    public function filter(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $filter_name = $this->getFilterName('registrations');

        if (isset($post['clear_filter'])) {
            $filters = new RegistrationsList();
        } else {
            if (isset($this->session->$filter_name)) {
                $filters = $this->session->$filter_name;
            } else {
                $filters = new RegistrationsList();
            }

            if (isset($post['session_filter'])) {
                $filters->session_filter = $post['session_filter'] !== '' ? (int)$post['session_filter'] : null;
            }
            if (isset($post['status_filter'])) {
                $filters->status_filter = $post['status_filter'];
            }
            if (isset($post['event_type_filter'])) {
                $filters->event_type_filter = $post['event_type_filter'] !== '' ? (int)$post['event_type_filter'] : null;
            }
            if (isset($post['name_filter'])) {
                $filters->name_filter = $post['name_filter'];
            }
            if (isset($post['date_from'])) {
                $filters->date_from = $post['date_from'];
            }
            if (isset($post['date_to'])) {
                $filters->date_to = $post['date_to'];
            }
            if (isset($post['nbshow']) && is_numeric($post['nbshow'])) {
                $filters->show = (int)$post['nbshow'];
            }
        }

        $this->session->$filter_name = $filters;

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesRegistrations'));
    }

    public function proxyRegisterForm(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessCanProxyRegister(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]),
            _T('You do not have permission to register members on behalf of others.', 'courses')
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $event = $session->getEvent();

        // Load eligible members from the event's groups
        $event->loadGroups();
        $eventGroups = $event->getGroups();
        $eligible_members = [];

        try {
            $select = $this->zdb->select(\Galette\Entity\Adherent::TABLE, 'a');
            $select->columns(['id_adh', 'nom_adh', 'prenom_adh', 'pseudo_adh']);
            // Phase 47.2: filter out members with the "Non member" status
            // (statuts.priorite_statut >= 99) — they are not eligible.
            $select->join(
                ['s' => PREFIX_DB . 'statuts'],
                'a.id_statut = s.id_statut',
                []
            );

            if (!empty($eventGroups)) {
                $select->join(
                    ['gm' => PREFIX_DB . 'groups_members'],
                    'a.id_adh = gm.id_adh',
                    []
                );
                $select->where->in('gm.id_group', $eventGroups);
                $select->quantifier('DISTINCT');
            }

            $select->where->equalTo('a.activite_adh', true);
            $select->where->lessThan('s.priorite_statut', 99);
            // Cotisation a jour : exempte OU date_echeance dans le futur
            $today = date('Y-m-d');
            $select->where->expression(
                '(a.bool_exempt_adh = 1 OR a.date_echeance >= ?)',
                [$today]
            );
            $select->order(['a.nom_adh ASC', 'a.prenom_adh ASC']);
            $results = $this->zdb->execute($select);

            // Get already registered members
            $regs_repo = new Registrations($this->zdb);
            $registrations = $regs_repo->getForSession($id);
            $registered_ids = [];
            foreach ($registrations as $reg) {
                $registered_ids[] = $reg->getMemberId();
            }

            foreach ($results as $r) {
                $mid = (int)$r->id_adh;
                if (in_array($mid, $registered_ids)) {
                    continue;
                }
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                $nickname = !empty($r->pseudo_adh) ? (string)$r->pseudo_adh : '';
                $eligible_members[$mid] = [
                    'name' => $name,
                    'nickname' => $nickname,
                ];
            }
        } catch (\Throwable $e) {
            Analog::log('Error loading eligible members: ' . $e->getMessage(), Analog::ERROR);
        }

        $this->view->render(
            $response,
            $this->getTemplate('pages/proxy_register'),
            [
                'page_title' => _T('Register a member', 'courses'),
                'session' => $session,
                'event' => $event,
                'eligible_members' => $eligible_members,
            ]
        );
        return $response;
    }

    public function doParentRegister(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $returnUrl = $this->resolveReturnUrl($request, $id);

        if ($this->login->isSuperAdmin() || !$this->login->isLogged()) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $parent_id = (int)$this->login->id;

        // Phase 47.2: parent must be active + non-"non-member" status + a jour
        $err = $this->getMemberEligibilityError($parent_id);
        if ($err !== null) {
            $this->flash->addMessage('error_detected', $err);
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        if (!$session->isOpen()) {
            $this->flash->addMessage('error_detected', _T('This session is not open for registration.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        if (
            !$session->getEvent()->isRegistrationAllowedWithoutInstructor()
            && !SessionInstructor::hasInstructor($this->zdb, $id)
        ) {
            $this->flash->addMessage('error_detected', _T('No instructor assigned to this session. Registration is not yet possible.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $post = $request->getParsedBody();
        $child_id = (int)($post['member_id'] ?? 0);
        if ($child_id <= 0) {
            $this->flash->addMessage('error_detected', _T('Select a linked member to register.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Verify parent-child relationship
        try {
            if (!$this->isChildOf($parent_id, $child_id)) {
                $this->flash->addMessage('error_detected', _T('You can only register your own linked members.', 'courses'));
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $returnUrl);
            }
        } catch (\Throwable $e) {
            $this->flash->addMessage('error_detected', _T('An error occurred during registration.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Phase 47.2: child must also be active + non-"non-member" + a jour
        $childDisplay = '';
        try {
            $childAdherent = new Adherent($this->zdb, $child_id);
            $childDisplay = $this->formatMemberDisplayName(
                (string)($childAdherent->sname ?? ''),
                !empty($childAdherent->nickname) ? (string)$childAdherent->nickname : ''
            );
        } catch (\Throwable) {
            // fall back to empty display name
        }
        $err = $this->getMemberEligibilityError($child_id, $childDisplay);
        if ($err !== null) {
            $this->flash->addMessage('error_detected', $err);
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Check child belongs to required event group
        $event = $session->getEvent();
        $event->loadGroups();
        $eventGroups = $event->getGroups();
        if (!empty($eventGroups)) {
            try {
                $checkSelect = $this->zdb->select('groups_members');
                $checkSelect->where(['id_adh' => $child_id]);
                $checkSelect->where->in('id_group', $eventGroups);
                $checkResults = $this->zdb->execute($checkSelect);
                if ($checkResults->count() === 0) {
                    $this->flash->addMessage('error_detected', _T('This linked member does not belong to a required group for this event.', 'courses'));
                    return $response
                        ->withStatus(302)
                        ->withHeader('Location', $returnUrl);
                }
            } catch (\Throwable $e) {
                Analog::log('Error checking group for child #' . $child_id . ': ' . $e->getMessage(), Analog::ERROR);
            }
        }

        if (Registration::isRegistered($this->zdb, $id, $child_id)) {
            $this->flash->addMessage('warning_detected', _T('This linked member is already registered for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Warn if another session overlaps on the same day for the linked member
        if (Registration::hasOverlappingSession($this->zdb, $child_id, $session->getSessionDate(), $session->getStartTime(), $session->getEndTime(), $id)) {
            $this->flash->addMessage('warning_detected', _T('Warning: this linked member is already registered for another session at the same time on this day.', 'courses'));
        }

        if ($session->isFull()) {
            $this->flash->addMessage('warning_detected', _T('This session is full.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $registration = new Registration($this->zdb);
        $registration->setSessionId($id);
        $registration->setMemberId($child_id);
        $registration->setRegisteredBy($parent_id);

        if ($registration->store($session)) {
            $this->history->add(
                _T('[Courses] Linked member registered to session', 'courses'),
                sprintf('session #%d — member #%d (by parent #%d)', $id, $child_id, $parent_id)
            );
            $this->flash->addMessage('success_detected', _T('The linked member has been registered successfully.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred during registration.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $returnUrl);
    }

    public function doProxyRegister(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessCanProxyRegister(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]),
            _T('You do not have permission to register members on behalf of others.', 'courses')
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        if (!$session->isOpen()) {
            $this->flash->addMessage('error_detected', _T('This session is not open for registration.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        if (
            !$session->getEvent()->isRegistrationAllowedWithoutInstructor()
            && !SessionInstructor::hasInstructor($this->zdb, $id)
        ) {
            $this->flash->addMessage('error_detected', _T('No instructor assigned to this session. Registration is not yet possible.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $post = $request->getParsedBody();
        $member_id = (int)($post['member_id'] ?? 0);
        if ($member_id <= 0) {
            $this->flash->addMessage('error_detected', _T('Select a member to register', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesProxyRegisterForm', ['id' => (string)$id]));
        }

        // Phase 47.2: enforce member eligibility (active + status + cotisation).
        $targetDisplay = '';
        try {
            $targetAdherent = new Adherent($this->zdb, $member_id);
            $targetDisplay = $this->formatMemberDisplayName(
                (string)($targetAdherent->sname ?? ''),
                !empty($targetAdherent->nickname) ? (string)$targetAdherent->nickname : ''
            );
        } catch (\Throwable) {
            // fall back to empty display name
        }
        $err = $this->getMemberEligibilityError($member_id, $targetDisplay);
        if ($err !== null) {
            $this->flash->addMessage('error_detected', $err);
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesProxyRegisterForm', ['id' => (string)$id]));
        }

        if (Registration::isRegistered($this->zdb, $id, $member_id)) {
            $this->flash->addMessage('warning_detected', _T('This member is already registered for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        // Full session -> fall back to waitlist instead of erroring out, so a
        // staff/instructor proxy registration always lands somewhere actionable.
        if ($session->isFull()) {
            if (Waitlist::isOnWaitlist($this->zdb, $id, $member_id)) {
                $this->flash->addMessage(
                    'warning_detected',
                    _T('This session is full and the member is already on the waitlist.', 'courses')
                );
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
            }
            $waitlist = new Waitlist($this->zdb);
            $waitlist->setSessionId($id);
            $waitlist->setMemberId($member_id);
            if ($waitlist->store()) {
                $this->history->add(
                    _T('[Courses] Member added to waitlist by staff', 'courses'),
                    sprintf('session #%d — member #%d — position %d', $id, $member_id, $waitlist->getPosition())
                );
                $this->flash->addMessage(
                    'success_detected',
                    sprintf(
                        _T('Session is full. Member added to the waitlist (position %d).', 'courses'),
                        $waitlist->getPosition()
                    )
                );
            } else {
                $this->flash->addMessage('error_detected', _T('An error occurred adding the member to the waitlist.', 'courses'));
            }
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $registration = new Registration($this->zdb);
        $registration->setSessionId($id);
        $registration->setMemberId($member_id);
        $registration->setRegisteredBy($this->login->isSuperAdmin() ? null : (int)$this->login->id);

        try {
            if ($registration->store($session)) {
                $this->history->add(
                    _T('[Courses] Member registered by staff', 'courses'),
                    sprintf('session #%d — member #%d', $id, $member_id)
                );
                $this->flash->addMessage('success_detected', _T('Member has been registered successfully.', 'courses'));
            } else {
                $this->flash->addMessage('error_detected', _T('An error occurred during registration.', 'courses'));
            }
        } catch (\Throwable $e) {
            $this->flash->addMessage('error_detected', _T('An error occurred during registration.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doProxyUnregister(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $actor_id = (int)$this->login->id;
        $is_session_instructor = $actor_id > 0
            && SessionInstructor::isInstructor($this->zdb, $id, $actor_id);

        if (!$this->login->isAdmin() && !$this->login->isStaff() && !$is_session_instructor) {
            $this->flash->addMessage(
                'error_detected',
                _T('You do not have permission to cancel this registration.', 'courses')
            );
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $post = $request->getParsedBody();
        $member_id = (int)($post['member_id'] ?? 0);
        if ($member_id <= 0) {
            $this->flash->addMessage('error_detected', _T('Invalid request.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $registration = Registration::findRegistration($this->zdb, $id, $member_id);
        if ($registration === null) {
            $this->flash->addMessage('error_detected', _T('Registration not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $event = $session->getEvent();
        $result = $registration->cancel($session);
        if ($result !== false) {
            $this->history->add(
                _T('[Courses] Registration cancelled by staff/instructor', 'courses'),
                sprintf('session #%d — member #%d (by #%d)', $id, $member_id, $actor_id)
            );
            $this->flash->addMessage('success_detected', _T('Registration cancelled successfully.', 'courses'));
            if (is_int($result)) {
                $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
                $notification->notifyWaitlistPromotion($session, $event, $result);
            }
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred during cancellation.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doMarkAttendance(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $post = $request->getParsedBody();
        $attendance = $post['attendance'] ?? [];

        $validStatuses = [
            Registration::STATUS_REGISTERED,
            Registration::STATUS_ATTENDED,
            Registration::STATUS_ABSENT,
            Registration::STATUS_ABSENT_EXCUSED,
            Registration::STATUS_PRESENT_UNREGISTERED,
        ];

        $updated = 0;
        foreach ($attendance as $regId => $status) {
            if (!in_array($status, $validStatuses)) {
                continue;
            }
            $reg = new Registration($this->zdb, (int)$regId);
            if ($reg->getId() !== null && $reg->getSessionId() === $id) {
                if ($reg->updateStatus($status)) {
                    $updated++;
                }
            }
        }

        if ($updated > 0) {
            $this->history->add(
                _T('[Courses] Attendance recorded', 'courses'),
                sprintf('session #%d — %d update(s)', $id, $updated)
            );
        }
        $this->flash->addMessage('success_detected', sprintf(_T('%d attendance(s) recorded.', 'courses'), $updated));

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doWalkIn(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $post = $request->getParsedBody();
        $member_id = (int)($post['member_id'] ?? 0);
        if ($member_id <= 0) {
            $this->flash->addMessage('error_detected', _T('Please select a member.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $registeredBy = $this->login->isSuperAdmin() ? null : (int)$this->login->id;

        if (Registration::createWalkIn($this->zdb, $id, $member_id, $registeredBy)) {
            $session->incrementRegistrations();
            $this->history->add(
                _T('[Courses] Walk-in attendance recorded', 'courses'),
                sprintf('session #%d — member #%d', $id, $member_id)
            );
            $this->flash->addMessage('success_detected', _T('Walk-in attendance recorded.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    /**
     * Return true if $childId is a linked member of $parentId.
     */
    private function isChildOf(int $parentId, int $childId): bool
    {
        $parentAdherent = new Adherent($this->zdb, $parentId, ['children' => true]);
        foreach ($parentAdherent->children as $child) {
            $cid = is_object($child) ? (int)$child->id : (int)$child;
            if ($cid === $childId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Phase 47.2: format "Name (pseudo)" if pseudo is set, otherwise just "Name".
     * Used to identify the member concerned in eligibility flash messages and
     * the my_registrations banner.
     */
    private function formatMemberDisplayName(string $name, ?string $nickname): string
    {
        $name = trim($name);
        if ($nickname !== null && trim($nickname) !== '') {
            return $name . ' (' . trim($nickname) . ')';
        }
        return $name;
    }

    /**
     * Phase 47.2: check that a member is eligible for registration.
     *
     * Returns null if the member is eligible, or a translated flash message
     * otherwise. The 3 conditions enforced (in evaluation order):
     *  - Account active (`adherents.activite_adh = 1`)
     *  - Membership status is NOT "Non member" (`statuts.priorite_statut < 99`,
     *    Galette convention)
     *  - Cotisation up to date (`bool_exempt_adh = 1` OR `date_echeance >= today`)
     *
     * If $name is provided it is appended in parentheses to the message so the
     * user can identify which linked member is concerned (parent / child).
     *
     * When $skipExMembers is true, returns null silently for accounts that are
     * inactive OR have the "Non member" status (assumed ex-members or members
     * being phased out). Used by the my_registrations banner to avoid noise;
     * the handlers keep $skipExMembers = false so they always block.
     */
    private function getMemberEligibilityError(
        int $memberId,
        string $name = '',
        bool $skipExMembers = false
    ): ?string {
        if ($memberId <= 0) {
            return _T('Member account not found.', 'courses');
        }
        try {
            $select = $this->zdb->select(Adherent::TABLE, 'a');
            $select->columns(['activite_adh', 'date_echeance', 'bool_exempt_adh']);
            $select->join(
                ['s' => PREFIX_DB . 'statuts'],
                'a.id_statut = s.id_statut',
                ['priorite_statut']
            );
            $select->where(['a.id_adh' => $memberId]);
            $row = $this->zdb->execute($select)->current();

            if (!$row) {
                return _T('Member account not found.', 'courses');
            }

            $isInactive = !(bool)$row->activite_adh;
            $isNonMember = (int)$row->priorite_statut >= 99;

            // Phase 47.2 follow-up: account inactive OR non-member -> silent
            // when called from the banner (assumed ex-member / phased out).
            // Handlers still enforce by default ($skipExMembers = false).
            if ($skipExMembers && ($isInactive || $isNonMember)) {
                return null;
            }

            $tag = $name !== '' ? ' — ' . $name : '';

            if ($isInactive) {
                return _T('Member account is not active.', 'courses') . $tag;
            }
            if ($isNonMember) {
                return _T('Member has the "Non member" status.', 'courses') . $tag;
            }
            $isUp2Date = (bool)$row->bool_exempt_adh
                || (!empty($row->date_echeance) && $row->date_echeance >= date('Y-m-d'));
            if (!$isUp2Date) {
                return _T('Membership is not up to date.', 'courses') . $tag;
            }

            return null;
        } catch (\Throwable $e) {
            Analog::log(
                'Error checking member eligibility for #' . $memberId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return _T('Unable to verify member eligibility.', 'courses');
        }
    }

    /**
     * Phase 47.2 batch helper : check eligibility for several members in a
     * single SQL query and return the SUBSET that satisfies all 3 conditions
     * (active + non-"Non member" status + cotisation up to date).
     *
     * Used by myRegistrations / SessionsController::show to filter the
     * "register" dropdowns (parent + children) so members who would be blocked
     * by the handler aren't even offered as an option in the UI.
     *
     * @param int[] $memberIds
     * @return array<int, true> map of eligible member IDs (use isset / array_key_exists)
     */
    public static function batchEligibleMemberIds(\Galette\Core\Db $zdb, array $memberIds): array
    {
        $eligible = [];
        if (empty($memberIds)) {
            return $eligible;
        }
        try {
            $select = $zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh']);
            $select->join(
                ['s' => PREFIX_DB . 'statuts'],
                'a.id_statut = s.id_statut',
                []
            );
            $select->where->in('a.id_adh', $memberIds);
            $select->where->equalTo('a.activite_adh', true);
            $select->where->lessThan('s.priorite_statut', 99);
            $today = date('Y-m-d');
            $select->where->expression(
                '(a.bool_exempt_adh = 1 OR a.date_echeance >= ?)',
                [$today]
            );
            foreach ($zdb->execute($select) as $r) {
                $eligible[(int)$r->id_adh] = true;
            }
        } catch (\Throwable $e) {
            Analog::log(
                'Error batch-checking member eligibility: ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $eligible;
    }
}
