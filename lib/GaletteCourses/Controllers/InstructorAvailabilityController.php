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
use GaletteCourses\Entity\SessionInstructor;
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
     * a member who manages no group).
     */
    public static function canDeclare(Db $zdb, Login $login): bool
    {
        $memberId = (int)$login->id;
        if ($memberId <= 0) {
            return false;
        }
        return $login->isGroupManager()
            || SessionInstructor::countSessionsForMember($zdb, $memberId) > 0;
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
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred.', 'courses'));
        }

        return $response->withStatus(302)->withHeader('Location', $returnUrl);
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
