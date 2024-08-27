<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LoadCsvRecordBatch
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $history;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($history)
    {
        $this->history = $history;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->batch()->cancelled()) {
            return;
        }

        $filePath = sprintf('%s/textdata.csv', $this->history->folder_path);

        if (! Storage::disk('s3')->exists($filePath)) {
            throw new \Exception('File not found');
        }

        $stream = Storage::disk('s3')->readStream($filePath);

        if ($stream === false) {
            throw new Exception("Could not open file: $filePath");
        }

        $batchData = [];
        $rowNumber = 0;

        while (($row = fgetcsv($stream, null, ',')) !== false) {
            if ($rowNumber == 0) {
                $rowNumber++;

                continue;
            }

            $batchData[] = $row;
            $rowNumber++;

            if (count($batchData) == 1000) {
                $this->batch()->add(new ImportProduct($batchData));
                $batchData = [];
            }
        }

        if (count($batchData) > 0) {
            $this->batch()->add(new ImportProduct($batchData));
        }

        fclose($stream);
        Log::info('Row number: ' . $rowNumber);
    }
}
