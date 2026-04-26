<?php

declare(strict_types=1);

namespace GaletteCourses\Controllers;

use Galette\Controllers\AbstractController;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\PluginPreferences;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

class PreferencesController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function show(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);

        $params = [
            'page_title'            => _T('Courses plugin preferences', 'courses'),
            'notifications_enabled' => $pluginPrefs->isNotificationsEnabled(),
            'test_email'            => $pluginPrefs->getTestEmail(),
            'closure_dates'         => $pluginPrefs->getClosureDates(),
            'cron_token'            => $pluginPrefs->getCronToken(),
            'is_admin'              => $this->login->isAdmin() || $this->login->isSuperAdmin(),
        ];

        return $this->view->render(
            $response,
            $this->getTemplate('pages/preferences'),
            $params
        );
    }

    public function doSave(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $pluginPrefs = new PluginPreferences($this->zdb);

        // Notifications and cron settings: admin only
        if ($this->login->isAdmin() || $this->login->isSuperAdmin()) {
            $notifEnabled = isset($post['notifications_enabled']) ? '1' : '0';
            $pluginPrefs->set(PluginPreferences::NOTIFICATIONS_ENABLED, $notifEnabled);

            $testEmail = trim((string)($post['test_email'] ?? ''));
            $pluginPrefs->set(PluginPreferences::TEST_EMAIL, $testEmail);
        }

        // Parse closure date ranges
        $froms = $post['closure_from'] ?? [];
        $tos   = $post['closure_to']   ?? [];
        $closures = [];
        foreach ($froms as $i => $from) {
            $from = trim($from);
            $to   = trim($tos[$i] ?? '');
            if ($from !== '' && $to !== '' && $to >= $from) {
                $closures[] = ['from' => $from, 'to' => $to];
            }
        }
        $pluginPrefs->setClosureDates($closures);

        $this->flash->addMessage('success_detected', _T('Courses preferences saved.', 'courses'));

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesPreferences'));
    }

    public function doRegenerateCronToken(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);
        $pluginPrefs->set(PluginPreferences::CRON_TOKEN, bin2hex(random_bytes(24)));
        $this->flash->addMessage('success_detected', _T('Cron token regenerated.', 'courses'));

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesPreferences'));
    }
}
