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
use GaletteCourses\MemberPreferences;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * Handles the public (no-auth) one-click unsubscribe link included in notification emails.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class UnsubscribeController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * GET /plugins/courses/unsubscribe/{token}
     *
     * Validates the token and immediately disables notifications for the
     * matching member, then shows a confirmation page.
     * No authentication required.
     */
    public function unsubscribe(Request $request, Response $response, string $token = ''): Response
    {
        $memberPrefs = new MemberPreferences($this->zdb);

        $success = false;
        $alreadyOptedOut = false;

        if ($token !== '') {
            $memberId = $memberPrefs->findMemberIdByToken($token);
            if ($memberId !== null) {
                // Check if already opted out
                if (!$memberPrefs->isNotificationsEnabled($memberId)) {
                    $alreadyOptedOut = true;
                    $success = true;
                } else {
                    $success = $memberPrefs->unsubscribeByToken($token);
                }
            }
        }

        return $this->view->render(
            $response,
            $this->getTemplate('pages/unsubscribe'),
            [
                'page_title'       => _T('Unsubscribe from notifications', 'courses'),
                'success'          => $success,
                'already_opted_out' => $alreadyOptedOut,
                'invalid_token'    => ($token === '' || (!$success && !$alreadyOptedOut)),
            ]
        );
    }
}
