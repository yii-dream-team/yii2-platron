<?php

namespace yiidreamteam\platron;

use GuzzleHttp\Client;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\base\InvalidValueException;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\Response;
use yiidreamteam\platron\events\GatewayEvent;

/**
 * Class Api
 * @author Valentine Konusov <rlng-krsk@yandex.ru>
 * @package yiidreamteam\platron
 */
class Api extends Component
{
    const URL_BASE = 'http://www.platron.ru';
    const URL_INIT_PAYMENT = 'init_payment.php';

    const STATUS_OK = 'ok';
    const STATUS_ERROR = 'error';
    const STATUS_REJECTED = 'rejected';

    private $client = null;

    /** @var string Account ID */
    public $accountId;
    /** @var string Secret key */
    public $secretKey;
    /** @var bool Merchant test mode */
    public $testMode = true;
    /** @var string Default API request(merchant->platron) method */
    public $requestMethod = "POST";
    /** @var string Default response(platron->merchant) method */
    public $responseMethod = "AUTOPOST";
    /** @var string Possible values: RUR, USD, EUR */
    public $currency = 'RUR';
    /** @var string */
    public $invoiceClass;

    /** @var string */
    public $resultUrl;
    /** @var string */
    public $successUrl;
    /** @var string */
    public $failureUrl;
    /** @var string Url of merchant site page, where platron can check for possibility of invoice payment */
    public $checkUrl;
    /** @var string */
    public $refundUrl;
    /** @var string */
    public $captureUrl;
    /** @var string Url of merchant site page, where user waiting for payment system response */
    public $stateUrl;
    /** @var string Url of merchant site page, where platron redirect user after cash payment */
    public $siteReturnUrl;

    /**
     * @param array $data
     * @return bool
     * @throws HttpException
     * @throws \yii\db\Exception
     */
    public function processResult($data)
    {
        $url = $this->resultUrl ? Url::to($this->resultUrl) : \Yii::$app->request->getUrl();

        $response = [
            'pg_status' => static::STATUS_ERROR,
            'pg_salt' => ArrayHelper::getValue($data, 'pg_salt'),
            'pg_description' => 'Оплата не принята',
        ];

        if (!$this->checkHash($url, $data))
            throw new ForbiddenHttpException('Hash error');

        $event = new GatewayEvent(['gatewayData' => $data]);

        $this->trigger(GatewayEvent::EVENT_PAYMENT_REQUEST, $event);
        if ($event->handled ) {
            $transaction = \Yii::$app->getDb()->beginTransaction();
            try {
                $this->trigger(GatewayEvent::EVENT_PAYMENT_SUCCESS, $event);
                $response = [
                    'pg_status' => static::STATUS_OK,
                    'pg_description' => 'Оплата принята'
                ];
                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollback();
                \Yii::error('Payment processing error: ' . $e->getMessage(), 'Platron');
                throw new HttpException(503, 'Error processing request');
            }
        }

        return $this->prepareParams($url, $response);
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->accountId)
            throw new InvalidConfigException('accountId required.');

