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
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\EventType;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\Filters\EventsList;
use GaletteCourses\MemberPreferences;
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\PluginPreferences;
use GaletteCourses\Recurrence\RecurrenceHandler;
use GaletteCourses\Repository\Events;
use GaletteCourses\Repository\Sessions as SessionsRepository;
use Galette\Repository\Groups;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;
use Analog\Analog;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class EventsController extends AbstractPluginController
{
    use CoursesAclGuard;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function list(Request $request, Response $response, ?string $option = null, int|string|null $value = null): Response
    {
        if (
            $deny = $this->denyUnlessCanAuthorEvents(
                $response,
                $this->routeparser->urlFor('coursesMyRegistrations')
            )
        ) {
            return $deny;
        }

        $filter_name = $this->getFilterName('events');
        if (isset($this->session->$filter_name)) {
            $filters = $this->session->$filter_name;
        } else {
            $filters = new EventsList();
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

        $events_repo = new Events($this->zdb, $this->login, $filters);
        $events = $events_repo->getList();
        $available_names = $events_repo->getAvailableNames();

        $this->session->$filter_name = $filters;

        // Assign variables and render
        $this->view->render(
            $response,
            $this->getTemplate('pages/events_list'),
            [
                'page_title' => _T('Events', 'courses'),
                'events' => $events,
                'nb' => $events_repo->getCount(),
                'filters' => $filters,
                'event_types' => EventType::getList($this->zdb),
                'available_names' => $available_names,
            ]
        );
        return $response;
    }

    public function filter(Request $request, Response $response): Response
    {
        if (
            $deny = $this->denyUnlessCanAuthorEvents(
                $response,
                $this->routeparser->urlFor('coursesMyRegistrations')
            )
        ) {
            return $deny;
        }

        $post = $request->getParsedBody();
        $filter_name = $this->getFilterName('events');

        if (isset($post['clear_filter'])) {
            $filters = new EventsList();
        } else {
            if (isset($this->session->$filter_name)) {
                $filters = $this->session->$filter_name;
            } else {
                $filters = new EventsList();
            }

            if (isset($post['filter_str'])) {
                $filters->filter_str = $post['filter_str'];
            }
            if (isset($post['type_filter'])) {
                $filters->type_filter = $post['type_filter'] !== '' ? (int)$post['type_filter'] : null;
            }
            if (isset($post['status_filter'])) {
                $filters->status_filter = $post['status_filter'];
            }
            if (isset($post['name_filter'])) {
                $filters->name_filter = $post['name_filter'] !== '' ? $post['name_filter'] : null;
            }
            if (isset($post['nbshow']) && is_numeric($post['nbshow'])) {
                $filters->show = (int)$post['nbshow'];
            }
        }

        $this->session->$filter_name = $filters;

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
    }

    public function add(Request $request, Response $response): Response
    {
        if (
            $deny = $this->denyUnlessCanAuthorEvents(
                $response,
                $this->routeparser->urlFor('coursesMyRegistrations')
            )
        ) {
            return $deny;
        }
        return $this->showForm($response, new Event($this->zdb));
    }

    public function doAdd(Request $request, Response $response): Response
    {
        if (
            $deny = $this->denyUnlessCanAuthorEvents(
                $response,
                $this->routeparser->urlFor('coursesMyRegistrations')
            )
        ) {
            return $deny;
        }
        return $this->doStore($request, $response, null);
    }

    public function show(Request $request, Response $response, int $id): Response
    {
        $event = new Event($this->zdb, $id);
        if ($event->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Event not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        if (!$event->canAccess($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to view this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        $event->loadSlots();
        $event->loadGroups();

        $sessions_repo = new SessionsRepository($this->zdb, $this->login);
        $sessions = $sessions_repo->getForEvent($id);

        $sessions_has_instructor = [];
        foreach ($sessions as $s) {
            $sessions_has_instructor[$s->getId()] = SessionInstructor::hasInstructor($this->zdb, $s->getId());
        }

        $this->view->render(
            $response,
            $this->getTemplate('pages/event_show'),
            [
                'page_title'              => $event->getName(),
                'event'                   => $event,
                'sessions'                => $sessions,
                'event_type'              => new EventType($this->zdb, $event->getTypeId()),
                'sessions_has_instructor' => $sessions_has_instructor,
            ]
        );
        return $response;
    }

    public function edit(Request $request, Response $response, int $id): Response
    {
        $event = new Event($this->zdb, $id);
        if ($event->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Event not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        if (!$event->canManage($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to edit this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        $event->loadSlots();
        $event->loadGroups();

        return $this->showForm($response, $event);
    }

    public function doEdit(Request $request, Response $response, int $id): Response
    {
        return $this->doStore($request, $response, $id);
    }

    private function showForm(Response $response, Event $event): Response
    {
        $this->view->render(
            $response,
            $this->getTemplate('pages/event_form'),
            [
                'page_title' => $event->getId() === null ? _T('New event', 'courses') : _T('Edit event', 'courses'),
                'event' => $event,
                'event_types' => EventType::getList($this->zdb),
                'groups' => Groups::getSimpleList(),
            ]
        );
        return $response;
    }

    private function doStore(Request $request, Response $response, ?int $id): Response
    {
        $post = $request->getParsedBody();

        if ($id !== null) {
            $event = new Event($this->zdb, $id);
            if ($event->getId() === null || !$event->canManage($this->login)) {
                $this->flash->addMessage('error_detected', _T('Event not found or access denied.', 'courses'));
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
            }
        } else {
            $event = new Event($this->zdb);
            $creatorId = (int)$this->login->id;
            $event->setCreatorId($creatorId > 0 ? $creatorId : null);
        }

        // Snapshot state BEFORE check() mutates the entity, so we can detect
        // edits to propagate onto existing future sessions (Phase 41).
        $oldSlots = [];
        $oldAllowNoInstructor = false;
        if ($id !== null) {
            $event->loadSlots();
            $oldSlots = $event->getSlots();
            $oldAllowNoInstructor = $event->isRegistrationAllowedWithoutInstructor();
        }

        $errors = $event->check($post);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->flash->addMessage('error_detected', $error);
            }
            // For add, redirect to add form; for edit, redirect to edit form
            if ($id !== null) {
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', $this->routeparser->urlFor('coursesEventEdit', ['id' => (string)$id]));
            }
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventAdd'));
        }

        if ($event->store()) {
            // Store slots
            $slots = [];
            if (isset($post['slots']) && is_array($post['slots'])) {
                foreach ($post['slots'] as $slot) {
                    if (!empty($slot['start_time']) && !empty($slot['end_time'])) {
                        $slots[] = [
                            'start_time' => $slot['start_time'],
                            'end_time' => $slot['end_time'],
                            'is_active' => !empty($slot['is_active']),
                        ];
                    }
                }
            }
            $event->storeSlots($slots);

            // Store groups if restricted
            if (isset($post['groups']) && is_array($post['groups'])) {
                $event->storeGroups(array_map('intval', $post['groups']));
            } else {
                $event->storeGroups([]);
            }

            // Sessions are materialized at validation time (see doValidate). While
            // the event is in DRAFT/PENDING, the form values (initial date, slots,
            // recurrence) are stored on the event itself but the sessions table is
            // left untouched — this is what keeps non-validated events out of the
            // staff/instructor session listings. Any session propagation, backfill,
            // session creation and member notification therefore only kicks in
            // once the event is VALIDATED (edit path), since at that point real
            // sessions exist and may need to be kept in sync with the form.
            if ($event->getStatus() === Event::STATUS_VALIDATED) {
                // Propagate edits onto existing future sessions (Phase 41).
                if ($id !== null) {
                    $this->propagateCapacityToSessions($event);
                    $this->propagateScheduleToSessions($event, $oldSlots, $slots);
                    if ($event->isRecurring() && !empty($post['session_date'])) {
                        $this->propagateDayOfWeekToSessions($event, (string)$post['session_date']);
                    }
                }

                // Collect sessions auto-created during this save so we can
                // notify group managers in a single batch below.
                $createdSessions = [];

                // Phase 69: backfill missing slot-sessions on existing future dates.
                // Idempotent — single-slot events with all slots present trigger no
                // inserts. Covers (1) edit of an existing event where the user
                // added a slot, and (2) migration of legacy multi-slot events.
                // Phase 78: only active slots are backfilled — re-activating a slot
                // therefore fills its missing future dates ; deactivating leaves
                // the already-generated sessions alone (no cascade by design).
                $activeSlots = array_values(array_filter(
                    $slots,
                    static fn(array $s): bool => !empty($s['is_active'])
                ));
                if ($id !== null && !empty($activeSlots)) {
                    $handler = new RecurrenceHandler($this->zdb);
                    $backfilled = $handler->backfillMissingSlots($event, $activeSlots);
                    if (!empty($backfilled)) {
                        $createdSessions = array_merge($createdSessions, $backfilled);
                    }
                }

                // Materialize sessions when the event reaches VALIDATED at
                // store time. Two code paths converge here:
                //   - Creation by staff/admin who picked status=VALIDATED in
                //     the form directly (no submit/validate roundtrip).
                //   - Edit of an already-validated recurring event where the
                //     user wants to seed new occurrences from a new start date
                //     (RecurrenceHandler is idempotent, no duplicates).
                // The one-shot edit case is skipped on purpose so the original
                // session is not duplicated. For the regular DRAFT -> PENDING
                // -> VALIDATED workflow, doValidate calls materializeSessions.
                if (!empty($post['session_date']) && ($id === null || $event->isRecurring())) {
                    $created = $this->materializeSessions($event, (string)$post['session_date']);
                    if (!empty($created)) {
                        $createdSessions = array_merge($createdSessions, $created);
                    }
                }

                $notification = null;
                if (!empty($createdSessions)) {
                    $notification = new CourseNotification(
                        $this->zdb,
                        $this->preferences,
                        new PluginPreferences($this->zdb),
                        new MemberPreferences($this->zdb),
                        $this->history
                    );
                    $notification->notifyNewSessions($event, $createdSessions);
                }

                // Phase 41: when the "allow registration without instructor" toggle
                // just flipped from off to on, the existing future sessions without
                // an instructor become registrable — notify eligible members now,
                // mirroring what notifyNewSessions does for newly-created sessions.
                // Sessions just created above are excluded: notifyNewSessions
                // already invokes notifySessionOpenWithoutInstructor for them.
                if (
                    $id !== null
                    && !$oldAllowNoInstructor
                    && $event->isRegistrationAllowedWithoutInstructor()
                    && !$event->isInstructorNotNeeded()
                ) {
                    $createdIds = array_map(static fn(Session $s) => $s->getId(), $createdSessions);
                    $futureNoInstr = array_filter(
                        $this->loadOpenFutureSessionsWithoutInstructor($event),
                        static fn(Session $s) => !in_array($s->getId(), $createdIds, true)
                    );
                    if (!empty($futureNoInstr)) {
                        $notification ??= new CourseNotification(
                            $this->zdb,
                            $this->preferences,
                            new PluginPreferences($this->zdb),
                            new MemberPreferences($this->zdb),
                            $this->history
                        );
                        foreach ($futureNoInstr as $s) {
                            $notification->notifySessionOpenWithoutInstructor($s, $event);
                        }
                    }
                }
            }

            $this->flash->addMessage('success_detected', _T('Event has been saved.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$event->getId()]));
        }

        $this->flash->addMessage('error_detected', _T('An error occurred saving the event.', 'courses'));
        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
    }

    /**
     * Phase 41: propagate the new capacity to every future non-cancelled
     * session of the event. The user explicitly accepted that lowering the
     * cap below current_registrations does NOT bump anyone — already-enrolled
     * members stay; the session just stops accepting new registrations until
     * natural cancellations bring it under the cap.
     */
    private function propagateCapacityToSessions(Event $event): void
    {
        try {
            $update = $this->zdb->update(Session::TABLE);
            $update->set(['max_capacity' => $event->getMaxCapacity()]);
            $update->where->equalTo('event_id', $event->getId());
            $update->where->notEqualTo('status', Session::STATUS_CANCELLED);
            $update->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));
            $this->zdb->execute($update);
        } catch (\Throwable $e) {
            Analog::log(
                'Error propagating capacity for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * Phase 41: propagate slot time changes to every future non-cancelled
     * session whose (start_time, end_time) matches one of the OLD slots.
     *
     * Slots are matched by index — position N in the form maps to position N
     * of the previously-stored list. Slots whose times are unchanged are
     * skipped. If the user added/removed slots, only the still-aligned indexes
     * are propagated; sessions tied to a removed slot keep their old time
     * (the staff can still edit individual sessions).
     *
     * @param array<array<string, mixed>> $oldSlots Slots loaded before check()
     * @param array<array<string, mixed>> $newSlots Slots posted by the form
     */
    private function propagateScheduleToSessions(Event $event, array $oldSlots, array $newSlots): void
    {
        if (empty($oldSlots)) {
            return;
        }

        try {
            foreach ($oldSlots as $i => $oldSlot) {
                if (!isset($newSlots[$i])) {
                    continue;
                }
                $newSlot = $newSlots[$i];
                if (
                    $oldSlot['start_time'] === $newSlot['start_time']
                    && $oldSlot['end_time'] === $newSlot['end_time']
                ) {
                    continue;
                }

                $update = $this->zdb->update(Session::TABLE);
                $update->set([
                    'start_time' => $newSlot['start_time'],
                    'end_time'   => $newSlot['end_time'],
                ]);
                $update->where->equalTo('event_id', $event->getId());
                $update->where->notEqualTo('status', Session::STATUS_CANCELLED);
                $update->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));
                $update->where->equalTo('start_time', $oldSlot['start_time']);
                $update->where->equalTo('end_time', $oldSlot['end_time']);
                $this->zdb->execute($update);
            }
        } catch (\Throwable $e) {
            Analog::log(
                'Error propagating schedule for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * Phase 50: propagate a day-of-week change to every future non-cancelled
     * session of a recurring event. The new weekday is read from the posted
     * session_date; the old weekday is read from the first future non-cancelled
     * session. The signed shortest-path delta (in [-3, +3]) is applied to every
     * future non-cancelled session via UPDATE session_date = old + delta days.
     *
     * Sessions whose shifted date would land in the past are skipped (rare:
     * happens only when shifting backward and the source session is today).
     */
    private function propagateDayOfWeekToSessions(Event $event, string $newSessionDate): void
    {
        $today = date('Y-m-d');
        $newWeekday = (int)date('w', strtotime($newSessionDate));

        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->columns([Session::PK, 'session_date']);
            $select->where(['event_id' => $event->getId()]);
            $select->where->notEqualTo('status', Session::STATUS_CANCELLED);
            $select->where->greaterThanOrEqualTo('session_date', $today);
            $select->order('session_date ASC');
            $rs = $this->zdb->execute($select);

            $rows = [];
            foreach ($rs as $r) {
                $rows[] = [
                    'id'   => (int)$r->{Session::PK},
                    'date' => (string)$r->session_date,
                ];
            }
            if (empty($rows)) {
                return;
            }

            $oldWeekday = (int)date('w', strtotime($rows[0]['date']));
            if ($oldWeekday === $newWeekday) {
                return;
            }

            $delta = $newWeekday - $oldWeekday;
            if ($delta > 3) {
                $delta -= 7;
            } elseif ($delta < -3) {
                $delta += 7;
            }

            foreach ($rows as $row) {
                $shifted = date(
                    'Y-m-d',
                    strtotime($row['date'] . ' ' . ($delta >= 0 ? '+' : '') . $delta . ' day')
                );
                if ($shifted < $today) {
                    continue;
                }
                $update = $this->zdb->update(Session::TABLE);
                $update->set(['session_date' => $shifted]);
                $update->where([Session::PK => $row['id']]);
                $this->zdb->execute($update);
            }
        } catch (\Throwable $e) {
            Analog::log(
                'Error propagating day-of-week for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * Dispatcher used at validation time (or at direct create-as-VALIDATED) to
     * spawn the sessions an event needs: a single per-slot session for one-shot
     * events, or the full recurrence series for recurring events. Idempotent
     * for the recurring case (RecurrenceHandler skips existing dates) — the
     * caller is responsible for skipping the one-shot edit case to avoid
     * duplicating the original session.
     *
     * @return Session[]
     */
    private function materializeSessions(Event $event, string $sessionDate): array
    {
        if ($event->isRecurring()) {
            $handler = new RecurrenceHandler($this->zdb);
            $created = $handler->generateSessions($event, $sessionDate);
            if (count($created) > 0) {
                $this->flash->addMessage(
                    'success_detected',
                    sprintf(_T('%d sessions have been generated.', 'courses'), count($created))
                );
            }
            return $created;
        }
        return $this->createSessionsForEvent($event, $sessionDate);
    }

    /**
     * Auto-create one session per active time slot of a non-recurring event
     * on the given date. Called at validation time (Option B: sessions are
     * deferred until the event is validated). Phase 78: deactivated slots are
     * skipped so that seasonal schedules don't spawn sessions on the off
     * side; falls back to a 09:00-10:00 default if no active slot is defined.
     *
     * @return Session[]
     */
    private function createSessionsForEvent(Event $event, string $sessionDate): array
    {
        $event->loadSlots();
        $slots = $event->getActiveSlots();
        if (empty($slots)) {
            $slots = [['start_time' => '09:00', 'end_time' => '10:00']];
        }

        $created = [];
        foreach ($slots as $slot) {
            $session = new Session($this->zdb);
            $session->setEventId($event->getId());
            $session->setSessionDate($sessionDate);
            $session->setStartTime($slot['start_time']);
            $session->setEndTime($slot['end_time']);
            $session->setMaxCapacity($event->getMaxCapacity());
            if ($session->store()) {
                $created[] = $session;
            }
        }
        return $created;
    }

    /**
     * Returns the OPEN sessions (date >= today) of an event that do NOT yet
     * have an instructor. Used at validation time to invite group managers
     * to volunteer for sessions created at draft phase.
     *
     * @return Session[]
     */
    private function loadOpenFutureSessionsWithoutInstructor(Event $event): array
    {
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->where(['event_id' => $event->getId()]);
            $select->where->equalTo('status', Session::STATUS_OPEN);
            $select->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));
            $select->order('session_date ASC');
            $rs = $this->zdb->execute($select);

            $sessions = [];
            foreach ($rs as $row) {
                $session = new Session($this->zdb, $row);
                if (!SessionInstructor::hasInstructor($this->zdb, $session->getId())) {
                    $sessions[] = $session;
                }
            }
            return $sessions;
        } catch (\Throwable $e) {
            Analog::log(
                'Error loading open future sessions for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    public function doSubmit(Request $request, Response $response, int $id): Response
    {
        $event = new Event($this->zdb, $id);
        if ($event->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Event not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        if (!$event->canSubmit($this->login)) {
            $this->flash->addMessage('error_detected', _T('You cannot submit this event for validation.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
        }

        if ($event->submit()) {
            $this->history->add(
                _T('[Courses] Event submitted for validation', 'courses'),
                sprintf('event #%d — %s', $event->getId(), $event->getName())
            );
            $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
            $notification->notifySubmission($event);
            $this->flash->addMessage('success_detected', _T('Event has been submitted for validation.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred submitting the event.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
    }

    public function doValidate(Request $request, Response $response, int $id): Response
    {
        $event = new Event($this->zdb, $id);
        if ($event->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Event not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        if (!$event->canValidate($this->login)) {
            $this->flash->addMessage('error_detected', _T('You cannot validate this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
        }

        if ($event->validate()) {
            $this->history->add(
                _T('[Courses] Event validated', 'courses'),
                sprintf('event #%d — %s', $event->getId(), $event->getName())
            );
            $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
            // notifyValidation : informe le createur (pas les moniteurs/membres).
            $notification->notifyValidation($event);

            // Option B (sessions deferred until validation): materialize the
            // sessions now using the date captured on the event at draft time.
            // For events pre-existing this change, initial_session_date may be
            // NULL — in that case we skip generation and rely on the historical
            // sessions that were already created in the draft workflow.
            $sessionDate = $event->getInitialSessionDate();
            if ($sessionDate !== null && $sessionDate !== '') {
                $this->materializeSessions($event, $sessionDate);
            }

            // Invite group managers to volunteer on every open future session
            // that does not yet have an instructor. Covers both the sessions
            // just created above AND any pre-existing legacy sessions that
            // survived from the old draft-time creation flow. notifyNewSessions
            // is internally deduplicated by (member, session), so this is safe
            // to call unconditionally.
            $sessionsWithoutInstructor = $this->loadOpenFutureSessionsWithoutInstructor($event);
            if (!empty($sessionsWithoutInstructor)) {
                $notification->notifyNewSessions($event, $sessionsWithoutInstructor);
            }

            $this->flash->addMessage('success_detected', _T('Event has been validated.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred validating the event.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
    }

    public function doReject(Request $request, Response $response, int $id): Response
    {
        $event = new Event($this->zdb, $id);
        if ($event->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Event not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        if (!$event->canReject($this->login)) {
            $this->flash->addMessage('error_detected', _T('You cannot reject this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
        }

        if ($event->reject()) {
            $this->history->add(
                _T('[Courses] Event rejected', 'courses'),
                sprintf('event #%d — %s', $event->getId(), $event->getName())
            );
            $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
            $notification->notifyRejection($event);
            $this->flash->addMessage('success_detected', _T('Event has been rejected and set back to draft.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred rejecting the event.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
    }

    public function doGenerateSessions(Request $request, Response $response, int $id): Response
    {
        $event = new Event($this->zdb, $id);
        if ($event->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Event not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEvents'));
        }

        if (!$event->canManage($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have permission to manage this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
        }

        if (!$event->isRecurring()) {
            $this->flash->addMessage('error_detected', _T('This event is not recurring.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
        }

        $handler = new RecurrenceHandler($this->zdb);
        $created = $handler->generateSessions($event);

        if (count($created) > 0) {
            $this->history->add(
                _T('[Courses] Sessions generated', 'courses'),
                sprintf('event #%d — %s — %d session(s)', $event->getId(), $event->getName(), count($created))
            );
            // Notify eligible members of new sessions
            if ($event->getStatus() === Event::STATUS_VALIDATED) {
                $notification = new CourseNotification($this->zdb, $this->preferences, new PluginPreferences($this->zdb), new MemberPreferences($this->zdb), $this->history);
                $notification->notifyNewSessions($event, $created);
            }

            $this->flash->addMessage(
                'success_detected',
                sprintf(_T('%d new sessions have been generated.', 'courses'), count($created))
            );
        } else {
            $this->flash->addMessage('warning_detected', _T('No new sessions to generate.', 'courses'));
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesEventShow', ['id' => (string)$id]));
    }

    public function confirmRemoveTitle(array $args): string
    {
        return _T('Remove event', 'courses');
    }

    public function redirectUri(array $args): string
    {
        return $this->routeparser->urlFor('coursesEvents');
    }

    public function formUri(array $args): string
    {
        return $this->routeparser->urlFor('coursesDoEventRemove');
    }

    protected function doDelete(array $args, array $post): bool
    {
        $event = new Event($this->zdb);
        $ids = $args['ids'] ?? (isset($args['id']) ? [(int)$args['id']] : []);
        return $event->remove($ids);
    }

    public function confirmDelete(Request $request, Response $response): Response
    {
        $args = $this->getArgs($request);
        $id = (int)($args['id'] ?? 0);

        $data = [
            'id' => $id,
            'redirect_uri' => $this->redirectUri($args),
        ];

        $this->view->render(
            $response,
            'modals/confirm_removal.html.twig',
            [
                'mode' => ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') ? 'ajax' : '',
                'page_title' => $this->confirmRemoveTitle($args),
                'form_url' => $this->formUri($args),
                'cancel_uri' => $this->redirectUri($args),
                'data' => $data,
            ]
        );
        return $response;
    }
}
