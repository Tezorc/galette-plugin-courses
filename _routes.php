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

/**
 * @author Team CCAG <contact@ccag42.org>
 */

declare(strict_types=1);

use GaletteCourses\Controllers\EventsController;
use GaletteCourses\Controllers\SessionsController;
use GaletteCourses\Controllers\RegistrationsController;
use GaletteCourses\Controllers\ICalController;
use GaletteCourses\Controllers\StatsController;
use GaletteCourses\Controllers\MailTemplatesController;
use GaletteCourses\Controllers\UnsubscribeController;

// Events
$app->get(
    '/events[/{option}/{value}]',
    [EventsController::class, 'list']
)->setName('coursesEvents')->add($authenticate);

$app->post(
    '/events/filter',
    [EventsController::class, 'filter']
)->setName('coursesEventsFilter')->add($authenticate);

$app->get(
    '/event/add',
    [EventsController::class, 'add']
)->setName('coursesEventAdd')->add($authenticate);

$app->post(
    '/event/add',
    [EventsController::class, 'doAdd']
)->setName('coursesDoEventAdd')->add($authenticate);

$app->get(
    '/event/{id:[0-9]+}',
    [EventsController::class, 'show']
)->setName('coursesEventShow')->add($authenticate);

$app->get(
    '/event/{id:[0-9]+}/edit',
    [EventsController::class, 'edit']
)->setName('coursesEventEdit')->add($authenticate);

$app->post(
    '/event/{id:[0-9]+}/edit',
    [EventsController::class, 'doEdit']
)->setName('coursesDoEventEdit')->add($authenticate);

$app->post(
    '/event/{id:[0-9]+}/submit',
    [EventsController::class, 'doSubmit']
)->setName('coursesDoEventSubmit')->add($authenticate);

$app->post(
    '/event/{id:[0-9]+}/validate',
    [EventsController::class, 'doValidate']
)->setName('coursesDoEventValidate')->add($authenticate);

$app->post(
    '/event/{id:[0-9]+}/reject',
    [EventsController::class, 'doReject']
)->setName('coursesDoEventReject')->add($authenticate);

$app->post(
    '/event/{id:[0-9]+}/generate-sessions',
    [EventsController::class, 'doGenerateSessions']
)->setName('coursesDoGenerateSessions')->add($authenticate);

$app->get(
    '/event/{id:[0-9]+}/remove',
    [EventsController::class, 'confirmDelete']
)->setName('coursesEventRemove')->add($authenticate);

$app->post(
    '/event/remove',
    [EventsController::class, 'delete']
)->setName('coursesDoEventRemove')->add($authenticate);

// Sessions
$app->get(
    '/sessions[/{option}/{value}]',
    [SessionsController::class, 'list']
)->setName('coursesSessions')->add($authenticate);

$app->post(
    '/sessions/filter',
    [SessionsController::class, 'filter']
)->setName('coursesSessionsFilter')->add($authenticate);

$app->get(
    '/session/{id:[0-9]+}',
    [SessionsController::class, 'show']
)->setName('coursesSessionShow')->add($authenticate);

$app->get(
    '/session/{id:[0-9]+}/edit',
    [SessionsController::class, 'edit']
)->setName('coursesSessionEdit')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/edit',
    [SessionsController::class, 'doEdit']
)->setName('coursesDoSessionEdit')->add($authenticate);

// Session instructors
$app->post(
    '/session/{id:[0-9]+}/assign-instructor',
    [SessionsController::class, 'doAssignInstructor']
)->setName('coursesDoAssignInstructor')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/remove-instructor',
    [SessionsController::class, 'doRemoveInstructor']
)->setName('coursesDoRemoveInstructor')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/volunteer-instructor',
    [SessionsController::class, 'doVolunteerInstructor']
)->setName('coursesDoVolunteerInstructor')->add($authenticate);

// Session cancellation
$app->post(
    '/session/{id:[0-9]+}/cancel',
    [SessionsController::class, 'doCancel']
)->setName('coursesDoSessionCancel')->add($authenticate);

// Session close
$app->post(
    '/session/{id:[0-9]+}/close',
    [SessionsController::class, 'doClose']
)->setName('coursesDoSessionClose')->add($authenticate);

// Session reopen (closed → open)
$app->post(
    '/session/{id:[0-9]+}/reopen',
    [SessionsController::class, 'doReopen']
)->setName('coursesDoSessionReopen')->add($authenticate);

// Session reactivation
$app->post(
    '/session/{id:[0-9]+}/reactivate',
    [SessionsController::class, 'doReactivate']
)->setName('coursesDoSessionReactivate')->add($authenticate);

// Session capacity edit (with waitlist auto-promotion)
$app->post(
    '/session/{id:[0-9]+}/capacity',
    [SessionsController::class, 'doEditCapacity']
)->setName('coursesDoSessionCapacity')->add($authenticate);

// Session waitlist: promote next in line
$app->post(
    '/session/{id:[0-9]+}/promote-waitlist',
    [SessionsController::class, 'doPromoteWaitlist']
)->setName('coursesDoPromoteWaitlist')->add($authenticate);

// Session waitlist: create a new session for people on waitlist
$app->post(
    '/session/{id:[0-9]+}/session-for-waitlist',
    [SessionsController::class, 'doSessionForWaitlist']
)->setName('coursesDoSessionForWaitlist')->add($authenticate);

