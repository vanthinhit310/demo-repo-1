<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
     * @return void
     */
    public function handle()
    {
        $this->generateTruscoImgMapping();
    }

    public function generateTruscoImgMapping()
    {
        $images = Storage::allFiles('trusco_images');
        $total = count($images);
        $notFounds = [];
        $output = fopen(storage_path('trusco_img_mapping.csv'), 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['品目コード', '写真名']);

        foreach ($images as $index => $image) {
            $this->info('Processing ' . $image . ' (' . ($index + 1) . '/' . $total . ')');
            $originalFileName = basename($image);
            $fileName = strtoupper($originalFileName);
            $fileName = str_replace(['.jpg', '.png', '.jpeg', '.JPG', '.PNG', '.JPEG'], '', $fileName);
            $formattedFileName = str_replace([' ', '_'], '', $fileName);
            $products = DB::connection('catalog')
                ->table('oc_product')
                ->select('sku')
                ->whereRaw('REPLACE(sku, " ", "") = ?', [$formattedFileName])
                ->get();

            $this->info('Found ' . $products->count() . ' products for ' . $fileName . ' with formatted name ' . $formattedFileName);

            if ($products->count() > 0) {
                if ($products->count() > 1) {
                    info('Multiple products found for ' . $fileName, [
                        'fileName' => $fileName,
                        'products' => $products->pluck('sku')->toArray(),
                        'formattedFileName' => $formattedFileName,
                    ]);
                    $this->info('Multiple products found for ' . $fileName);
                } else {
                    $sku = $products->first() ? $products->first()->sku : null;
                    $this->info('Product found for ' . $fileName . ' with code ' . $sku);
                    fputcsv($output, [$products->first()->sku, $originalFileName]);
                    Storage::delete($image);
                    $this->info('Deleted image: ' . $image);
                }
            } else {
                $this->warn('No product found for ' . $originalFileName);
                $notFounds[] = $originalFileName;
            }

            $this->info('Done ' . $image);
            $this->info('--------------------------------');
        }

        if (!empty($notFounds)) {
            Storage::put('trusco_not_found_images.log', implode("\n", $notFounds));
        }

        fclose($output);
        $this->processNotFoundImage();
        $this->info('Done!');
    }

    public function processNotFoundImage()
    {
        $notFounds = Storage::get('trusco_not_found_images.log');
        $notFounds = explode("\n", $notFounds);
        $notFounds = array_filter($notFounds);
        $csvData = $this->readCsvData();
        $matches = [];

        foreach ($notFounds as $image) {
            $formattedImageCode = $this->formatImageCode($image);
            
            foreach ($csvData as $item) {
                $skuCode = $this->formatSkuCode($item);

                if ($formattedImageCode === $skuCode) {
                    $matches[] = [$item, $image];
                    break;
                }
            }
        }

        $this->newLine();

        if (count($matches) > 0) {
            $this->writeToCsv($matches);
            $this->info('Found ' . count($matches) . ' matches. Results saved to trusco_img_mapping.csv');
        } else {
            $this->info('No matches found');
        }
    }

    protected function readCsvData()
    {
        $filePath = Storage::path('textdata.csv');
        $data = [];

        if (($handle = fopen($filePath, "r")) !== false) {
            fgetcsv($handle, 1000, ",", '"');

            while (($row = fgetcsv($handle, 0, ",", '"')) !== false) {
                if (isset($row[1])) {
                    $data[] = $row[1];
                }
            }

            fclose($handle);
        }

        return $data;
    }

    protected function formatImageCode($imageCode)
    {
        $formatted = str_replace(['.jpg', '.png', '.jpeg', '.JPG', '.PNG', '.JPEG'], '', $imageCode);
        $formatted = str_replace('_', '', $formatted);
        $formatted = str_replace(' ', '', $formatted);

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
        $formatted = str_replace('_', '', $skuCode);
        $formatted = str_replace(' ', '', $formatted);

        return trim($formatted);
    }

    protected function writeToCsv($matches)
    {
        $output = fopen(storage_path('trusco_img_mapping.csv'), 'a');

        foreach ($matches as $match) {
            fputcsv($output, [$match[0], $match[1]]);
        }

        fclose($output);
    }
}
