<?php


namespace App\Console\Commands;


use App\components\WebClient;
use Illuminate\Console\Command;

class UzMonitorCreate extends Command
{
    protected $webClient;

    public $signature = 'uz:monitor:create';

    public $description = 'Creates command for polling of uz.booking';

    public function __construct()
    {
        $this->webClient = new WebClient();

        parent::__construct();
    }

    public function handle()
    {
        $fromTerm = $this->ask('From');

        $fromOptions = $this->getCityOptions($fromTerm);
        if (empty($fromOptions)) {
            $this->output->writeln("No cities found");
            return;
        }

        $from = $this->choice('Select one of available cities', array_keys($fromOptions));
        $fromCode = $fromOptions[$from];

        $toTerm = $this->ask('To');
        $toOptions = $this->getCityOptions($toTerm);
        if (empty($toOptions)) {
            $this->output->writeln("No cities found");
            return;
        }
        $to = $this->choice('Select one of available cities', array_keys($toOptions));
        $toCode = $toOptions[$to];

        $date = $this->ask('Date');
        $passengersNames = $this->ask('Comma-separated full names of passengers:');
        $minTime = $this->ask('Departure time after (optional, default: 00:00)', 0);
        $trainNumbers = $this->ask('Concrete train number(s), separated by , (optional, any train by default)', '');
        $placesTypes = $this->ask('Comma-separated list of place types:');
        //$login = $this->ask('Your uz.booking login (optional):', '');
        //$password = $this->ask('Your uz.booking password (optional):', '');

        $runCommand = 'uz:monitor:run';
        $arguments = [
            'fromCode' => $fromCode,
            'toCode' => $toCode,
            'date' => $date,
            'passengersNames' => $passengersNames
        ];
        $options = array_filter([
            '--minTime' => $minTime,
            '--trainNumbers' => $trainNumbers,
            '--placesTypes' => $placesTypes,
            //'--login' => $login,
            //'--password' => $password,
        ]);

        $this->output->writeln($this->createInputFromArguments(array_merge(['command' => $runCommand], $arguments, $options)));

        //$this->call($runCommand, array_merge($arguments, $options));
    }

    protected function getCityOptions($term)
    {
        $response = $this->webClient->get('https://booking.uz.gov.ua/ru/train_search/station/?term='.urlencode($term));
        $citiesData = json_decode($response, true);

        return $citiesData ? array_column($citiesData, 'value', 'title') : [];
    }
}