        if (!$this->secretKey)
            throw new InvalidConfigException('secretKey required.');
    }

    /**
     * @return Client|null
     */
    public function getClient()
    {
        if (empty($this->client))
            $this->client = new Client([
                'base_url' => static::URL_BASE
            ]);

        return $this->client;
    }

    /**
     * @param $script
     * @param array $params
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    private function call($script, $params = [])
    {
        try {
            $response = $this->getClient()->post($script, ['body' => $this->prepareParams($script, $params)]);

            if ($response->getStatusCode() != 200)
                throw new HttpException(503, 'Api http error: ' . $response->getStatusCode(), $response->getStatusCode());

            $xml = $response->xml();

            // Handle request errors
            if ((string)ArrayHelper::getValue($xml, 'pg_status') != static::STATUS_OK) {
                $errorCode = (int)ArrayHelper::getValue($xml, 'pg_error_code');
                $errorDescription = (string)ArrayHelper::getValue($xml, 'pg_error_description');
                throw new \Exception(static::getErrorCodeLabel($errorCode) . " : " . $errorDescription);
            }

            return $xml;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $script
     * @param $params
     * @return array
     */
    private function prepareParams($script, $params)
    {
        $params = array_filter($params);
        $params['pg_sig'] = $this->generateSig($script, $params);

        return $params;
    }

    /**
     * @param $invoiceId
     * @param $amount
     * @param $description
     * @throws \Exception
     */
    public function redirectToPayment($invoiceId, $amount, $description)
    {
        try {
            $url = $this->getPaymentUrl($invoiceId, $amount, $description);
            \Yii::$app->response->redirect($url)->send();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $invoiceId
     * @param $amount
     * @param $description
     * @return bool
     */
    private function getPaymentUrl($invoiceId, $amount, $description)
    {
        $defaultParams = [
            'pg_merchant_id' => $this->accountId, //*
            'pg_description' => $description, //*
            'pg_amount' => number_format($amount, 2, '.', ''), //*
            'pg_salt' => \Yii::$app->getSecurity()->generateRandomString(), // *
            'pg_order_id' => $invoiceId,
            'pg_currency' => $this->currency,
            'pg_check_url' => $this->checkUrl ? Url::to($this->checkUrl, true) : null,
            'pg_result_url' => $this->resultUrl ? Url::to($this->resultUrl, true) : null,
            'pg_refund_url' => $this->refundUrl ? Url::to($this->refundUrl, true) : null,
            'pg_success_url' => $this->successUrl ? Url::to($this->successUrl, true) : null,
            'pg_failure_url' => $this->failureUrl ? Url::to($this->failureUrl, true) : null,
            'pg_site_url' => $this->siteReturnUrl ? Url::to($this->siteReturnUrl, true) : null,
            'pg_request_method' => $this->requestMethod ?: null,
            'pg_success_url_method' => $this->responseMethod ?: null,
            'pg_failure_url_method' => $this->responseMethod ?: null,
            'pg_state_url' => $this->stateUrl ? Url::to($this->stateUrl, true) : null,
            'pg_state_url_method' => $this->responseMethod ?: null,
//            'pg_payment_system' => '',
//            'pg_lifetime' => '',
//            'pg_encoding' => '',
//            'pg_user_phone' => '',
//            'pg_user_contact_email' => '',
//            'pg_user_email' => '',
//            'pg_user_ip' => '',
//            'pg_postpone_payment' => '',
//            'pg_language' => '',
//            'pg_recurring_start' => '',
//            'pg_recurring_lifetime' => '',
            'pg_testing_mode' => $this->testMode,
        ];

        $response = $this->call(static::URL_INIT_PAYMENT, $defaultParams);

        return (string)ArrayHelper::getValue($response, 'pg_redirect_url');
    }

    /**
     * Generate SIG
     * @param $params
     * @param $script
     * @return string
     */
    protected function generateSig($script, $params)
    {
        if(empty($script))
            throw new \LogicException('Script name cannot be empty');

        ksort($params);
        array_unshift($params, basename($script));
        array_push($params, $this->secretKey);

        return md5(implode(';', $params));
    }

    /**
     * @param $data
     * @param $scriptName
     * @return bool
     */
    protected function checkHash($scriptName, $data)
    {
        $sig = (string)ArrayHelper::remove($data, 'pg_sig');
        return $sig === $this->generateSig($scriptName, $data);
    }

    /**
     * @param $data
     */
    public static function sendXlmResponse($data)
    {
        \Yii::$app->response->format = Response::FORMAT_XML;
        \Yii::$app->response->data = $data;
        \Yii::$app->response->send();
    }

    /**
     * @param $code
     * @return mixed
     */
    protected function getErrorCodeLabel($code)
    {
        $labels = [
            '100' => 'Некорректная подпись запроса *',
            '101' => 'Неверный номер магазина',
            '110' => 'Отсутствует или не действует контракт с магазином',
            '120' => 'Запрошенное действие отключено в настройках магазина',
            '200' => 'Не хватает или некорректный параметр запроса',
            '340' => 'Транзакция не найдена',
            '350' => 'Транзакция заблокирована',
            '360' => 'Транзакция просрочена',
            '400' => 'Платеж отменен покупателем или платежной системой',
            '420' => 'Платеж отменен по причине превышения лимита',
            '490' => 'Отмена платежа невозможна',
            '600' => 'Общая ошибка',
            '700' => 'Ошибка в данных введенных покупателем',
            '701' => 'Некорректный номер телефона',
            '711' => 'Номер телефона неприемлем для выбранной ПС',
            '1000' => 'Внутренняя ошибка сервиса (может не повториться при повторном обращении)',
        ];

        return ArrayHelper::getValue($labels, $code, null);
    }

    /**
     * @param $code
     * @return mixed
     */
    protected function getRejectCodeLabel($code)
    {
        $labels = [
            '1' => 'Неизвестная причина отказа',
            '2' => 'Общая ошибка',
            '3' => 'Ошибка на стороне платежной системы',
            '4' => 'Не удалось выставить счет ни в одну из платежных систем',
            '5' => 'Неправильный запрос в платежную систему',
            '40' => 'Превышение лимитов',
            '50' => 'Платеж отменен',
            '100' => 'Ошибка в данных покупателя',
            '101' => 'Некорректный номер телефона',
            '300' => 'Некорректная транзакция',
            '301' => 'Неверный номер карты',
            '302' => 'Неверное имя держателя карты',
            '303' => 'Неверное значение CVV2/CVC2',
            '304' => 'Неверный срок действия карты',
            '305' => 'Данный вид карты не поддерживается банком',
            '306' => 'Некорректная сумма',
            '310' => 'Карта клиента просрочена',
            '320' => 'Ожидаемый fraud',
            '321' => 'Не пройдена аутентификация по 3ds51',
            '329' => 'Карта была украдена',
            '330' => 'Неизвестный банк эквайер',
            '350' => 'Превышение количества использований карты клиента за определенный промежуток времени',
            '351' => 'Превышение лимита по сумме',
            '352' => 'На счете клиента не хватает средств',
            '353' => 'Транзакция не разрешена для владельца карты',
            '354' => 'Транзакция не разрешена для банка эквайера',
            '389' => 'Общая техническая ошибка системы',
            '390' => 'Ограничения по карте',
            '391' => 'Карта заблокирована',
            '400' => 'Транзакция заблокирована по решению fraud-фильтров',
            '410' => 'Клиент не подтвердил свой номер телефона',
        ];

        return ArrayHelper::getValue($labels, $code, null);
    }

}