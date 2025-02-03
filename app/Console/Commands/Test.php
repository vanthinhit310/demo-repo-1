<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

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
    public function generateTruscoImgMapping()
    {
        $images = Storage::allFiles('trusco_images');
        $total = count($images);
        $mapping = [];
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

                    if (!empty($sku)) {
                        $this->info('Product found for ' . $fileName . ' with code ' . $sku);
                        $mapping[] = [
                            'sku' => $products->first()->sku,
                            'fileName' => $fileName,
                        ];
                        fputcsv($output, [$sku, $originalFileName]);
                    }

                    Storage::delete($image);
                    $this->info('Deleted image: ' . $image);
                }
            } else {
                $this->warn('No product found for ' . $fileName);
                $notFounds[] = $fileName;
            }

            $this->info('Done ' . $image);
            $this->info('--------------------------------');
        }

        if (!empty($notFounds)) {
            info('Not founds images', ['notFound' => $notFounds]);
        }

        fclose($output);
        $this->info('Done!');
    }
}
