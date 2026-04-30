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
 * @author Team CCAG <contact@ccag42.org>
 */
class MemberPreferencesController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function show(Request $request, Response $response): Response
    {
        $memberId = (int)$this->login->id;
        $hasMemberRecord = $memberId > 0;
        $memberPrefs = new MemberPreferences($this->zdb);

        $params = [
            'page_title' => _T('My notification preferences', 'courses'),
            'notifications_enabled' => $hasMemberRecord && $memberPrefs->isNotificationsEnabled($memberId),
            'no_member_record' => !$hasMemberRecord,
        ];

        return $this->view->render(
            $response,
            $this->getTemplate('pages/member_preferences'),
            $params
        );
    }

    public function doSave(Request $request, Response $response): Response
    {
        $memberId = (int)$this->login->id;

        if ($memberId <= 0) {
            $this->flash->addMessage('warning_detected', _T('Preferences cannot be saved for the super-admin account.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesMemberPreferences'));
        }

        $post = $request->getParsedBody();
        $memberPrefs = new MemberPreferences($this->zdb);

        $enabled = isset($post['notifications_enabled']);
        $memberPrefs->setNotificationsEnabled($memberId, $enabled);

        $this->flash->addMessage('success_detected', _T('Your notification preferences have been saved.', 'courses'));

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesMemberPreferences'));
    }
}
