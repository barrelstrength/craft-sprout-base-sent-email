<?php

namespace barrelstrength\sproutbasesentemail\web\assets\email;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SentEmailAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = '@sproutbasesentemail/web/assets/sentemail/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/sent-email.css',
        ];

        parent::init();
    }
}