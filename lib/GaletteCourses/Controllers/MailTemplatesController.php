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
use GaletteCourses\Entity\MailTemplate;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class MailTemplatesController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Display the email templates editor.
     */
    public function show(Request $request, Response $response): Response
    {
        $refs = MailTemplate::getAvailableRefs();

        $templates = [];
        foreach ($refs as $ref) {
            $tpl = new MailTemplate($this->zdb);
            $tpl->load($ref);
            $templates[$ref] = $tpl;
        }

        $params = [
            'page_title'    => _T('Email templates', 'courses'),
            'templates'     => $templates,
            'refs'          => $refs,
            'require_tabs'  => true,
        ];

        return $this->view->render(
            $response,
            $this->getTemplate('pages/mail_templates'),
            $params
        );
    }

    /**
     * Save one template (identified by hidden tref field).
     */
    public function doSave(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $ref  = trim($post['tref'] ?? '');

        if (!in_array($ref, MailTemplate::getAvailableRefs(), true)) {
            $this->flash->addMessage('error_detected', _T('Unknown template.', 'courses'));
            return $response->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesMailTemplates') . '#tab-' . $ref);
        }

        $subject = trim($post['tsubject'] ?? '');
        $body    = trim($post['tbody'] ?? '');

        $tpl = new MailTemplate($this->zdb);
        $tpl->load($ref);
        $tpl->setSubject($subject);
        $tpl->setBody($body);

        if ($tpl->store()) {
            $this->flash->addMessage('success_detected', _T('Email template saved.', 'courses'));
        } else {
            $this->flash->addMessage('error_detected', _T('An error occurred saving the template.', 'courses'));
        }

        return $response->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesMailTemplates') . '#tab-' . $ref);
    }

    /**
     * Reset one template to default (delete custom version from DB).
     */
    public function doReset(Request $request, Response $response, array $args): Response
    {
        $ref = $args['ref'] ?? '';

        if (!in_array($ref, MailTemplate::getAvailableRefs(), true)) {
            $this->flash->addMessage('error_detected', _T('Unknown template.', 'courses'));
            return $response->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesMailTemplates'));
        }

        $tpl = new MailTemplate($this->zdb);
        if ($tpl->load($ref) && $tpl->isCustomized()) {
            $tpl->delete();
            $this->flash->addMessage('success_detected', _T('Template reset to default.', 'courses'));
        }

        return $response->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesMailTemplates') . '#tab-' . $ref);
    }
}
