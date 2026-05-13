<?php

namespace honchoagency\yesterdaysnews\controllers;

use Craft;
use craft\web\Controller;
use honchoagency\yesterdaysnews\YesterdaysNews;
use yii\web\Response;

/**
 * CP controller — admin actions triggered from the Diagnostics utility UI.
 */
class CpController extends Controller
{
    /**
     * Flush buffered visits from the Craft cache into the DB.
     *
     * POST /groundcontrol/yesterdays-news/cp/flush
     */
    public function actionFlush(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        YesterdaysNews::getInstance()->visits->flushBufferToDb();

        Craft::$app->getSession()->setNotice("Yesterday's News: buffer flushed to DB.");

        return $this->redirectToPostedUrl();
    }

    /**
     * Prune stale URLs from the Blitz cache and visits table.
     *
     * POST /groundcontrol/yesterdays-news/cp/prune
     */
    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        YesterdaysNews::getInstance()->visits->pruneStaleUrls();

        Craft::$app->getSession()->setNotice("Yesterday's News: prune complete.");

        return $this->redirectToPostedUrl();
    }

    /**
     * Sync Blitz-cached URIs that have no YN visit record into the visits table.
     *
     * POST /groundcontrol/yesterdays-news/cp/sync
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $count = YesterdaysNews::getInstance()->visits->syncFromBlitz();

        Craft::$app->getSession()->setNotice("Yesterday's News: synced $count URI(s) from Blitz.");

        return $this->redirectToPostedUrl();
    }

    /**
     * Clear all visit data from the DB table and the cache buffer.
     *
     * POST /groundcontrol/yesterdays-news/cp/clear
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $count = YesterdaysNews::getInstance()->visits->clearAll();

        Craft::$app->getSession()->setNotice("Yesterday's News: cleared $count visit record(s).");

        return $this->redirectToPostedUrl();
    }
}
