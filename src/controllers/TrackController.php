<?php

namespace honchoagency\yesterdaysnews\controllers;

use Craft;
use craft\web\Controller;
use honchoagency\yesterdaysnews\YesterdaysNews;
use yii\web\Response;

/**
 * Track controller — anonymous POST endpoint called by the JS beacon.
 *
 * Route: POST /groundcontrol/yesterdays-news/track
 *
 * CSRF is validated normally. The beacon waits for Blitz's afterBlitzInjectAll
 * event (which fires after Blitz injects live CSRF tokens into the page) and
 * then reads the token from the DOM and includes it in the POST body.
 */
class TrackController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Allow unauthenticated access — this endpoint is called from the frontend.
     */
    protected array|int|bool $allowAnonymous = ['index'];

    /**
     * Disable CSRF validation — this is a read-only analytics endpoint with no
     * state-changing side effects, so CSRF protection is not required.
     * The beacon fires from cached Blitz pages that may not have a CSRF token
     * available in the DOM.
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'index') {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    /**
     * POST /groundcontrol/yesterdays-news/track
     *
     * Accepts JSON body: { "url": "/path/to/page", "CRAFT_CSRF_TOKEN": "..." }
     */
    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        $url = Craft::$app->getRequest()->getBodyParam('url');

        if (!is_string($url) || trim($url) === '') {
            return $this->asJson(['ok' => false, 'error' => 'Missing url param']);
        }

        YesterdaysNews::getInstance()->visits->bufferVisit($url);

        return $this->asJson(['ok' => true]);
    }
}
