<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:demo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $filePath = sprintf('%s/textdata.csv', 'csv-imports');

        if (! Storage::disk('s3')->exists($filePath)) {
            throw new \Exception('File not found');
        }

        $stream = Storage::disk('s3')->readStream($filePath);
        $batchData = [];
        $rowNumber = 0;

        while (($row = fgetcsv($stream, null, ',')) !== false) {
            if ($rowNumber == 0) {
                $rowNumber++;

                continue;
            }

            $batchData[] = $row;
            $rowNumber++;

            if (count($batchData) == 100) {
                dd($batchData[0]);
                $batchData = [];
            }
        }
        fclose($stream);
    }
}
