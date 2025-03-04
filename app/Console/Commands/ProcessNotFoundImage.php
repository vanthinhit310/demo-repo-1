<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessNotFoundImage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:not-found-image';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process not found image';

    /**
     * Path to the log file containing not found images
     *
     * @var string
     */
    protected $logFilePath = 'logs/trusco/not_found_images.log';

    /**
     * Path to the CSV data file
     *
     * @var string
     */
    protected $csvFilePath = 'textdata.csv';

    /**
     * Path to the output CSV file
     *
     * @var string
     */
    protected $outputFilePath = 'matched_images.csv';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting to process not found images...');

        // Read not found images from log file
        if (!Storage::exists($this->logFilePath)) {
            $this->error("Log file not found: {$this->logFilePath}");
            return Command::FAILURE;
        }

        $notFoundImages = Storage::get($this->logFilePath);
        $notFoundImages = explode("\n", $notFoundImages);
        $notFoundImages = array_filter($notFoundImages);

        $this->info('Found ' . count($notFoundImages) . ' not found images');

        // Read CSV data
        if (!Storage::exists($this->csvFilePath)) {
            $this->error("CSV file not found: {$this->csvFilePath}");
            return Command::FAILURE;
        }

        $csvData = $this->readCsvData();
        $this->info('CSV data loaded successfully');

        // Process images and find matches
        $matches = [];
        $bar = $this->output->createProgressBar(count($notFoundImages));

        foreach ($notFoundImages as $image) {
            $image = trim($image);
            $formattedImageCode = $this->formatImageCode($image);

            foreach ($csvData as $item) {
                $skuCode = $this->formatSkuCode($item);

                if ($formattedImageCode === $skuCode) {
                    $matches[] = [$item, $image];
                    break;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Write matches to output CSV
        if (count($matches) > 0) {
            $this->writeToCsv($matches);
            $this->info('Found ' . count($matches) . ' matches. Results saved to ' . $this->outputFilePath);
        } else {
            $this->info('No matches found');
        }

        return Command::SUCCESS;
    }

    /**
     * Format image code by removing file extension, spaces and underscores
     *
     * @param string $imageCode The image code to format
     * @return string Formatted image code
     */
    protected function formatImageCode($imageCode)
    {
        // Remove file extension, spaces and underscores
        $formatted = str_replace(['.jpg', '.png', '.jpeg', '.JPG', '.PNG', '.JPEG'], '', $imageCode); // Remove extension
        $formatted = str_replace('_', '', $formatted); // Remove underscores
        $formatted = str_replace(' ', '', $formatted); // Remove spaces

        return trim($formatted);
    }

    /**
     * Format SKU code from CSV for comparison
     *
     * @param string $skuCode The SKU code to format
     * @return string Formatted SKU code
     */
    protected function formatSkuCode($skuCode)
    {
        $formatted = str_replace('_', '', $skuCode); // Remove underscores
        $formatted = str_replace(' ', '', $formatted); // Remove spaces

        return trim($formatted);
    }

    /**
     * Read and parse CSV data file, extracting only the second column
     *
     * @return array Array of SKU codes from the second column
     */
    protected function readCsvData()
    {
        $filePath = Storage::path($this->csvFilePath);
        $data = [];

        if (($handle = fopen($filePath, "r")) !== false) {
            fgetcsv($handle, 0, ",", '"');

            while (($row = fgetcsv($handle, 1000, ",", '"')) !== false) {
                if (isset($row[1])) {
                    $data[] = $row[1];
                }
            }

            fclose($handle);
        }

        return $data;
    }

    /**
     * Write matched data to output CSV file
     *
     * @param array $matches Array of matched data
     * @return void
     */
    protected function writeToCsv($matches)
    {
        $output = fopen(storage_path('trusco_img_mapping_new.csv'), 'a');

        foreach ($matches as $match) {
            fputcsv($output, [$match[0], $match[1]]);
        }

        fclose($output);
    }
}
