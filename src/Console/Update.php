<?php

namespace Torann\Currency\Console;

use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class Update extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'currency:update
                                {--o|openexchangerates : Get rates from OpenExchangeRates.org}
                                {--g|google : Get rates from Google Finance}
                                {--b|banxico : Get rates from Banxico}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update exchange rates from an online source';

    /**
     * Currency instance
     *
     * @var \Torann\Currency\Currency
     */
    protected $currency;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        $this->currency = app('currency');

        parent::__construct();
    }

    /**
     * Execute the console command for Laravel 5.4 and below
     *
     * @return void
     */
    public function fire()
    {
        $this->handle();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // Get Settings
        $defaultCurrency = $this->currency->config('default');

        if ($this->input->getOption('google')) {
            // Get rates from google
            return $this->updateFromGoogle($defaultCurrency);
        }

        if ($this->input->getOption('openexchangerates')) {
            if (!$api = $this->currency->config('api_key')) {
                $this->error('An API key is needed from OpenExchangeRates.org to continue.');

                return;
            }

            // Get rates from OpenExchangeRates
            return $this->updateFromOpenExchangeRates($defaultCurrency, $api);
        }

        if ($this->input->getOption('banxico')) {
            if ($defaultCurrency !== 'MXN') {
                $this->error('Banxico only works from MXN to USD');

                return;
            }
            // Get rates from Banxico
            return $this->updateFromBanxico($defaultCurrency);
        }
    }

    /**
     * Fetch rates from the API
     *
     * @param $defaultCurrency
     * @param $api
     */
    private function updateFromOpenExchangeRates($defaultCurrency, $api)
    {
        $this->info('Updating currency exchange rates from OpenExchangeRates.org...');

        // Make request
        $content = json_decode($this->request("http://openexchangerates.org/api/latest.json?base={$defaultCurrency}&app_id={$api}&show_alternative=1"));

        // Error getting content?
        if (isset($content->error)) {
            $this->error($content->description);

            return;
        }

        // Parse timestamp for DB
        $timestamp = (new DateTime())->setTimestamp($content->timestamp);

        // Update each rate
        foreach ($content->rates as $code => $value) {
            $this->currency->getDriver()->update($code, [
                'exchange_rate' => $value,
                'updated_at' => $timestamp,
            ]);
        }

        $this->currency->clearCache();

        $this->info('Update!');
    }

    /**
     * Fetch rates from Banxico (only from MXN to USD)
     *
     * @param $defaultCurrency
     */
    private function updateFromBanxico($defaultCurrency)
    {
        $this->info('Updating currency exchange rates from Banxico');

        foreach ($this->currency->getDriver()->all() as $code => $value) {
            // Don't update the default currency, the value is always 1
            if ($code !== 'USD') {
                continue;
            }

            $client = new \SoapClient(
                null,
                [
                    'location' => 'http://www.banxico.org.mx:80/DgieWSWeb/DgieWS?WSDL',
                    'uri'      => 'http://DgieWSWeb/DgieWS?WSDL',
                    'encoding' => 'ISO-8859-1',
                    'trace'    => 1
                ]
            );

            try {
                $result = $client->tiposDeCambioBanxico();
                if (!empty($result)) {
                    $dom = new \DomDocument();
                    $dom->loadXML($result);
                    $xmlDatos = $dom->getElementsByTagName("Obs");
                    if ($xmlDatos->length > 1) {
                        $item = $xmlDatos->item(1);
                        $fecha_tc = $item->getAttribute('TIME_PERIOD');
                        $rate = $item->getAttribute('OBS_VALUE');
                    }
                }
            } catch (\SoapFault $e) {
                \Log::error($e->getMessage());
            }

            if ($rate) {
                $this->currency->getDriver()->update($code, [
                    'exchange_rate' => $rate,
                ]);
            } else {
                $this->warn('Can\'t update rate for ' . $code);
                continue;
            }
        }
    }

    /**
     * Fetch rates from Google Finance
     *
     * @param $defaultCurrency
     */
    private function updateFromGoogle($defaultCurrency)
    {
        $this->info('Updating currency exchange rates from finance.google.com...');
        foreach ($this->currency->getDriver()->all() as $code => $value) {
            // Don't update the default currency, the value is always 1
            if ($code === $defaultCurrency) {
                continue;
            }

            $response = $this->request('http://finance.google.com/finance/converter?a=1&from=' . $defaultCurrency . '&to=' . $code);

            if (Str::contains($response, 'bld>')) {
                $data = explode('bld>', $response);
                $rate = explode($code, $data[1])[0];
                
                $this->currency->getDriver()->update($code, [
                    'exchange_rate' => $rate,
                ]);
            }
            else {
                $this->warn('Can\'t update rate for ' . $code);
                continue;
            }
        }
    }

    /**
     * Make the request to the sever.
     *
     * @param $url
     *
     * @return string
     */
    private function request($url)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_MAXCONNECTS, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}
