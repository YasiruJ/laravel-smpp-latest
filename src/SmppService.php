<?php

namespace LaravelSmpp;

use Exception;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use smpp\SMPP;
use smpp\Address;
use smpp\Client;
use smpp\exceptions\SmppException;
use smpp\transport\Socket;

/**
 * SMPP implementation of the SMS sending service.
 *
 * @package LaravelSmpp
 */
class SmppService implements SmppServiceInterface
{
    /**
     * Config repository.
     *
     * @var Repository
     */
    protected $config;

    /**
     * The SMPP Client implementation.
     *
     * @var Client
     */
    protected $smpp;

    /**
     * Providers configuration.
     *
     * @var array
     */
    protected $providers = [];

    /**
     * Current provider.
     *
     * @var string
     */
    protected $provider = 'default';

    /**
     * Catchable SMPP exceptions.
     *
     * @var array
     */
    protected $catchables = [];

    /**
     * SmppService constructor.
     *
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->config = $config;
        $this->providers = $config->get('laravel-smpp.providers', []);
        $this->catchables = $config->get('laravel-smpp.transport.catchables', []);

        Client::$csmsMethod = Client::CSMS_8BIT_UDH;
        Client::$systemType = $config->get('laravel-smpp.client.system_type', 'default');
        Client::$smsNullTerminateOctetStrings = $config->get('laravel-smpp.client.null_terminate_octetstrings', false);
        Socket::$forceIpv4 = $config->get('laravel-smpp.transport.force_ipv4', true);
        Socket::$defaultDebug = $config->get('laravel-smpp.transport.debug', false);
    }

    /**
     * Send a single SMS.
     *
     * @param $phone
     * @param $message
     *
     * @return string
     * @throws SmppException
     */
    public function sendOne($phone, $message)
    {
        $this->setupSmpp();

        $id = $this->sendSms($this->getSender(), $phone, $message);

        $this->smpp->close();

        return $id;
    }

    /**
     * Send multiple SMSes.
     *
     * @param array $phones
     * @param string|array $message
     *
     * @return void
     * @throws SmppException
     */
    public function sendBulk(array $phones, $message)
    {
        $this->setupSmpp();
        $sender = $this->getSender();

        foreach ($phones as $idx => $phone) {
            try {
                $message = (is_array($message) ? $message[$idx] : $message);

                $this->sendSms($sender, $phone, $message);
            } catch (Exception $ex) {
                $this->alertSendingError($ex, $phone);
            }
        }

        $this->smpp->close();
    }

    /**
     * Alert error occured while sending SMSes.
     *
     * @param Exception $ex
     * @param int $phone
     */
    protected function alertSendingError(Exception $ex, $phone)
    {
        Log::alert(sprintf('SMPP bulk sending error: %s. Exception: %s', $phone, $ex->getMessage()));
    }

    /**
     * Setup SMPP transport and client.
     *
     * @return Client
     * @throws SmppException
     */
    protected function setupSmpp()
    {
        // Trying all available providers
        foreach ($this->providers as $provider => $config) {
            $transport = new Socket([$config['host']], $config['port']);

            try {
               
                $transport->setRecvTimeout($config['timeout']);
                $smpp = new Client($transport);
                $smpp->debug = $this->config->get('laravel-smpp.client.debug', false);

                $transport->open();
                $smpp->bindTransmitter($config['login'], $config['password']);

                $this->smpp = $smpp;
                $this->provider = $provider;

                break;
            }
            // Skipping unavailable
            catch (SmppException $ex) {
                $transport->close();
                $this->smpp = null;

                if (in_array($ex->getCode(), $this->catchables)) {
                    continue;
                }

                throw $ex;
            }
        }
    }

    /**
     * Return sender as SmppAddress.
     *
     * @return Address
     */
    protected function getSender()
    {
        return $this->getSmppAddress();
    }

    /**
     * Return recipient as SmppAddress.
     *
     * @param $phone
     *
     * @return Address
     */
    protected function getRecipient($phone)
    {
        return $this->getSmppAddress($phone);
    }

    /**
     * Return an SmppAddress instance based on the given phone.
     *
     * @param int|null $phone
     *
     * @return Address
     */
    protected function getSmppAddress($phone = null)
    {
        if ($phone === null) {
            $phone = $this->getConfig('sender');
            $prefix = 'source';
        } else {
            $prefix = 'destination';
        }

        return new Address(
            $phone,
            hexdec($this->getConfig(sprintf('%s_ton', $prefix))),
            hexdec($this->getConfig(sprintf('%s_npi', $prefix)))
        );
    }

    /**
     * Send SMS via SMPP.
     *
     * @param Address $sender
     * @param int $recipient
     * @param string $message
     *
     * @return string
     */
    protected function sendSms(Address $sender, $recipient, $message)
    {
        $message = mb_convert_encoding($message, 'UCS-2', 'utf8');

        return $this->smpp->sendSMS($sender, $this->getRecipient($recipient), $message, null, SMPP::DATA_CODING_UCS2);
    }

    /**
     * Return SMPP config item for the current provider.
     *
     * @param string $option
     *
     * @return mixed
     */
    protected function getConfig($option)
    {
        $key = $this->provider . '.' . $option;
        $default = $this->config->get(sprintf('laravel-smpp.defaults.%s', $option));

        return Arr::get($this->providers, $key, $default);
    }
}
