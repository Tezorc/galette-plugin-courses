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

use Galette\Controllers\Crud\AbstractPluginController;
use Galette\Entity\Adherent;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Registration;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\Entity\Waitlist;
use GaletteCourses\Entity\EventType;
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
class SessionsController extends AbstractPluginController
{
    use CoursesAclGuard;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function list(Request $request, Response $response, ?string $option = null, int|string|null $value = null): Response
    {
        $filter_name = $this->getFilterName('sessions');
        if (isset($this->session->$filter_name)) {
            $filters = $this->session->$filter_name;
        } else {
            // First visit: default to today onwards
            $filters = new SessionsList();
            $filters->date_from = date('Y-m-d');
        }

        if ($option !== null) {
            switch ($option) {
                case 'page':
                    $filters->current_page = (int)$value;
                    break;
                case 'order':
                    $filters->orderby = (int)$value;
                    break;
            }
        }

        $sessions_repo = new Sessions($this->zdb, $this->login, $filters);
        $sessions = $sessions_repo->getList();
        $available_names = $sessions_repo->getAvailableNames();

        // Pre-load events deduplicated by id, then inject into each Session so
        // template calls to session.getEvent() return the cached instance —
        // avoids one Event SELECT per session row in sessions_list.html.twig.
        $events_by_id = [];
        foreach ($sessions as $s) {
            $eid = $s->getEventId();
            if (!isset($events_by_id[$eid])) {
                $events_by_id[$eid] = new Event($this->zdb, $eid);
            }
            $s->setEvent($events_by_id[$eid]);
        }

        // Batch-load instructor names for all sessions in one JOIN query
        $session_ids = array_map(fn($s) => $s->getId(), $sessions);
        $batch_instructor_names = SessionInstructor::getInstructorNamesForSessions($this->zdb, $session_ids);

        $sessions_has_instructor   = [];
        $sessions_instructor_names = [];
        foreach ($sessions as $s) {
            $sid = $s->getId();
            $sessions_instructor_names[$sid] = $batch_instructor_names[$sid] ?? '';
            $sessions_has_instructor[$sid]   = isset($batch_instructor_names[$sid]);
        }

        $this->session->$filter_name = $filters;

        $this->view->render(
            $response,
            $this->getTemplate('pages/sessions_list'),
            [
                'page_title' => _T('Sessions', 'courses'),
                'sessions' => $sessions,
                'nb' => $sessions_repo->getCount(),
                'filters' => $filters,
                'event_types' => EventType::getList($this->zdb),
                'available_names' => $available_names,
                'sessions_has_instructor' => $sessions_has_instructor,
                'sessions_instructor_names' => $sessions_instructor_names,
            ]
        );
        return $response;
    }

