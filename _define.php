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

/** @var \Galette\Core\Plugins $this */
$this->register(
    name: 'Galette Courses',
    desc: 'Courses and events management',
    author: 'ccag42 Team',
    version: '0.1.0',
    compver: '1.2.0',
    route: 'courses',
    date: '2026-02-24',
    acls: [
        // Event authorship: routes are 'member' but each handler enforces
        // denyUnlessCanAuthorEvents() (admin/staff/group manager/instructor on
        // any session) — see Phase 46. Edit/Submit/DoEdit further gate via
        // Event::canManage()/canSubmit() which limit non-staff users to their
        // own events (creator_id === login.id).
        'coursesEvents'             => 'member',
        'coursesEventsFilter'       => 'member',
        'coursesEventAdd'           => 'member',
        'coursesDoEventAdd'         => 'member',
        'coursesEventShow'          => 'member',
        'coursesEventEdit'          => 'member',
        'coursesDoEventEdit'        => 'member',
        'coursesDoEventSubmit'      => 'member',
        'coursesDoEventValidate'    => 'staff',
        'coursesDoEventReject'      => 'staff',
        'coursesDoGenerateSessions' => 'staff',
        'coursesEventRemove'        => 'staff',
        'coursesDoEventRemove'      => 'staff',
        'coursesSessions'           => 'member',
        'coursesSessionsFilter'    => 'member',
        'coursesSessionShow'        => 'member',
        'coursesDoRegister'         => 'member',
        'coursesDoUnregister'       => 'member',
        'coursesDoWaitlist'         => 'member',
        'coursesDoLeaveWaitlist'    => 'member',
        'coursesMyRegistrations'    => 'member',
        'coursesMyRegistrationsIcal' => 'member',
        'coursesMyInstructorSessions' => 'member',
        'coursesSessionIcal'        => 'member',
        'coursesRegistrations'      => 'groupmanager',
        'coursesRegistrationsFilter' => 'groupmanager',
        // Session-scoped management actions: route ACL is 'member' but each
        // handler enforces denyUnlessSessionManager() (admin/staff/instructor
        // of this specific session) — see Phase 43.
        'coursesDoAssignInstructor'  => 'member',
        'coursesDoRemoveInstructor'  => 'member',
        'coursesDoVolunteerInstructor' => 'groupmanager',
        'coursesDoSessionClose'      => 'member',
        'coursesDoSessionReopen'     => 'member',
        'coursesDoSessionCancel'     => 'member',
        'coursesDoSessionReactivate' => 'member',
        'coursesDoMarkAttendance'    => 'groupmanager',
        'coursesDoWalkIn'            => 'groupmanager',
        'coursesProxyRegisterForm'   => 'member',
        'coursesDoProxyRegister'     => 'member',
        'coursesDoProxyUnregister'   => 'member',
        'coursesDoParentRegister'    => 'member',
        'coursesDoParentUnregister'  => 'member',
        'coursesSessionEdit'            => 'member',
        'coursesDoSessionEdit'          => 'member',
        'coursesDoSessionCapacity'      => 'member',
        'coursesDoPromoteWaitlist'      => 'member',
        'coursesDoSessionForWaitlist'   => 'member',
        'coursesSessionExportRegistrations' => 'groupmanager',
        'coursesMailSession'                => 'groupmanager',
        'coursesStats'                  => 'staff',
        'coursesPreferences'            => 'staff',
        'coursesDoPreferences'          => 'staff',
        'coursesDoRegenerateCronToken'  => 'admin',
        'coursesMailTemplates'          => 'admin',
        'coursesDoMailTemplates'        => 'admin',
        'coursesDoMailTemplateReset'    => 'admin',
        'coursesMemberPreferences'   => 'member',
        'coursesDoMemberPreferences' => 'member',
    ]
);
