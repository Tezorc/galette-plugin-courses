<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 Team ccag42 <contact@ccag42.org>
 *
 * This file is part of Galette (https://galette.eu).
 *
 * Galette is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Galette is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Galette. If not, see <http://www.gnu.org/licenses/>.
 */

namespace GaletteCourses\Controllers;

use Galette\Controllers\AbstractController;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\MemberPreferences;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * Handles the public (no-auth) one-click unsubscribe link included in notification emails.
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
