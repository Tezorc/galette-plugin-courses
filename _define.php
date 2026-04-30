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
        'coursesEvents'             => 'groupmanager',
        'coursesEventsFilter'       => 'groupmanager',
        'coursesEventAdd'           => 'groupmanager',
        'coursesDoEventAdd'         => 'groupmanager',
        'coursesEventShow'          => 'member',
        'coursesEventEdit'          => 'groupmanager',
        'coursesDoEventEdit'        => 'groupmanager',
        'coursesDoEventSubmit'      => 'groupmanager',
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
        'coursesSessionIcal'        => 'member',
        'coursesRegistrations'      => 'groupmanager',
        'coursesRegistrationsFilter' => 'groupmanager',
        'coursesDoAssignInstructor'  => 'staff',
        'coursesDoRemoveInstructor'  => 'staff',
        'coursesDoVolunteerInstructor' => 'groupmanager',
        'coursesDoSessionClose'      => 'staff',
        'coursesDoSessionReopen'     => 'staff',
        'coursesDoSessionCancel'     => 'staff',
        'coursesDoSessionReactivate' => 'staff',
        'coursesDoMarkAttendance'    => 'groupmanager',
        'coursesDoWalkIn'            => 'groupmanager',
        'coursesProxyRegisterForm'   => 'groupmanager',
        'coursesDoProxyRegister'     => 'groupmanager',
        'coursesParentRegisterForm'  => 'member',
        'coursesDoParentRegister'    => 'member',
        'coursesDoParentUnregister'  => 'member',
        'coursesSessionEdit'            => 'staff',
        'coursesDoSessionEdit'          => 'staff',
        'coursesDoSessionCapacity'      => 'staff',
        'coursesDoPromoteWaitlist'      => 'staff',
        'coursesDoSessionForWaitlist'   => 'staff',
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
