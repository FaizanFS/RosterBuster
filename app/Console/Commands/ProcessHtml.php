<?php

namespace App\Console\Commands;

use DOMDocument;
use Illuminate\{
    Console\Command,
    Support\Facades\Storage,
    Contracts\Filesystem\FileNotFoundException,
};

class ProcessHtml extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:html {file : HTML file to be processed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process html file and return json response';

    /**
     * ProcessHtml constructor.
     */
    public function __construct()
    {
        parent::__construct();
        libxml_use_internal_errors(true);
    }

    /**
     * Execute the console command.
     *
     * @throws FileNotFoundException
     */
    public function handle()
    {
        $dom = new DOMDocument();
        $dom->loadHTML(Storage::disk('local')->get($this->argument('file')));

        $data = [];

        foreach ($dom->getElementsByTagName('table') as $key => $table) {
            $tr = $table->getElementsByTagName('tr');

            foreach ($tr as $node) {
                $data[$key][] = $this->processTableData($node->childNodes);
            }
        }

        $flightModel = collect([])
            ->union($this->getDates($data[0][1][1]))
            ->union($this->getPersonDetails($data[0][3][1]))
            ->union($this->getDays($data[0]));

        $this->getOutput()->writeln($flightModel->toJson(JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param $elements
     * @return array
     */
    private function processTableData($elements): array
    {
        //read td or th
        $td = [];
        foreach ($elements as $key => $element) {
            $td[$key] = trim($element->nodeValue);
        }

        return $td;
    }

    /**
     * @param string $string
     * @return array
     */
    private function getDates(string $string): array
    {
        preg_match_all('/\d{2}\/\d{2}\/\d{4}/', $string, $matches, PREG_PATTERN_ORDER);

        return [
            'from_date' => $matches[0][0] ?? '',
            'to_date' => $matches[0][1] ?? '',
        ];
    }

    /**
     * @param string $string
     * @return array
     */
    private function getPersonDetails(string $string): array
    {
        return [
            'person_name' => trim($this->getSubString($string, ':', 'ID')),
            'person_id' => trim(substr($string, strrpos($string, ':') + 1)),
        ];
    }

    /**
     * @param string $str
     * @param string $startingWord
     * @param string $endingWord
     * @return string
     */
    private function getSubString(string $str, string $startingWord, string $endingWord): string
    {
        $substringStart = strpos($str, $startingWord);

        //Get the ending index
        $substringStart += strlen($startingWord);

        //Length of our required sub string
        $size = strpos($str, $endingWord, $substringStart) - $substringStart;

        // Return the substring from the index substringStart of length size
        return substr($str, $substringStart, $size);
    }

    /**
     * @param array $daysData
     * @param int $fromIndex
     * @return array[]
     */
    private function getDays(array $daysData, int $fromIndex = 5): array
    {
        $data = array_slice($daysData, $fromIndex + 1, null, true);
        $days = $daysData[$fromIndex];
        $result = [];

        foreach ($days as $key => $day) {
            if (!empty($day)) {
                $result[$key]['day'] = $day;
            }
        }

        $skipIndex = false;
        $skipValues = ['D/O', 'ESBY', 'CSBE', 'ADTY', 'INTV', ' '];
        $totalFlights = 0;

        foreach ($data as $dkey => $row) {

            foreach ($row as $ckey => $column) {

                if ($column == 'CODE EXPLANATIONS') {
                    $skipIndex = $ckey;
                }

                if ($skipIndex !== false && $ckey >= $skipIndex) {
                    break;
                }

                if (!empty($result[$ckey])
                    && !in_array($column, $skipValues)
                    && false == preg_match("/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/", $column)
                    && intval($column)) {
                    $result[$ckey]['flight_events'][] = [
                        'flight_number' => $column,
                        'report_time' => (isset($data[$dkey + 1][$ckey]) ? $data[$dkey + 1][$ckey] : ''),
                        'departure_time' => (isset($data[$dkey + 2][$ckey]) ? $data[$dkey + 2][$ckey] : ''),
                        'departure_airport' => (isset($data[$dkey + 3][$ckey]) ? $data[$dkey + 3][$ckey] : ''),
                        'arrival_time' => (isset($data[$dkey + 5][$ckey]) ? $data[$dkey + 5][$ckey] : ''),
                        'arrival_airport' => (isset($data[$dkey + 4][$ckey]) ? $data[$dkey + 4][$ckey] : ''),
                    ];

                    $totalFlights++;
                }
            }
        }

        return ['days' => $result, 'total_flights' => $totalFlights];
    }
}
