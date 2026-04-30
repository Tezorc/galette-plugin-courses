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
    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function list(Request $request, Response $response, ?string $option = null, int|string|null $value = null): Response
    {
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
        return $this->showForm($response, new Event($this->zdb));
    }

    public function doAdd(Request $request, Response $response): Response
    {
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

            // Propagate max_capacity to future open sessions
            if ($id !== null) {
                $this->propagateCapacityToSessions($event);
            }

            // For non-recurring event: auto-create a single session
            if (!$event->isRecurring() && !empty($post['session_date'])) {
                $this->createSessionForEvent($event, $post);
            }

            // For recurring event: generate sessions from start date
            if ($event->isRecurring() && !empty($post['session_date'])) {
                $handler = new RecurrenceHandler($this->zdb);
                $created = $handler->generateSessions($event, $post['session_date']);
                if (count($created) > 0) {
                    $this->flash->addMessage(
                        'success_detected',
                        sprintf(_T('%d sessions have been generated.', 'courses'), count($created))
                    );
                }
            }

            // If new event created directly at VALIDATED status (staff bypass), notify group managers
            // (normal workflow: notifyPublication is called by doValidate, not here)
            if ($id === null && $event->getStatus() === Event::STATUS_VALIDATED) {
                $notification = new CourseNotification(
                    $this->zdb,
                    $this->preferences,
                    new PluginPreferences($this->zdb),
                    new MemberPreferences($this->zdb),
                    $this->history
                );
                $notification->notifyPublication($event);
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

    private function propagateCapacityToSessions(Event $event): void
    {
        $newCapacity = $event->getMaxCapacity();

        try {
            $update = $this->zdb->update(Session::TABLE);
            $update->set(['max_capacity' => $newCapacity]);
            $update->where->equalTo('event_id', $event->getId());
            $update->where->equalTo('status', Session::STATUS_OPEN);
            $update->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));

            // Never reduce capacity below current registrations for any session
            if ($newCapacity !== null) {
                $update->where->lessThanOrEqualTo('current_registrations', $newCapacity);
            }

            $this->zdb->execute($update);

            // Warn if some sessions were skipped (too many registrations)
            if ($newCapacity !== null) {
                $select = $this->zdb->select(Session::TABLE);
                $select->columns(['count' => new \Laminas\Db\Sql\Expression('COUNT(*)')])
                    ->where->equalTo('event_id', $event->getId())
                    ->equalTo('status', Session::STATUS_OPEN)
                    ->greaterThanOrEqualTo('session_date', date('Y-m-d'))
                    ->greaterThan('current_registrations', $newCapacity);
                $result = $this->zdb->execute($select)->current();
                $skipped = (int)($result->count ?? 0);
                if ($skipped > 0) {
                    $this->flash->addMessage(
                        'error_detected',
                        sprintf(
                            _T('%d session(s) were not updated: their current registrations exceed the new capacity.', 'courses'),
                            $skipped
                        )
                    );
                }
            }
        } catch (\Throwable $e) {
            Analog::log(
                'Error propagating capacity for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    private function createSessionForEvent(Event $event, array $post): void
    {
        $session = new Session($this->zdb);
        $session->setEventId($event->getId());
        $session->setSessionDate($post['session_date']);
        $session->setStartTime($post['slots'][0]['start_time'] ?? '09:00');
        $session->setEndTime($post['slots'][0]['end_time'] ?? '10:00');
        $session->setMaxCapacity($event->getMaxCapacity());
        $session->store();
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
            $notification->notifyValidation($event);
            $notification->notifyPublication($event);
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
