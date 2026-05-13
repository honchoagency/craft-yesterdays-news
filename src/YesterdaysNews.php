<?php

namespace honchoagency\yesterdaysnews;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use honchoagency\yesterdaysnews\models\Settings;

/**
 * Yesterday's News plugin
 *
 * @method static YesterdaysNews getInstance()
 * @method Settings getSettings()
 * @author honchoagency <dev@honcho.agency>
 * @copyright honchoagency
 * @license MIT
 */
class YesterdaysNews extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('yesterdays-news/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
    }
}
