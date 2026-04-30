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

use Slim\Psr7\Response;

/**
 * Reusable access-control guards for plugin controllers.
 *
 * Each guard returns null when access is granted, or a redirect Response
 * (302 + flash error) to short-circuit the controller action when denied.
 *
 * Typical usage:
 *   if ($deny = $this->denyUnlessStaffOrGroupManager($response, $redirectUrl)) {
 *       return $deny;
 *   }
 *
 * @author Team CCAG <contact@ccag42.org>
 */
trait CoursesAclGuard
{
    /**
     * Deny unless the logged-in user is admin, staff, or a group manager.
     */
    protected function denyUnlessStaffOrGroupManager(
        Response $response,
        string $redirectUrl,
        ?string $errorMessage = null
    ): ?Response {
        if ($this->login->isAdmin() || $this->login->isStaff() || $this->login->isGroupManager()) {
            return null;
        }
        $this->flash->addMessage(
            'error_detected',
            $errorMessage ?? _T('You do not have permission to perform this action.', 'courses')
        );
        return $response->withStatus(302)->withHeader('Location', $redirectUrl);
    }

    /**
     * Deny unless the logged-in user is admin or staff.
     */
    protected function denyUnlessAdminOrStaff(
        Response $response,
        string $redirectUrl,
        ?string $errorMessage = null
    ): ?Response {
        if ($this->login->isAdmin() || $this->login->isStaff()) {
            return null;
        }
        $this->flash->addMessage(
            'error_detected',
            $errorMessage ?? _T('You do not have permission to perform this action.', 'courses')
        );
        return $response->withStatus(302)->withHeader('Location', $redirectUrl);
    }
}
