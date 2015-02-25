<?php

namespace yiidreamteam\platron;

use GuzzleHttp\Client;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

/**
 * Class Api
 * @author Valentine Konusov <rlng-krsk@yandex.ru>
 * @package yiidreamteam\platron
 */
class Api extends Component
{
    const URL_BASE = 'http://www.platron.ru';
    const URL_INIT_PAYMENT = 'init_payment.php';

    private $client = null;
    private $authParams = [];

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
     * @inheritdoc
     */
    public function init()
    {
        if ($this->accountId)
            throw new InvalidConfigException('accountId required.');

        if (!$this->secretKey)
            throw new InvalidConfigException('secretKey required.');

        if (!$this->accountPassword)
            throw new InvalidConfigException('accountPassword required.');
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
     * @throws \Exception
     */
    public function call($script, $params = [])
    {
        try {
            $response = $this->getClient()->post($script, ['body' => $this->prepareParams($params, $script)]);
            var_dump($response);
            exit;
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
     * @throws \Exception
     */
    public function redirectToPayment($invoiceId, $amount)
    {
        try {
            $url = $this->getPaymentUrl($invoiceId, $amount);
            \Yii::$app->response->redirect($url)->send();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $invoiceId
     * @param $amount
     * @return bool
     */
    private function getPaymentUrl($invoiceId, $amount)
    {
        $defaultParams = [
            'pg_merchant_id' => $this->accountId, //*
            'pg_description' => '', //*
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

        return false;
    }

    /**
     * Generate SIG
     * @param $params
     * @param $script
     * @return string
     */
    protected function generateSig($script, $params)
    {
        if ($script)
            throw new \BadMethodCallException('Unknown request url');

        ksort($params);
        array_unshift($params, $script);
        array_push($params, $this->secretKey);

        return md5(implode(';', $params));
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