    public function filter(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $filter_name = $this->getFilterName('sessions');

        if (isset($post['clear_filter'])) {
            $filters = new SessionsList();
        } else {
            if (isset($this->session->$filter_name)) {
                $filters = $this->session->$filter_name;
            } else {
                $filters = new SessionsList();
            }

            if (isset($post['event_filter'])) {
                $filters->event_filter = $post['event_filter'] !== '' ? (int)$post['event_filter'] : null;
            }
            if (isset($post['type_filter'])) {
                $filters->type_filter = $post['type_filter'];
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
            if (isset($post['status_filter'])) {
                $filters->status_filter = $post['status_filter'];
            }
            if (isset($post['nbshow']) && is_numeric($post['nbshow'])) {
                $filters->show = (int)$post['nbshow'];
            }
        }

        $this->session->$filter_name = $filters;

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
    }

    public function show(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $event = $session->getEvent();

        if (!$event->canAccess($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to view this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        // Restricted groups: resolve names once for the Information block.
        $event->loadGroups();
        $restricted_group_names = [];
        $eventGroupIds = $event->getGroups();
        if (!empty($eventGroupIds)) {
            try {
                $select = $this->zdb->select('groups');
                $select->columns(['id_group', 'group_name']);
                $select->where->in('id_group', $eventGroupIds);
                $select->order('group_name ASC');
                foreach ($this->zdb->execute($select) as $row) {
                    $restricted_group_names[] = (string)$row->group_name;
                }
            } catch (\Throwable $e) {
                Analog::log('Error loading group names for event #' . $event->getId() . ': ' . $e->getMessage(), Analog::ERROR);
            }
        }

        // Load registrations for this session
        $regs_repo = new Registrations($this->zdb);
        $registrations = $regs_repo->getForSession($id);

        // Check if current user is registered or on waitlist
        $is_registered = false;
        $is_on_waitlist = false;
        $waitlist_position = 0;
        if ($this->login->isLogged() && !$this->login->isSuperAdmin()) {
            $memberId = (int)$this->login->id;
            $is_registered = Registration::isRegistered($this->zdb, $id, $memberId);
            if (!$is_registered) {
                $wlEntry = Waitlist::findEntry($this->zdb, $id, $memberId);
                if ($wlEntry !== null) {
                    $is_on_waitlist = true;
                    $waitlist_position = $wlEntry->getPosition();
                }
            }
        }

        // Load instructors for this session
        $instructors = SessionInstructor::getForSession($this->zdb, $id);

        $is_instructor_for_load = false;
        if ($this->login->isLogged() && !$this->login->isSuperAdmin() && $this->login->id !== null) {
            $is_instructor_for_load = SessionInstructor::isInstructor($this->zdb, $id, (int)$this->login->id);
        }
        $is_session_manager_load = $this->login->isAdmin() || $this->login->isStaff() || $is_instructor_for_load;

        // Phase 72: members can see the registered + waitlist lists on the fiche
        // seance (read-only -- actions like cancel/export/mail stay manager-gated
        // in the template). Charge waitlist_entries unconditionally so the right
        // column "Waitlist" section renders for every logged-in user.
        $waitlist_count = Waitlist::getCount($this->zdb, $id);
        $waitlist_entries = Waitlist::getForSession($this->zdb, $id);

        // Batch-load display data (sname + nickname) for everyone shown in
        // the registered/waitlist/instructor blocks — one SELECT instead of
        // one `new Adherent()` per row.
        $memberIdsToDisplay = [];
        foreach ($registrations as $reg) {
            $memberIdsToDisplay[$reg->getMemberId()] = true;
        }
        foreach ($waitlist_entries as $wl) {
            $memberIdsToDisplay[$wl->getMemberId()] = true;
        }
        foreach ($instructors as $instr) {
            $memberIdsToDisplay[$instr->getMemberId()] = true;
        }
        $memberDisplay = $this->batchLoadMemberDisplay(array_keys($memberIdsToDisplay));
        $unknown = _T('Unknown member', 'courses');

        $members = [];
        $nicknames = [];
        foreach ($registrations as $reg) {
            $mid = $reg->getMemberId();
            $members[$mid] = $memberDisplay[$mid]['sname'] ?? $unknown;
            if (!empty($memberDisplay[$mid]['nickname'])) {
                $nicknames[$mid] = $memberDisplay[$mid]['nickname'];
            }
        }
        foreach ($waitlist_entries as $wl) {
            $mid = $wl->getMemberId();
            if (!isset($members[$mid])) {
                $members[$mid] = $memberDisplay[$mid]['sname'] ?? $unknown;
                if (!empty($memberDisplay[$mid]['nickname'])) {
                    $nicknames[$mid] = $memberDisplay[$mid]['nickname'];
                }
            }
        }
        $instructor_members = [];
        foreach ($instructors as $instr) {
            $mid = $instr->getMemberId();
            $instructor_members[$mid] = $memberDisplay[$mid]['sname'] ?? $unknown;
        }

        $has_instructor = SessionInstructor::hasInstructor($this->zdb, $id);
        $is_instructor = $is_instructor_for_load;
        $is_session_manager = $is_session_manager_load;

        // For session managers (staff or session instructors): load eligible
        // instructors (group managers of the event's groups) so they can
        // assign/replace co-instructors.
        $eligible_instructors = [];
        if ($is_session_manager) {
            $event->loadGroups();
            $eventGroups = $event->getGroups();
            if (!empty($eventGroups)) {
                try {
                    $select = $this->zdb->select(\Galette\Entity\Adherent::TABLE, 'a');
                    $select->columns(['id_adh', 'nom_adh', 'prenom_adh']);
                    $select->join(
                        ['gm' => PREFIX_DB . 'groups_managers'],
                        'a.id_adh = gm.id_adh',
                        []
                    );
                    $select->where->in('gm.id_group', $eventGroups);
                    $select->quantifier('DISTINCT');
                    $results = $this->zdb->execute($select);
                    foreach ($results as $r) {
                        $eligible_instructors[(int)$r->id_adh] = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                    }
                } catch (\Throwable $e) {
                    // Silently fail - no eligible instructors
                }
            } else {
                // No group restrictions: all group managers are eligible
                try {
                    $select = $this->zdb->select(\Galette\Entity\Adherent::TABLE, 'a');
                    $select->columns(['id_adh', 'nom_adh', 'prenom_adh']);
                    $select->join(
                        ['gm' => PREFIX_DB . 'groups_managers'],
                        'a.id_adh = gm.id_adh',
                        []
                    );
                    $select->quantifier('DISTINCT');
                    $results = $this->zdb->execute($select);
                    foreach ($results as $r) {
                        $eligible_instructors[(int)$r->id_adh] = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                    }
                } catch (\Throwable $e) {
                    // Silently fail
                }
            }
            // Remove already assigned instructors
            foreach ($instructors as $instr) {
                unset($eligible_instructors[$instr->getMemberId()]);
            }
        }

        // Load children for the current user (for child picker)
        $children = [];
        $children_registered = [];
        $parent_eligible = false; // fail-secure default
        $eventGroups = [];
        $eligibility_set = []; // [memberId => true] — populated below
        if ($this->login->isLogged() && !$this->login->isSuperAdmin() && $this->login->id !== null) {
            // Compute eligibility outside the Adherent try/catch so an Adherent load failure
            // cannot leave parent_eligible at its default value and accidentally grant access.
            $currentMemberId = (int)$this->login->id;
            // Phase 47.2 follow-up: parent eligibility check (active + status +
            // cotisation) outside the try so a children-load failure cannot
            // accidentally grant access. If the SQL itself fails, set is empty
            // -> parent_eligible becomes false (fail-closed).
            $eligibility_set = RegistrationsController::batchEligibleMemberIds(
                $this->zdb,
                [$currentMemberId]
            );
            $parent_eligible = $event->canRegisterSelf($this->login)
                && isset($eligibility_set[$currentMemberId]);
            $eventGroups = $event->getGroups(); // already loaded by canRegisterSelf()

            try {
                $currentAdherent = new Adherent($this->zdb, $currentMemberId, ['children' => true]);
                $childrenIds = $currentAdherent->children;
                // Collect valid child IDs first
                $validChildIds = [];
                foreach ($childrenIds as $child) {
                    $childId = is_object($child) ? (int)$child->id : (int)$child;
                    if ($childId > 0) {
                        $validChildIds[] = $childId;
                    }
                }

                // Phase 47.2 follow-up: also compute eligibility for the children
                // (single SQL query) and merge into the existing parent set.
                if (!empty($validChildIds)) {
                    $eligibility_set += RegistrationsController::batchEligibleMemberIds(
                        $this->zdb,
                        $validChildIds
                    );
                }

                // Batch-load group memberships for all children if event has group restrictions
                $childrenInGroup = [];
                if (!empty($eventGroups) && !empty($validChildIds)) {
                    try {
                        $groupSelect = $this->zdb->select('groups_members');
                        $groupSelect->columns(['id_adh']);
                        $groupSelect->where->in('id_adh', $validChildIds);
                        $groupSelect->where->in('id_group', $eventGroups);
                        $groupSelect->quantifier('DISTINCT');
                        $groupResults = $this->zdb->execute($groupSelect);
                        foreach ($groupResults as $gr) {
                            $childrenInGroup[(int)$gr->id_adh] = true;
                        }
                    } catch (\Throwable $e) {
                        Analog::log('Error batch-loading children group memberships: ' . $e->getMessage(), Analog::ERROR);
                    }
                }

                // Batch-load display data for all children at once instead of
                // creating one Adherent per child (= one SELECT per child).
                $childDisplay = $this->batchLoadMemberDisplay($validChildIds);

                foreach ($validChildIds as $childId) {
                    $childName     = $childDisplay[$childId]['sname']    ?? '';
                    $childNickname = $childDisplay[$childId]['nickname'] ?? '';

                    // A registered child always appears (so the unregister button is always shown),
                    // regardless of current group membership (group may have changed after registration).
                    $isRegistered = Registration::isRegistered($this->zdb, $id, $childId);
                    if ($isRegistered) {
                        $children[$childId] = ['name' => $childName, 'nickname' => $childNickname];
                        $children_registered[] = $childId;
                        continue;
                    }

                    // For non-registered children: filter by event groups before offering registration.
                    if (!empty($eventGroups) && !isset($childrenInGroup[$childId])) {
                        continue;
                    }
                    // Phase 47.2 follow-up: skip children who fail any of the 3
                    // eligibility conditions (active + status + cotisation). The
                    // handler would block them anyway — don't even offer the option.
                    if (!isset($eligibility_set[$childId])) {
                        continue;
                    }

                    $children[$childId] = ['name' => $childName, 'nickname' => $childNickname];
                }
            } catch (\Throwable $e) {
                Analog::log('Error loading children for member #' . ((int)$this->login->id) . ': ' . $e->getMessage(), Analog::ERROR);
            }
        }

        // Load eligible members for walk-in attendance (past or today sessions)
        $walkin_eligible_members = [];
        $can_mark_attendance = ($is_session_manager || $this->login->isGroupManager())
            && $session->getSessionDate() <= date('Y-m-d');
        if ($can_mark_attendance) {
            $event->loadGroups();
            $eventGroups = $event->getGroups();
            try {
                $select = $this->zdb->select(\Galette\Entity\Adherent::TABLE, 'a');
                $select->columns(['id_adh', 'nom_adh', 'prenom_adh', 'pseudo_adh']);
                // Phase 47.2: exclude members with the "Non member" status
                // (statuts.priorite_statut >= 99 by Galette convention).
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

                // Get already registered member IDs
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
                    $walkin_eligible_members[$mid] = [
                        'name' => $name,
                        'nickname' => $nickname,
                    ];
                }
            } catch (\Throwable $e) {
                // Silently fail
            }
        }

        $this->view->render(
            $response,
            $this->getTemplate('pages/session_show'),
            [
                'page_title' => $event->getName() . ' - ' . $session->getFormattedDateLong(),
                'session' => $session,
                'event' => $event,
                'registrations' => $registrations,
                'members' => $members,
                'nicknames' => $nicknames,
                'is_registered' => $is_registered,
                'is_on_waitlist' => $is_on_waitlist,
                'waitlist_position' => $waitlist_position,
                'waitlist_count' => $waitlist_count,
                'waitlist_entries' => $waitlist_entries,
                'instructors' => $instructors,
                'instructor_members' => $instructor_members,
                'has_instructor' => $has_instructor,
                'is_instructor' => $is_instructor,
                'is_session_manager' => $is_session_manager,
                'eligible_instructors' => $eligible_instructors,
                'children' => $children,
                'children_registered' => $children_registered,
                'parent_eligible' => $parent_eligible,
                'current_member_name' => isset($currentAdherent) ? ($currentAdherent->sname ?? '') : '',
                'current_member_nickname'  => isset($currentAdherent) && !empty($currentAdherent->nickname) ? (string)$currentAdherent->nickname : '',
                'walkin_eligible_members' => $walkin_eligible_members,
                'restricted_group_names' => $restricted_group_names,
            ]
        );
        return $response;
    }

    public function doAssignInstructor(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
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

        // A cancelled session will not happen — no instructor can be assigned to it.
        if ($session->getStatus() === Session::STATUS_CANCELLED) {
            $this->flash->addMessage(
                'error_detected',
                _T('This session has been cancelled.', 'courses')
            );
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $post = $request->getParsedBody();
        $memberId = (int)($post['member_id'] ?? 0);
        if ($memberId <= 0) {
            $this->flash->addMessage('error_detected', _T('Please select a member.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        if (SessionInstructor::isInstructor($this->zdb, $id, $memberId)) {
            $this->flash->addMessage('warning_detected', _T('This member is already an instructor for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        // Phase 61: block double-booking — an instructor cannot run two
        // overlapping sessions on the same day.
        if (SessionInstructor::hasOverlappingSession($this->zdb, $memberId, $session->getSessionDate(), $session->getStartTime(), $session->getEndTime(), $id)) {
            $this->flash->addMessage('error_detected', _T('This member already runs another session at the same time on this day.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $wasWithoutInstructor = !SessionInstructor::hasInstructor($this->zdb, $id);

        $instructor = new SessionInstructor($this->zdb);
        $instructor->setSessionId($id);
        $instructor->setMemberId($memberId);
        $instructor->setAssignedBy($this->login->isSuperAdmin() ? null : (int)$this->login->id);

        try {
            if ($instructor->store()) {
                $this->history->add(
                    _T('[Courses] Instructor assigned to session', 'courses'),
                    sprintf('session #%d — member #%d', $id, $memberId)
                );
                $this->flash->addMessage('success_detected', _T('Instructor has been assigned.', 'courses'));

                if ($wasWithoutInstructor) {
                    $event = $session->getEvent();
                    $instructorMember = new \Galette\Entity\Adherent($this->zdb, $memberId);
                    $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
                    $notification->notifyInstructorAssigned($session, $event, (string)$instructorMember->sname);
                }
            } else {
                $this->flash->addMessage('error_detected', _T('An error occurred assigning the instructor.', 'courses'));
            }
        } catch (\Throwable $e) {
            $this->flash->addMessage('error_detected', _T('An error occurred assigning the instructor.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doRemoveInstructor(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
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

        $post = $request->getParsedBody();
        $memberId = (int)($post['member_id'] ?? 0);
        if ($memberId <= 0) {
            $this->flash->addMessage('error_detected', _T('Please select a member.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $entry = SessionInstructor::findEntry($this->zdb, $id, $memberId);
        if ($entry === null) {
            $this->flash->addMessage('error_detected', _T('Instructor not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        if ($entry->remove()) {
            $this->history->add(
                _T('[Courses] Instructor removed from session', 'courses'),
                sprintf('session #%d — member #%d', $id, $memberId)
            );
            $this->flash->addMessage('success_detected', _T('Instructor has been removed.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred removing the instructor.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doVolunteerInstructor(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $memberId = (int)$this->login->id;
        if ($memberId <= 0) {
            return $response->withStatus(302)->withHeader("Location", $this->routeparser->urlFor("coursesSessions"));
        }

        // Actions triggered from the "My instructor sessions" page carry
        // redirect_to=my_instructor_sessions so the page reloads with fresh
        // data (the volunteered session leaves the "Find a session" tab and
        // appears under "My instructor sessions"). Default: session detail page.
        $post = $request->getParsedBody();
        $returnUrl = (is_array($post) && ($post['redirect_to'] ?? '') === 'my_instructor_sessions')
            ? $this->routeparser->urlFor('coursesMyInstructorSessions')
            : $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]);

        // A cancelled session will not happen — no instructor can be assigned to it.
        if ($session->getStatus() === Session::STATUS_CANCELLED) {
            $this->flash->addMessage(
                'error_detected',
                _T('This session has been cancelled.', 'courses')
            );
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Eligibility: admin/staff can self-assign for any session.
        // Group managers must manage one of the event's groups (or no group restriction).
        $event = $session->getEvent();
        $event->loadGroups();
        $eventGroups = $event->getGroups();
        $canVolunteer = false;
        if ($this->login->isAdmin() || $this->login->isStaff()) {
            $canVolunteer = true;
        } elseif (empty($eventGroups)) {
            $canVolunteer = true; // no group restriction
        } else {
            $managed = $this->login->getManagedGroups();
            foreach ($eventGroups as $gid) {
                if (in_array($gid, $managed)) {
                    $canVolunteer = true;
                    break;
                }
            }
        }

        if (!$canVolunteer) {
            $this->flash->addMessage('error_detected', _T('You do not manage any group associated with this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        if (SessionInstructor::isInstructor($this->zdb, $id, $memberId)) {
            $this->flash->addMessage('warning_detected', _T('You are already an instructor for this session.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        // Phase 61: block double-booking — cannot volunteer on a session that
        // overlaps another session already assigned to this instructor.
        if (SessionInstructor::hasOverlappingSession($this->zdb, $memberId, $session->getSessionDate(), $session->getStartTime(), $session->getEndTime(), $id)) {
            $this->flash->addMessage('error_detected', _T('You already run another session at the same time on this day.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $returnUrl);
        }

        $wasWithoutInstructor = !SessionInstructor::hasInstructor($this->zdb, $id);

        $instructor = new SessionInstructor($this->zdb);
        $instructor->setSessionId($id);
        $instructor->setMemberId($memberId);
        $instructor->setAssignedBy($memberId);

        try {
            if ($instructor->store()) {
                $this->history->add(
                    _T('[Courses] Instructor volunteered for session', 'courses'),
                    sprintf('session #%d — member #%d', $id, $memberId)
                );
                $this->flash->addMessage('success_detected', _T('You have been assigned as instructor.', 'courses'));

                if ($wasWithoutInstructor) {
                    $instructorMember = new \Galette\Entity\Adherent($this->zdb, $memberId);
                    $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
                    $notification->notifyInstructorAssigned($session, $event, (string)$instructorMember->sname);
                }

                // Phase 71: append #tab=mine when returning to the My-instructor page,
                // so the JS auto-switches and pulses the "Mes seances moniteur" tab
                // to surface that the volunteered session is now listed there.
                $successUrl = (is_array($post) && ($post['redirect_to'] ?? '') === 'my_instructor_sessions')
                    ? $this->routeparser->urlFor('coursesMyInstructorSessions') . '#tab=mine'
                    : $returnUrl;
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $successUrl);
            }
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        } catch (\Throwable $e) {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $returnUrl);
    }

    public function doClose(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
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

        if ($session->getStatus() !== Session::STATUS_OPEN) {
            $this->flash->addMessage('error_detected', _T('Only open sessions can be closed.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $session->setStatus(Session::STATUS_CLOSED);

        if ($session->store()) {
            $this->history->add(
                _T('[Courses] Session closed', 'courses'),
                sprintf('session #%d', $id)
            );

            // Purge waitlist silently (closing is temporary, no notification needed)
            $purged = Waitlist::clearForSession($this->zdb, $id);
            if (!empty($purged)) {
                $this->history->add(
                    _T('[Courses] Waitlist purged after closing', 'courses'),
                    sprintf('session #%d — %d member(s) removed', $id, count($purged))
                );
            }

            $this->flash->addMessage('success_detected', _T('Session has been closed. Members can no longer register.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred closing the session.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doReopen(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
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

        if ($session->getStatus() !== Session::STATUS_CLOSED) {
            $this->flash->addMessage('error_detected', _T('Only closed sessions can be reopened.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $session->setStatus(Session::STATUS_OPEN);

        if ($session->store()) {
            $this->history->add(
                _T('[Courses] Session reopened', 'courses'),
                sprintf('session #%d', $id)
            );
            $this->flash->addMessage('success_detected', _T('Session has been reopened. Members can register again.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred reopening the session.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function doCancel(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
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

        if ($session->getStatus() !== Session::STATUS_OPEN) {
            $this->flash->addMessage('error_detected', _T('This session cannot be cancelled.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $post = $request->getParsedBody();
        $reason = !empty($post['cancellation_reason']) ? trim($post['cancellation_reason']) : null;
        $comment = !empty($post['cancellation_comment']) ? trim($post['cancellation_comment']) : null;

        $session->setStatus(Session::STATUS_CANCELLED);
        $session->setCancellationReason($reason);
        $session->setCancellationComment($comment);

        if ($session->store()) {
            $this->history->add(
                _T('[Courses] Session cancelled', 'courses'),
                sprintf('session #%d — reason: %s', $id, $reason ?? 'none')
            );
            $event = $session->getEvent();
            $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);

            // Notify registered members
            $notification->notifySessionCancellation($session, $event, $reason, $comment);

            // Purge waitlist and notify waiting members
            $waitlistMemberIds = Waitlist::clearForSession($this->zdb, $id);
            if (!empty($waitlistMemberIds)) {
                $this->history->add(
                    _T('[Courses] Waitlist purged after cancellation', 'courses'),
                    sprintf('session #%d — %d member(s) removed', $id, count($waitlistMemberIds))
                );
                $notification->notifyWaitlistSessionCancellation($session, $event, $waitlistMemberIds, $reason, $comment);
            }

            $this->flash->addMessage('success_detected', _T('Session has been cancelled.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred cancelling the session.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    /**
     * Reactivate a cancelled session
     */
    public function doReactivate(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
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

        if ($session->getStatus() !== Session::STATUS_CANCELLED) {
            $this->flash->addMessage('error_detected', _T('Only cancelled sessions can be reactivated.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $session->setStatus(Session::STATUS_OPEN);
        $session->setCancellationReason(null);
        $session->setCancellationComment(null);

        if ($session->store()) {
            $this->history->add(
                _T('[Courses] Session reactivated', 'courses'),
                sprintf('session #%d', $id)
            );

            $event = $session->getEvent();
            $notification = new CourseNotification(
                $this->zdb,
                $this->preferences,
                new PluginPreferences($this->zdb),
                new MemberPreferences($this->zdb),
                $this->history
            );

            if (SessionInstructor::hasInstructor($this->zdb, $id)) {
                // Session already has a monitor: notify eligible members directly
                $instructors = SessionInstructor::getForSession($this->zdb, $id);
                $instructorName = '';
                if (!empty($instructors)) {
                    $adh = new \Galette\Entity\Adherent($this->zdb, $instructors[0]->getMemberId());
                    $instructorName = (string)$adh->sname;
                }
                $notification->notifyInstructorAssigned($session, $event, $instructorName);
            } else {
                // No monitor yet: notify group managers so they can volunteer.
                // Reactivation = the session is back in circulation, equivalent
                // to a fresh session creation -> use notifyNewSessions (single
                // session passed as a 1-element array). Same template as
                // regular session creation, dates_list contains the one date.
                $notification->notifyNewSessions($event, [$session]);
            }

            $this->flash->addMessage('success_detected', _T('Session has been reactivated.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred reactivating the session.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    /**
     * Edit session max_capacity. If capacity is increased and there is a waitlist,
     * promote as many people as new spots allow (FIFO), with notification.
     */
    public function doEditCapacity(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $post = $request->getParsedBody();
        $rawCapacity = trim((string)($post['max_capacity'] ?? ''));
        $newCapacity = $rawCapacity === '' ? null : (int)$rawCapacity;

        // Capacity can only be increased, never decreased
        $oldCapacity = $session->getMaxCapacity();
        if ($newCapacity !== null && $oldCapacity !== null && $newCapacity < $oldCapacity) {
            $this->flash->addMessage(
                'error_detected',
                sprintf(_T('Capacity cannot be decreased (current: %d).', 'courses'), $oldCapacity)
            );
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }
        if ($newCapacity !== null && $newCapacity < $session->getCurrentRegistrations()) {
            $this->flash->addMessage(
                'error_detected',
                sprintf(_T('Minimum: %d (current registrations)', 'courses'), $session->getCurrentRegistrations())
            );
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $oldCapacity = $session->getMaxCapacity();
        $session->setMaxCapacity($newCapacity);
        $session->store();

        $this->history->add(
            _T('[Courses] Session capacity updated', 'courses'),
            sprintf('session #%d: %s → %s', $id, $oldCapacity ?? '∞', $newCapacity ?? '∞')
        );

        // Auto-promote from waitlist if capacity increased
        $promoted = 0;
        $capacityIncreased = ($newCapacity === null)
            || ($oldCapacity !== null && $newCapacity > $oldCapacity);

        if ($capacityIncreased && Waitlist::getCount($this->zdb, $id) > 0) {
            $event = $session->getEvent();
            $notification = new CourseNotification(
                $this->zdb,
                $this->preferences,
                new PluginPreferences($this->zdb),
                new MemberPreferences($this->zdb),
                $this->history
            );
            while (!$session->isFull() && ($memberId = Waitlist::promoteFirst($this->zdb, $session)) !== null) {
                $notification->notifyWaitlistPromotion($session, $event, $memberId);
                $promoted++;
                // Reload the session to get updated current_registrations count
                $session = new Session($this->zdb, $id);
            }
        }

        if ($promoted > 0) {
            $this->flash->addMessage(
                'success_detected',
                sprintf(_T('Session capacity updated. %d member(s) promoted from the waitlist.', 'courses'), $promoted)
            );
        } else {
            $this->flash->addMessage('success_detected', _T('Session capacity updated.', 'courses'));
        }

        return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    /**
     * Manually promote the next person on the waitlist.
     */
    public function doPromoteWaitlist(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $memberId = Waitlist::promoteFirst($this->zdb, $session);
        if ($memberId === null) {
            $this->flash->addMessage('warning_detected', _T('The waitlist is empty.', 'courses'));
        } else {
            $event = $session->getEvent();
            $notification = new CourseNotification(
                $this->zdb,
                $this->preferences,
                new PluginPreferences($this->zdb),
                new MemberPreferences($this->zdb),
                $this->history
            );
            $notification->notifyWaitlistPromotion($session, $event, $memberId);
            $this->history->add(
                _T('[Courses] Member promoted from waitlist', 'courses'),
                sprintf('session #%d — member #%d', $id, $memberId)
            );
            $this->flash->addMessage('success_detected', _T('Member promoted from the waitlist.', 'courses'));
        }

        return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    /**
     * Create a new session (same event + same time slots) and register all waitlisted members.
     */
    public function doSessionForWaitlist(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
        );
        if ($deny !== null) {
            return $deny;
        }

        $source = new Session($this->zdb, $id);
        if ($source->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $waitlist = Waitlist::getForSession($this->zdb, $id);
        if (empty($waitlist)) {
            $this->flash->addMessage('warning_detected', _T('The waitlist is empty.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $post = $request->getParsedBody();
        $newDate = trim((string)($post['new_date'] ?? ''));
        if ($newDate === '' || !\DateTime::createFromFormat('Y-m-d', $newDate)) {
            $this->flash->addMessage('error_detected', _T('A valid date is required to create the new session.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        // Create the new session copying all attributes from the source
        $newSession = new Session($this->zdb);
        $newSession->setEventId($source->getEventId());
        $newSession->setSessionDate($newDate);
        $newSession->setStartTime($source->getStartTime());
        $newSession->setEndTime($source->getEndTime());
        $newSession->setStatus(Session::STATUS_OPEN);
        $newSession->setMaxCapacity($source->getMaxCapacity());
        $newSession->store();

        $newId = $newSession->getId();
        if ($newId === null) {
            $this->flash->addMessage('error_detected', _T('An error occurred creating the new session.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        // Register all waitlisted members in the new session and purge the waitlist
        $event = $source->getEvent();
        $notification = new CourseNotification(
            $this->zdb,
            $this->preferences,
            new PluginPreferences($this->zdb),
            new MemberPreferences($this->zdb),
            $this->history
        );

        $registered = 0;
        foreach ($waitlist as $wlEntry) {
            $reg = new Registration($this->zdb);
            $reg->setSessionId($newId);
            $reg->setMemberId($wlEntry->getMemberId());
            $reg->store($newSession);
            // Notify each member they have been automatically registered in the new session
            $notification->notifyWaitlistPromotion($newSession, $event, $wlEntry->getMemberId());
            $registered++;
        }
        // Purge original waitlist
        Waitlist::clearForSession($this->zdb, $id);

        $this->history->add(
            _T('[Courses] New session created for waitlist', 'courses'),
            sprintf('source session #%d → new session #%d — %d member(s) registered', $id, $newId, $registered)
        );
        $this->flash->addMessage(
            'success_detected',
            sprintf(_T('New session created on %s. %d member(s) from the waitlist have been registered.', 'courses'), $newDate, $registered)
        );

        return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$newId]));
    }

    public function add(Request $request, Response $response): Response
    {
        return $response->withStatus(404);
    }

    public function doAdd(Request $request, Response $response): Response
    {
        return $response->withStatus(404);
    }

    public function edit(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        if (!$this->canEditSession($session)) {
            $this->flash->addMessage('error_detected', _T('This session can no longer be edited.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $event = new Event($this->zdb, $session->getEventId());

        $this->view->render($response, $this->getTemplate('pages/session_edit'), [
            'page_title' => _T('Edit session', 'courses'),
            'session'    => $session,
            'event'      => $event,
        ]);
        return $response;
    }

    public function doEdit(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessSessionManager(
            $id,
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id])
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        if (!$this->canEditSession($session)) {
            $this->flash->addMessage('error_detected', _T('This session can no longer be edited.', 'courses'));
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
        }

        $post = $request->getParsedBody();
        $errors = [];

        $date       = trim((string)($post['session_date'] ?? ''));
        $startTime  = trim((string)($post['start_time'] ?? ''));
        $endTime    = trim((string)($post['end_time'] ?? ''));
        $capacity   = trim((string)($post['max_capacity'] ?? ''));

        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = _T('Invalid date.', 'courses');
        }
        if ($startTime === '' || !preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            $errors[] = _T('Invalid start time.', 'courses');
        }
        if ($endTime === '' || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            $errors[] = _T('Invalid end time.', 'courses');
        }
        if ($startTime !== '' && $endTime !== '' && $endTime <= $startTime) {
            $errors[] = _T('End time must be after start time.', 'courses');
        }
        if ($date < date('Y-m-d')) {
            $errors[] = _T('Session date must be today or in the future.', 'courses');
        }
        $newCapacity = $capacity !== '' && is_numeric($capacity) && (int)$capacity > 0 ? (int)$capacity : null;
        $oldCapacity = $session->getMaxCapacity();
        if ($newCapacity !== null && $oldCapacity !== null && $newCapacity < $oldCapacity) {
            $errors[] = sprintf(_T('Capacity cannot be decreased (current: %d).', 'courses'), $oldCapacity);
        } elseif ($newCapacity !== null && $newCapacity < $session->getCurrentRegistrations()) {
            $errors[] = sprintf(_T('Minimum: %d (current registrations)', 'courses'), $session->getCurrentRegistrations());
        }

        if (!empty($errors)) {
            foreach ($errors as $err) {
                $this->flash->addMessage('error_detected', $err);
            }
            return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionEdit', ['id' => (string)$id]));
        }

        $session->setSessionDate($date);
        $session->setStartTime($startTime . ':00');
        $session->setEndTime($endTime . ':00');
        $session->setMaxCapacity($newCapacity);

        try {
            $session->store();
            $this->flash->addMessage('success_detected', _T('Session updated successfully.', 'courses'));
        } catch (\Throwable $e) {
            Analog::log('Error updating session #' . $id . ': ' . $e->getMessage(), Analog::ERROR);
            $this->flash->addMessage('error_detected', _T('An error occurred while saving the session.', 'courses'));
        }

        return $response->withStatus(302)->withHeader('Location', $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]));
    }

    public function exportRegistrations(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessStaffOrGroupManager(
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]),
            _T('You do not have permission to export this session.', 'courses')
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

        // --- Batch-load registered members with a single JOIN query ---
        $regsData = [];
        try {
            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->columns(['id_registration', 'member_id', 'registration_date', 'status']);
            $select->join(
                ['a' => PREFIX_DB . \Galette\Entity\Adherent::TABLE],
                'r.member_id = a.id_adh',
                ['nom_adh', 'prenom_adh', 'email_adh', 'pseudo_adh', 'tel_adh', 'gsm_adh']
            );
            $select->where(['r.session_id' => $id]);
            $select->where->notEqualTo('r.status', Registration::STATUS_CANCELLED);
            $select->order(['a.nom_adh ASC', 'a.prenom_adh ASC']);
            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $regsData[] = $r;
            }
        } catch (\Throwable $e) {
            Analog::log('Export registrations query failed: ' . $e->getMessage(), Analog::ERROR);
        }

        // --- Batch-load waitlist members with a single JOIN query ---
        $waitlistData = [];
        try {
            $select = $this->zdb->select(Waitlist::TABLE, 'w');
            $select->columns(['position', 'added_date', 'member_id']);
            $select->join(
                ['a' => PREFIX_DB . \Galette\Entity\Adherent::TABLE],
                'w.member_id = a.id_adh',
                ['nom_adh', 'prenom_adh', 'email_adh', 'pseudo_adh', 'tel_adh', 'gsm_adh']
            );
            $select->where(['w.session_id' => $id]);
            $select->order('w.position ASC');
            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $waitlistData[] = $r;
            }
        } catch (\Throwable $e) {
            Analog::log('Export waitlist query failed: ' . $e->getMessage(), Analog::ERROR);
        }

        // --- Build CSV rows ---
        $rows = [];

        // Section 1: Registered members
        $rows[] = [_T('Registered members', 'courses')];
        $rows[] = [
            _T('Last name', 'courses'),
            _T('First name', 'courses'),
            _T('Nickname', 'courses'),
            _T('Email', 'courses'),
            _T('Phone', 'courses'),
            _T('Registration date', 'courses'),
            _T('Attendance', 'courses'),
        ];

        foreach ($regsData as $r) {
            $statusLabel = match ((string)$r->status) {
                Registration::STATUS_ATTENDED             => _T('Attended', 'courses'),
                Registration::STATUS_ABSENT               => _T('Absent', 'courses'),
                Registration::STATUS_ABSENT_EXCUSED       => _T('Absent (excused)', 'courses'),
                Registration::STATUS_PRESENT_UNREGISTERED => _T('Present (unregistered)', 'courses'),
                default                                   => _T('Registered', 'courses'),
            };
            $tel = trim((string)($r->tel_adh ?? ''));
            $gsm = trim((string)($r->gsm_adh ?? ''));
            $phone = $tel && $gsm ? $tel . ' / ' . $gsm : ($tel ?: $gsm);
            $rows[] = [
                (string)($r->nom_adh ?? ''),
                (string)($r->prenom_adh ?? ''),
                (string)($r->pseudo_adh ?? ''),
                (string)($r->email_adh ?? ''),
                $phone,
                (string)($r->registration_date ?? ''),
                $statusLabel,
            ];
        }

        // Blank separator
        $rows[] = [];

        // Section 2: Waitlist
        $rows[] = [_T('Waitlist', 'courses')];
        $rows[] = [
            _T('Position', 'courses'),
            _T('Last name', 'courses'),
            _T('First name', 'courses'),
            _T('Nickname', 'courses'),
            _T('Email', 'courses'),
            _T('Phone', 'courses'),
            _T('Added date', 'courses'),
        ];

        foreach ($waitlistData as $r) {
            $tel = trim((string)($r->tel_adh ?? ''));
            $gsm = trim((string)($r->gsm_adh ?? ''));
            $phone = $tel && $gsm ? $tel . ' / ' . $gsm : ($tel ?: $gsm);
            $rows[] = [
                (string)($r->position ?? ''),
                (string)($r->nom_adh ?? ''),
                (string)($r->prenom_adh ?? ''),
                (string)($r->pseudo_adh ?? ''),
                (string)($r->email_adh ?? ''),
                $phone,
                (string)($r->added_date ?? ''),
            ];
        }

        // --- Generate CSV with UTF-8 BOM for Excel ---
        $eventName = $event->getName();
        $eventSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($eventName !== '' ? $eventName : 'session'));
        $filename  = 'seance_' . $session->getSessionDate() . '_' . $eventSlug . '.csv';

        $csvLines = [];
        foreach ($rows as $row) {
            $cells = array_map(
                static fn(string $cell): string => '"' . str_replace('"', '""', $cell) . '"',
                $row
            );
            $csvLines[] = implode(';', $cells);
        }

        $csv = "\xEF\xBB\xBF" . implode("\r\n", $csvLines);

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * A session is editable if it is not cancelled and its date is today or in the future.
     */
    private function canEditSession(Session $session): bool
    {
        return $session->getStatus() !== Session::STATUS_CANCELLED
            && $session->getSessionDate() >= date('Y-m-d');
    }

    /**
     * Batch-load display data (sname + nickname) for a set of member ids in
     * one SELECT, replacing the per-id `new Adherent()` pattern that produced
     * an N+1 in the session detail page.
     *
     * Recomposes Adherent::sname (uppercase last name + capitalized first
     * name) from raw columns to keep visual identity with the magic getter.
     *
     * @param array<int> $memberIds
     * @return array<int, array{sname: string, nickname: string}>
     */
    private function batchLoadMemberDisplay(array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }
        $unique = array_values(array_unique(array_map('intval', $memberIds)));
        $result = [];
        try {
            $select = $this->zdb->select(\Galette\Entity\Adherent::TABLE, 'a');
            $select->columns(['id_adh', 'nom_adh', 'prenom_adh', 'pseudo_adh']);
            $select->where->in('a.id_adh', $unique);
            foreach ($this->zdb->execute($select) as $r) {
                $name    = (string)($r->nom_adh ?? '');
                $surname = (string)($r->prenom_adh ?? '');
                $sname   = trim(
                    mb_strtoupper($name, 'UTF-8')
                    . ' '
                    . ucwords(mb_strtolower($surname, 'UTF-8'), " \t\r\n\f\v-")
                );
                $result[(int)$r->id_adh] = [
                    'sname'    => $sname,
                    'nickname' => (string)($r->pseudo_adh ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            Analog::log(
                'Batch member display load failed: ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $result;
    }

    public function redirectUri(array $args): string
    {
        return $this->routeparser->urlFor('coursesSessions');
    }

    public function formUri(array $args): string
    {
        return $this->routeparser->urlFor('coursesSessions');
    }

    public function confirmRemoveTitle(array $args): string
    {
        return _T('Remove session', 'courses');
    }

    protected function doDelete(array $args, array $post): bool
    {
        return false;
    }

    /**
     * Prepare Galette mailing with registered + waitlist members, then redirect to mailing page.
     */
    public function mailSession(Request $request, Response $response, int $id): Response
    {
        $deny = $this->denyUnlessStaffOrGroupManager(
            $response,
            $this->routeparser->urlFor('coursesSessionShow', ['id' => (string)$id]),
            _T('You do not have permission to send a mailing for this session.', 'courses')
        );
        if ($deny !== null) {
            return $deny;
        }

        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        // Collect member IDs: registered (non-cancelled) + waitlist
        $memberIds = [];

        $selReg = $this->zdb->select(Registration::TABLE, 'r');
        $selReg->columns(['member_id'])
            ->where(['r.session_id' => $id])
            ->where->notEqualTo('r.status', Registration::STATUS_CANCELLED);
        foreach ($this->zdb->execute($selReg) as $row) {
            $memberIds[(int)$row['member_id']] = true;
        }

        $selWl = $this->zdb->select(Waitlist::TABLE, 'w');
        $selWl->columns(['member_id'])->where(['w.session_id' => $id]);
        foreach ($this->zdb->execute($selWl) as $row) {
            $memberIds[(int)$row['member_id']] = true;
        }

        $memberIds = array_keys($memberIds);

        // Build recipients array (member_id => Adherent) for Galette Mailing
        $recipients = [];
        foreach ($memberIds as $mid) {
            $adh = new \Galette\Entity\Adherent($this->zdb, $mid);
            if (!empty($adh->email)) {
                $recipients[$mid] = $adh;
            }
        }

        // Store mailing in session (Galette mailing page reads it when mailing_new is absent)
        $this->session->mailing = new \Galette\Core\Mailing($this->preferences, $recipients);

        return $response->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('mailing'));
    }

    /**
     * Page "Mes seances comme moniteur" — two tabs:
     *  1. "Find a session" : sessions where the user can become instructor
     *     (admin/staff = all, group managers = events with at least one
     *     managed group or no group restriction; regular members = none).
     *  2. "My instructor sessions" : sessions where the user is already
     *     instructor, split next / upcoming / cancelled / past.
     */
    public function myInstructorSessions(Request $request, Response $response): Response
    {
        $member_id = (int)$this->login->id;
        if ($member_id <= 0) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        // ============================================================
        // Tab 2 : sessions where I'm already instructor
        // ============================================================
        $session_ids = SessionInstructor::getSessionIdsForMember($this->zdb, $member_id);

        $sessions = [];
        $events   = [];
        foreach ($session_ids as $sid) {
            $s = new Session($this->zdb, $sid);
            if ($s->getId() === null) {
                continue;
            }
            $sessions[$sid] = $s;
            $eid = $s->getEventId();
            if (!isset($events[$eid])) {
                $events[$eid] = new Event($this->zdb, $eid);
            }
        }

        uasort($sessions, static function (Session $a, Session $b): int {
            return strcmp(
                $a->getSessionDate() . $a->getStartTime(),
                $b->getSessionDate() . $b->getStartTime()
            );
        });

        $instructor_names = SessionInstructor::getInstructorNamesForSessions(
            $this->zdb,
            array_keys($sessions)
        );

        // ============================================================
        // Tab 1 : sessions I could become instructor for ("Find")
        // ============================================================
        $is_admin_or_staff = $this->login->isAdmin() || $this->login->isStaff();
        $is_group_manager  = $this->login->isGroupManager();
        $can_volunteer     = $is_admin_or_staff || $is_group_manager;

        $volunteer_sessions     = [];
        $volunteer_cancelled_sessions = [];
        $volunteer_all_sessions = [];
        $volunteer_events       = [];
        $volunteer_event_types  = [];
        $volunteer_available_names = [];

        if ($can_volunteer) {
            $volunteer_filters = new SessionsList();
            $volunteer_filters->date_from = date('Y-m-d');
            $volunteer_filters->status_filter = Session::STATUS_OPEN;
            $volunteer_repo = new Sessions($this->zdb, $this->login, $volunteer_filters);
            $candidates = $volunteer_repo->getList();

            // Batch: which candidate sessions already have at least one instructor?
            $candidate_ids = array_keys($candidates);
            $with_instructor_map = SessionInstructor::getInstructorNamesForSessions(
                $this->zdb,
                $candidate_ids
            );
            $sessions_with_instructor = array_flip(array_keys($with_instructor_map));

            $own_session_id_set = array_flip($session_ids);
            $managed_groups = $this->login->getManagedGroups();

            // Shared eligibility test: may the current user be instructor for $event?
            $isEligibleEvent = static function (Event $event) use ($is_admin_or_staff, $managed_groups): bool {
                if ($is_admin_or_staff) {
                    return true;
                }
                $eventGroups = $event->getGroups();
                if (empty($eventGroups)) {
                    return true;
                }
                foreach ($eventGroups as $gid) {
                    if (in_array($gid, $managed_groups, true)) {
                        return true;
                    }
                }
                return false;
            };

            foreach ($candidates as $sid => $s) {
                // Skip sessions I'm already instructor of (shown in Tab 2)
                if (isset($own_session_id_set[$sid])) {
                    continue;
                }
                // Skip sessions that already have an instructor
                if (isset($sessions_with_instructor[$sid])) {
                    continue;
                }

                $eid = $s->getEventId();
                if (!isset($volunteer_events[$eid])) {
                    $event = new Event($this->zdb, $eid);
                    $event->loadGroups();
                    $volunteer_events[$eid] = $event;
                }

                if (!$isEligibleEvent($volunteer_events[$eid])) {
                    continue;
                }

                $volunteer_sessions[$sid] = $s;
            }

            uasort($volunteer_sessions, static function (Session $a, Session $b): int {
                return strcmp(
                    $a->getSessionDate() . $a->getStartTime(),
                    $b->getSessionDate() . $b->getStartTime()
                );
            });

            // Phase 52: cancelled upcoming sessions — informational, so a potential
            // instructor knows a slot they might consider has been cancelled. Same
            // eligibility scope as the volunteer list; sessions the user is already
            // instructor of are skipped (they appear in the "My instructor sessions"
            // tab cancelled section).
            $cancelled_filters = new SessionsList();
            $cancelled_filters->date_from = date('Y-m-d');
            $cancelled_filters->status_filter = Session::STATUS_CANCELLED;
            $cancelled_repo = new Sessions($this->zdb, $this->login, $cancelled_filters);
            foreach ($cancelled_repo->getList() as $sid => $s) {
                if (isset($own_session_id_set[$sid])) {
                    continue;
                }

                $eid = $s->getEventId();
                if (!isset($volunteer_events[$eid])) {
                    $event = new Event($this->zdb, $eid);
                    $event->loadGroups();
                    $volunteer_events[$eid] = $event;
                }

                if (!$isEligibleEvent($volunteer_events[$eid])) {
                    continue;
                }

                $volunteer_cancelled_sessions[$sid] = $s;
            }

            uasort($volunteer_cancelled_sessions, static function (Session $a, Session $b): int {
                return strcmp(
                    $a->getSessionDate() . $a->getStartTime(),
                    $b->getSessionDate() . $b->getStartTime()
                );
            });

            // Phase 65: open + cancelled volunteer sessions in ONE grid, sorted by
            // date. Union (+) keeps the integer session-id keys (the template needs
            // them); the two sets are disjoint, so no collision. array_merge would
            // reindex integer keys, so it must NOT be used here.
            $volunteer_all_sessions = $volunteer_sessions + $volunteer_cancelled_sessions;
            uasort($volunteer_all_sessions, static function (Session $a, Session $b): int {
                return strcmp(
                    $a->getSessionDate() . $a->getStartTime(),
                    $b->getSessionDate() . $b->getStartTime()
                );
            });

            // Drop loaded events that aren't actually shown
            $shown_event_ids = [];
            foreach ($volunteer_sessions as $s) {
                $shown_event_ids[$s->getEventId()] = true;
            }
            foreach ($volunteer_cancelled_sessions as $s) {
                $shown_event_ids[$s->getEventId()] = true;
            }
            $volunteer_events = array_intersect_key($volunteer_events, $shown_event_ids);

            // Filter dropdowns: types + names actually present in the result set
            $volunteer_event_types = EventType::getList($this->zdb);
            $seen_names = [];
            foreach ($volunteer_events as $ev) {
                $key = $ev->getName() . '|' . $ev->getTypeId();
                if (!isset($seen_names[$key])) {
                    $seen_names[$key] = true;
                    $volunteer_available_names[] = [
                        'name'    => $ev->getName(),
                        'type_id' => $ev->getTypeId(),
                    ];
                }
            }
        }

        // Phase 61: schedule conflict detection for instructor assignments.
        // Build list of "active future instructor sessions" then flag:
        //  - $instr_conflicts[sid]      => pairs of my own overlapping sessions
        //  - $volunteer_conflicts[sid]  => candidate sessions overlapping one I already run
        $today = date('Y-m-d');
        $active_future_instr = [];
        foreach ($sessions as $sid => $s) {
            if ($s->getStatus() === Session::STATUS_CANCELLED) {
                continue;
            }
            if ($s->getSessionDate() < $today) {
                continue;
            }
            $ev = $events[$s->getEventId()] ?? null;
            $active_future_instr[] = [
                'sid'        => $sid,
                'event_name' => $ev !== null ? (string)$ev->getName() : '',
                'date'       => $s->getSessionDate(),
                'start'      => $s->getStartTime(),
                'end'        => $s->getEndTime(),
            ];
        }

        $formatConflictLabel = static function (array $r): string {
            $name = $r['event_name'] !== '' ? $r['event_name'] : '#' . $r['sid'];
            $dateLabel = (string)$r['date'];
            try {
                $dt = new \DateTime($dateLabel);
                $dateLabel = $dt->format('d/m');
            } catch (\Throwable) {
            }
            $start = substr((string)$r['start'], 0, 5);
            $end   = substr((string)$r['end'], 0, 5);
            return $name . ' ' . $dateLabel . ' ' . $start . '-' . $end;
        };

        $instr_conflicts = [];
        $count = count($active_future_instr);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $active_future_instr[$i];
                $b = $active_future_instr[$j];
                if ($a['date'] !== $b['date']) {
                    continue;
                }
                if ($a['start'] >= $b['end'] || $a['end'] <= $b['start']) {
                    continue;
                }
                $instr_conflicts[$a['sid']][] = $formatConflictLabel($b);
                $instr_conflicts[$b['sid']][] = $formatConflictLabel($a);
            }
        }

        $volunteer_conflicts = [];
        foreach ($volunteer_sessions as $sid => $s) {
            $labels = [];
            foreach ($active_future_instr as $other) {
                if ($other['sid'] === $sid) {
                    continue;
                }
                if ($other['date'] !== $s->getSessionDate()) {
                    continue;
                }
                if ($other['start'] >= $s->getEndTime() || $other['end'] <= $s->getStartTime()) {
                    continue;
                }
                $labels[] = $formatConflictLabel($other);
            }
            if (!empty($labels)) {
                $volunteer_conflicts[$sid] = array_values(array_unique($labels));
            }
        }

        $this->view->render(
            $response,
            $this->getTemplate('pages/my_instructor_sessions'),
            [
                'page_title'         => _T('My instructor sessions', 'courses'),
                'sessions'           => $sessions,
                'events'             => $events,
                'instructor_names'   => $instructor_names,
                'current_member_id'  => $member_id,
                'can_export'         => $this->login->isAdmin()
                                          || $this->login->isStaff()
                                          || $this->login->isGroupManager(),
                'can_volunteer'             => $can_volunteer,
                'volunteer_sessions'        => $volunteer_sessions,
                'volunteer_cancelled_sessions' => $volunteer_cancelled_sessions,
                'volunteer_all_sessions'    => $volunteer_all_sessions,
                'volunteer_events'          => $volunteer_events,
                'volunteer_event_types'     => $volunteer_event_types,
                'volunteer_available_names' => $volunteer_available_names,
                'instr_conflicts'           => $instr_conflicts,
                'volunteer_conflicts'       => $volunteer_conflicts,
            ]
        );
        return $response;
    }
}
