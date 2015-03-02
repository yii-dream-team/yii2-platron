<?php
/**
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 */

namespace yiidreamteam\platron\actions;

use yii\base\Action;
use yii\base\InvalidConfigException;
use yiidreamteam\platron\Api;

class ResultAction extends Action
{
    public $componentName;
    public $redirectUrl;

    public $silent = false;

    /** @var Api */
    private $api;

    /**
     * @inheritdoc
     */
    public function init()
    {
        assert(isset($this->componentName));
        assert(isset($this->redirectUrl));

        $this->api = \Yii::$app->get($this->componentName);
        if (!$this->api instanceof Api)
            throw new InvalidConfigException('Invalid Platron component configuration');

        parent::init();
    }

    public function run()
    {
        try {
            $response = $this->api->processResult(\Yii::$app->request->post());
        } catch (\Exception $e) {
            throw $e;
        }

        Api::sendXlmResponse($response);
    }
}