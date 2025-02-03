<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class TestNew extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:demo-new';

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
        // $this->checkProductCategories();
    }

    private function checkProductCategories()
    {
        $handle = fopen(storage_path('category_trusco_01.csv'), 'r');
        $lineNumber = 0;
        $categories = [];
        $notFoundCategories = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($lineNumber == 0) {
                $lineNumber++;
                continue;
            }

            $categoryId = '';

            if (isset($line[0]) && !empty($line[0])) {
                $categoryId .= $line[0];
            }

            if (isset($line[1]) && !empty($line[1])) {
                $categoryId .= "_" . $line[1];
            }

            if (isset($line[2]) && !empty($line[2])) {
                $categoryId .= "_" . $line[2];
            }

            if ($categoryId === "___") {
                $categoryId = "";
            }

            $categoryId = trim($categoryId);

            if (! empty($categoryId) && ! in_array($categoryId, $categories)) {
                $categories[] = $categoryId;
            }
        }

        foreach ($categories as $category) {
            $this->info('Processing ' . $category);

            $categoryExists = DB::connection('catalog')
                ->table('category')
                ->where('category_id', $category)
                ->exists();

            if (! $categoryExists) {
                $this->error('Category ' . $category . ' does not exist');
                $notFoundCategories[] = $category;
            } else {
                $this->info('Category ' . $category . ' exists');
            }
        }

        dd($notFoundCategories);
    }
    /**
     * Generate mapping between Trusco images and products
     * Process large number of images efficiently using batch processing
     *
     * @return void
     */
    public function generateTruscoImgMapping()
    {
        $output = null;
        try {
            // Get all images and prepare batch processing
            $images = Storage::allFiles('trusco_images_new');
            $total = count($images);
            $notFounds = [];
            $batchSize = 1000; // Process 1000 images per batch
            $processed = 0;

            // Open output file
            $output = fopen(storage_path('trusco_img_mapping_new.csv'), 'a');

            // Add BOM and headers if file is empty
            if (filesize(storage_path('trusco_img_mapping.csv')) === 0) {
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['品目コード', '写真名']);
            }

            // Process images in batches
            foreach (array_chunk($images, $batchSize) as $batchNumber => $imageBatch) {
                $this->info("Processing batch " . ($batchNumber + 1) . " of " . ceil($total / $batchSize));

                // Prepare all filenames for this batch
                $fileNameMap = [];
                foreach ($imageBatch as $image) {
                    $originalFileName = basename($image);
                    $fileName = strtoupper($originalFileName);
                    $fileName = str_replace(['.jpg', '.png', '.jpeg', '.JPG', '.PNG', '.JPEG'], '', $fileName);
                    $formattedFileName = str_replace([' ', '_'], '', $fileName);

                    $fileNameMap[$formattedFileName] = [
                        'original' => $originalFileName,
                        'path' => $image
                    ];
                }

                // Bulk query for all products in this batch
                $products = DB::connection('catalog')
                    ->table('oc_product')
                    ->select('sku')
                    ->whereIn(DB::raw('REPLACE(sku, " ", "")'), array_keys($fileNameMap))
                    ->get()
                    ->groupBy(function ($item) {
                        return str_replace([' ', '_'], '', $item->sku);
                    });

                // Process results
                foreach ($fileNameMap as $formattedFileName => $fileInfo) {
                    $matchingProducts = $products->get($formattedFileName, collect([]));

                    if ($matchingProducts && $matchingProducts->count() === 1) {
                        $sku = $matchingProducts->first()->sku;
                        fputcsv($output, [$sku, $fileInfo['original']]);
                        Storage::delete($fileInfo['path']);
                        $processed++;

                        // Thêm log hiển thị tiến độ
                        if ($processed % 100 == 0) { // Log mỗi 100 ảnh đã xử lý
                            $percentage = round(($processed / $total) * 100, 2);
                            $this->info("Processed: {$processed}/{$total} images ({$percentage}%)");
                        }
                    } elseif ($matchingProducts && $matchingProducts->count() > 1) {
                        info('Multiple products found', [
                            'fileName' => $fileInfo['original'],
                            'products' => $matchingProducts->pluck('sku')->toArray()
                        ]);
                    } else {
                        $notFounds[] = $fileInfo['original'];
                    }
                }

                // Flush after each batch
                fflush($output);

                $this->info("Completed batch " . ($batchNumber + 1));
            }

            if (!empty($notFounds)) {
                info('Not found images', ['notFound' => $notFounds]);
            }
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            throw $e;
        } finally {
            if ($output) {
                fclose($output);
            }
        }

        $this->info('Done!');
    }
}
