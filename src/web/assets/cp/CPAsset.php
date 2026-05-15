<?php

namespace honchoagency\yesterdaysnews\web\assets\cp;

use Craft;
use craft\web\AssetBundle;

/**
 * Cp asset bundle
 */
class CPAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [];
    public $js = [];
    public $css = ['cp.css'];
}