// Registrations
$app->post(
    '/session/{id:[0-9]+}/register',
    [RegistrationsController::class, 'doRegister']
)->setName('coursesDoRegister')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/unregister',
    [RegistrationsController::class, 'doUnregister']
)->setName('coursesDoUnregister')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/waitlist',
    [RegistrationsController::class, 'doWaitlist']
)->setName('coursesDoWaitlist')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/leave-waitlist',
    [RegistrationsController::class, 'doLeaveWaitlist']
)->setName('coursesDoLeaveWaitlist')->add($authenticate);

// Attendance marking
$app->post(
    '/session/{id:[0-9]+}/mark-attendance',
    [RegistrationsController::class, 'doMarkAttendance']
)->setName('coursesDoMarkAttendance')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/walk-in',
    [RegistrationsController::class, 'doWalkIn']
)->setName('coursesDoWalkIn')->add($authenticate);

// Proxy registration
$app->get(
    '/session/{id:[0-9]+}/proxy-register',
    [RegistrationsController::class, 'proxyRegisterForm']
)->setName('coursesProxyRegisterForm')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/proxy-register',
    [RegistrationsController::class, 'doProxyRegister']
)->setName('coursesDoProxyRegister')->add($authenticate);

// Parent registration (inscrire ses enfants)
$app->get(
    '/session/{id:[0-9]+}/parent-register',
    [RegistrationsController::class, 'parentRegisterForm']
)->setName('coursesParentRegisterForm')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/parent-register',
    [RegistrationsController::class, 'doParentRegister']
)->setName('coursesDoParentRegister')->add($authenticate);

$app->post(
    '/session/{id:[0-9]+}/parent-unregister',
    [RegistrationsController::class, 'doParentUnregister']
)->setName('coursesDoParentUnregister')->add($authenticate);

$app->get(
    '/my-registrations',
    [RegistrationsController::class, 'myRegistrations']
)->setName('coursesMyRegistrations')->add($authenticate);

$app->get(
    '/registrations[/{option}/{value}]',
    [RegistrationsController::class, 'list']
)->setName('coursesRegistrations')->add($authenticate);

$app->post(
    '/registrations/filter',
    [RegistrationsController::class, 'filter']
)->setName('coursesRegistrationsFilter')->add($authenticate);

// iCal exports
$app->get(
    '/session/{id:[0-9]+}/ical',
    [ICalController::class, 'sessionIcal']
)->setName('coursesSessionIcal')->add($authenticate);

// CSV export of registrations + waitlist
$app->get(
    '/session/{id:[0-9]+}/export-registrations',
    [SessionsController::class, 'exportRegistrations']
)->setName('coursesSessionExportRegistrations')->add($authenticate);

$app->get(
    '/session/{id:[0-9]+}/mail',
    [SessionsController::class, 'mailSession']
)->setName('coursesMailSession')->add($authenticate);

$app->get(
    '/my-registrations/ical',
    [ICalController::class, 'myRegistrationsIcal']
)->setName('coursesMyRegistrationsIcal')->add($authenticate);

// Statistics
$app->get(
    '/stats',
    [StatsController::class, 'show']
)->setName('coursesStats')->add($authenticate);

// Plugin Preferences (admin only)
$app->get(
    '/preferences',
    [GaletteCourses\Controllers\PreferencesController::class, 'show']
)->setName('coursesPreferences')->add($authenticate);

$app->post(
    '/preferences',
    [GaletteCourses\Controllers\PreferencesController::class, 'doSave']
)->setName('coursesDoPreferences')->add($authenticate);

$app->post(
    '/preferences/regenerate-cron-token',
    [GaletteCourses\Controllers\PreferencesController::class, 'doRegenerateCronToken']
)->setName('coursesDoRegenerateCronToken')->add($authenticate);

// Cron: auto-generate sessions (no auth, token-protected)
$app->get(
    '/cron/generate-sessions',
    [GaletteCourses\Controllers\CronController::class, 'generateSessions']
)->setName('coursesCronGenerateSessions');

// Email templates (admin)
$app->get(
    '/admin/mail-templates',
    [MailTemplatesController::class, 'show']
)->setName('coursesMailTemplates')->add($authenticate);

$app->post(
    '/admin/mail-templates',
    [MailTemplatesController::class, 'doSave']
)->setName('coursesDoMailTemplates')->add($authenticate);

$app->post(
    '/admin/mail-templates/{ref}/reset',
    [MailTemplatesController::class, 'doReset']
)->setName('coursesDoMailTemplateReset')->add($authenticate);

// Member notification preferences
$app->get(
    '/my-preferences',
    [GaletteCourses\Controllers\MemberPreferencesController::class, 'show']
)->setName('coursesMemberPreferences')->add($authenticate);

$app->post(
    '/my-preferences',
    [GaletteCourses\Controllers\MemberPreferencesController::class, 'doSave']
)->setName('coursesDoMemberPreferences')->add($authenticate);

// One-click unsubscribe (no auth required — token acts as credential)
$app->get(
    '/unsubscribe/{token:[a-f0-9]{48}}',
    [UnsubscribeController::class, 'unsubscribe']
)->setName('coursesUnsubscribe');
