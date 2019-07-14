<?php


namespace App\Console\Commands;


use App\components\WebClient;
use Illuminate\Console\Command;

class UzMonitorRun extends Command
{
    protected $webClient;


    public $signature = 'uz:monitor:run {fromCode} {toCode} {date} {passengersNames} {--minTime=0} {--placesTypes=} {--trainNumbers=}';

    public $description = 'Polling of uz.booking';

    public function __construct()
    {
        $this->webClient = new WebClient();
        parent::__construct();
    }

    public function handle()
    {
        $fromCode = $this->argument('fromCode');
        $toCode = $this->argument('toCode');
        $date = $this->argument('date');
        $passengersNames = array_map('trim', explode(',', $this->argument('passengersNames')));

        $minTime = $this->option('minTime') ?: null;
        //$login = $this->option('login') ?: '';
        //$password = $this->option('password') ?: '';
        $placesTypes = $this->option('placesTypes')
            ? array_map('strtoupper', array_map('trim', explode(',', $this->option('placesTypes'))))
            : null;
        $trainNumbers = $this->option('trainNumbers')
            ? array_map('trim', explode(',', $this->option('trainNumbers')))
            : null;

        //$this->output->writeln('Login...');
        //$this->webClient->login($login, $password);

        $this->output->writeln('Start monitoring...');

        while(true) {

            $this->output->writeln(date('d.m H:i:s').' New request...');

            $data = [
                'from' => $fromCode,
                'to' => $toCode,
                'date' => $date,
                'time' => $minTime,
            ];

            $this->output->writeln('Get trains');
            $trainsResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_search/', $data);

            if (($trainsResponse = json_decode($trainsResponseJson, true)) && isset($trainsResponse['captcha'])) {
                file_put_contents('captcha.jpg', $this->webClient->get('https://booking.uz.gov.ua/ru/captcha/'));

                // mac version
                exec('open captcha.jpg');
                exec('say "Bypass the captcha, please"');

                // termux version
                exec('termux-open captcha.jpg');
                exec('termux-notification --content "Bypass the captcha please"');

                $captchaCode = $this->ask('Please enter symbols from image:');

                $trainsResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_search/', array_merge($data, [
                    'captcha' => $captchaCode
                ]));
            }

            //$this->output->writeln('Response:');
            //$this->output->writeln($trainsResponseJson);

            if (empty($trainsResponse = json_decode($trainsResponseJson, true)) || empty($trainsData = $trainsResponse['data'] ?? null)) {
                $this->notifyUnexpectedResponse($trainsResponseJson);

                return;
            }

            if (!empty($trainsList = $trainsData['list'] ?? null)) {
                $matchedTrains = $this->withFreePlaces(
                    $this->matchedDepartureTime(
                        $this->matchedPlacesTypes(
                            $this->matchedTrainNumbers(
                                $trainsList,
                                $trainNumbers
                            ),
                            $placesTypes
                        ),
                        $minTime
                    )
                );

                if (!empty($matchedTrains)) {
                    $train = reset($matchedTrains);

                    $this->output->writeln('Get wagons');

                    $wagonsResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_wagons/', array_merge($data, [
                        'train' => $train['num'],
                        'wagon_type_id' => $train['types'][0]['id'],
                        'get_tpl' => 1,
                    ]));

                    if (empty($wagonsResponse = json_decode($wagonsResponseJson, true)) || empty($wagonsData = $wagonsResponse['data'] ?? null)) {
                        $this->notifyUnexpectedResponse($wagonsResponseJson);

                        return;
                    }

                    if (!empty($wagonsList = $wagonsData['wagons'])) {
                        $wagon = reset($wagonsData['wagons']);

                        $this->output->writeln('Get places');

                        $wagonResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_wagon/', array_merge($data, [
                            'train' => $train['num'],
                            'wagon_num' =>  $wagon['num'],
                            'wagon_type' =>  $wagon['type'],
                            'wagon_class' =>  $wagon['class'],
                            //'cached_scheme[]' => К01,
                        ]));

                        if (empty($wagonResponse = json_decode($wagonResponseJson, true)) || empty($wagonData = $wagonResponse['data'] ?? null)) {
                            $this->notifyUnexpectedResponse($wagonResponseJson);

                            return;
                        }

                        $placesNumbers = [];
                        $placesData = reset($wagonData['places']);
                        foreach ($passengersNames as $i => $fullName) {
                            if (isset($placesData[$i])) {
                                $placesNumbers[$fullName] = $placesData[$i];
                            } else {
                                break;
                            }
                        }

                        $placesRequest = [];
                        $i = 0;
                        foreach ($placesNumbers as $fullName => $placeNumber) {
                            [$firstName, $lastName] = explode(' ', $fullName);
                            $placesRequest[] = [
                                'ord' => $i,
                                'from' => $fromCode,
                                'to' => $toCode,
                                'train' => $train['num'],
                                'date' => $date,
                                'wagon_num' => $wagon['num'],
                                'wagon_class' => $wagon['class'],
                                'wagon_type' => $wagon['type'],
                                'wagon_railway' => $wagon['railway'],
                                'charline' => 'A',
                                'firstname' => $firstName,
                                'lastname' => $lastName,
                                'bedding' => '1',
                                'services' => ['M'],
                                'child' => '', //
                                'student' => '', //
                                'reserve' => 0, // 0
                                'place_num' => $placeNumber
                            ];
                            $i++;
                        }

                        $this->output->writeln('Put places to cart');

                        $this->webClient->post('https://booking.uz.gov.ua/ru/cart/add/', [
                            'places' => $placesRequest
                        ]);

                        $this->output->writeln('Ticket put in cart: session id '.$this->webClient->sessionId);

                        for ($i = 0; $i < 5; $i++) {
                            // mac version
                            exec('say "Tickets in you cart!"');

                            // terux version (for android)
                            exec('termux-vibrate 2000');
                            exec('termux-notification --content "Tickets in you cart!"');

                            sleep(2);
                        }

                        return;
                    }
                }
            }

            sleep(rand(5, 10));

            if (!empty($trainsData['warning'])) {
                $this->output->writeln($trainsData['warning']);
            }
        }
    }

    protected function withFreePlaces(array $trains): array
    {
        return array_filter($trains, function(array $train) {
            return !empty($train['types']);
        });
    }

    protected function matchedDepartureTime(array $trains, ?string $minDepartureTime)
    {
        return is_null($minDepartureTime) ? $trains : array_filter($trains, function(array $train) use($minDepartureTime) {
            return (intval($train['from']['time']) >= intval($minDepartureTime));
        });
    }

    protected function matchedPlacesTypes(array $trains, ?array $allowedTypes): array
    {
        return is_null($allowedTypes) ? $trains : array_filter($trains, function(array $train) use($allowedTypes) {
            $matchedPlaces = array_filter($train['types'], function(array $type) use($allowedTypes) {
                return in_array($type['id'], $allowedTypes);
            });

            return !empty($matchedPlaces);
        });
    }

    protected function matchedTrainNumbers(array $trains, ?array $trainNumbers): array
    {
        return is_null($trainNumbers) ? $trains : array_filter($trains, function(array $train) use($trainNumbers) {
            return in_array($train['num'], $trainNumbers);
        });
    }

    protected function notifyUnexpectedResponse(string $response)
    {
        // mac version
        exec('say "Unexpected response!"');
        // termux version
        exec('termux-vibrate 5000');

        $this->output->writeln('Unexpected response!');
        $this->output->writeln($response);
    }
}