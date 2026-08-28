<?php

namespace honchoagency\yesterdaysnews;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Utilities;
use craft\web\View;
use honchoagency\yesterdaysnews\models\Settings;
use honchoagency\yesterdaysnews\services\VisitService;
use honchoagency\yesterdaysnews\utilities\Diagnostics;
use yii\base\Event;

/**
 * Yesterday's News plugin
 *
 * Tracks page views via a JS beacon and prunes stale entries from the Blitz
 * static cache for URLs that have not been visited within a configured threshold.
 *
 * @method static YesterdaysNews getInstance()
 * @method Settings getSettings()
 * @property-read VisitService $visits
 */
class YesterdaysNews extends Plugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    public static function blitzIsInstalled(): bool
    {
        return Craft::$app->getPlugins()->getPlugin('blitz') !== null;
    }

    public static function config(): array
    {
        return [
            'components' => [
                'visits' => VisitService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Point to the correct controller namespace for console vs. web requests.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'honchoagency\\yesterdaysnews\\console\\controllers';
        } else {
            $this->controllerNamespace = 'honchoagency\\yesterdaysnews\\controllers';

            if (Craft::$app->getRequest()->getIsCpRequest()) {
                // Register the plugin's templates directory as a CP template root
                // so Diagnostics::contentHtml() can resolve 'yesterdays-news/_diagnostics'.
                Event::on(
                    View::class,
                    View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
                    function (RegisterTemplateRootsEvent $event): void {
                        $event->roots['yesterdays-news'] = __DIR__ . '/templates';
                    }
                );

                // Register the Diagnostics utility so it appears in the CP.
                Event::on(
                    Utilities::class,
                    Utilities::EVENT_REGISTER_UTILITIES,
                    function (RegisterComponentTypesEvent $event): void {
                        $event->types[] = Diagnostics::class;
                    }
                );
            } else {
                // Only inject the JS beacon on frontend (non-CP) web requests.
                $this->attachEventHandlers();
            }
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('yesterdays-news/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'config' => Craft::$app->getConfig()->getConfigFromFile('yesterdays-news'),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register the plugin's templates directory as a site template root.
        // This allows renderTemplate('yesterdays-news/_beacon', ...) to resolve
        // to src/templates/_beacon.html.twig.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots['yesterdays-news'] = __DIR__ . '/templates';
            }
        );

        // Inject the JS beacon just before </body> on all frontend pages.
        // The resulting <script> tag is baked into the static HTML that Blitz
        // caches, so it persists in every subsequent cached page response.
        // Only inject on 200 responses — skip 404, 410, etc. so those URLs
        // are never tracked as visited.
        Event::on(
            View::class,
            View::EVENT_END_BODY,
            function (Event $event): void {

                if (!$this->shouldInjectBeacon()) {
                    return;
                }

                echo Craft::$app->getView()->renderTemplate(
                    'yesterdays-news/_beacon',
                    ['actionTrigger' => Craft::$app->getConfig()->getGeneral()->actionTrigger],
                    View::TEMPLATE_MODE_SITE,
                );
            }
        );
    }

    private function shouldInjectBeacon(): bool
    {
        $request = Craft::$app->getRequest();
        $settings = $this->getSettings();
        $url = $request->getUrl();

        // Don't inject the beacon if it's not enabled in settings.
        if (!$settings->beaconEnabled) {
            return false;
        }

        // Only inject the beacon on site requests — skip CP and console requests.
        if (!$request->getIsSiteRequest()) {
            return false;
        }

        // Don't inject the beacon on preview requests — those URLs are never cached
        // by Blitz, so recording them would generate spurious visit records.
        if ($request->getIsPreview()) {
            return false;
        }

        // Only inject into HTML responses. Check the raw Accept header for an
        // explicit text/html entry — don't honour */* wildcards, which appear
        // in CSS/font/asset requests as a low-priority fallback and would
        // otherwise cause the beacon to fire on non-HTML routes.
        if (!str_contains($request->getHeaders()->get('Accept', ''), 'text/html')) {
            return false;
        }

        // Only inject the beacon on successful 200 responses, to avoid
        // tracking errors or redirects.
        if (Craft::$app->getResponse()->statusCode !== 200) {
            return false;
        }

        return true;
    }
}
