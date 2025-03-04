<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Class CreateTruscoImageMapping
 * Command to process Trusco images and create SKU mapping
 */
class CreateTruscoImageMapping extends Command
{
    /**
     * Number of images to process in each batch
     *
     * @var int
     */
    private const BATCH_SIZE = 500;

    /**
     * CSV file headers for the mapping file
     *
     * @var array
     */
    private const CSV_HEADERS = ['品目コード', '写真名'];

    /**
     * Supported image file extensions
     *
     * @var array
     */
    private const IMAGE_EXTENSIONS = ['.jpg', '.png', '.jpeg', '.JPG', '.PNG', '.JPEG'];

    /**
     * Path to the source images directory
     *
     * @var string
     */
    private const SOURCE_IMAGES_DIR = 'images';

    /**
     * Path to the output mapping file
     *
     * @var string
     */
    private const OUTPUT_MAPPING_FILE = 'trusco_img_mapping_new.csv';

    /**
     * Path to the log directory
     *
     * @var string
     */
    private const LOG_DIR = 'logs/trusco';

    /**
     * Log file names
     *
     * @var string
     */
    private const LOG_FILE_MULTIPLE_SKU = 'multiple_sku_images.log';

    /**
     * Log file for images not found in the database
     *
     * @var string
     */
    private const LOG_FILE_NOT_FOUND = 'not_found_images.log';

    /**
     * Path to the processed images directory
     *
     * @var string
     */
    private const PROCESSED_IMAGES_DIR = 'processed_images';

    /**
     * Total number of images to process
     *
     * @var int
     */
    private $total = 0;

    /**
     * Number of images processed so far
     *
     * @var int
     */
    private $processed = 0;

    /**
     * Number of images successfully mapped to a single SKU
     *
     * @var int
     */
    private $successCount = 0;

    /**
     * Number of images matching multiple SKUs
     *
     * @var int
     */
    private $multipleSkuCount = 0;

    /**
     * Number of images with no matching SKU
     *
     * @var int
     */
    private $notFoundCount = 0;

    /**
     * List of image names that couldn't be matched to any SKU
     *
     * @var array
     */
    private $notFoundImages = [];

    /**
     * File handle for the output CSV file
     *
     * @var resource|null
     */
    private $outputFile;

    /**
     * The console command name and signature
     *
     * @var string
     */
    protected $signature = 'make:trusco-image-mapping';

    /**
     * The console command description
     *
     * @var string
     */
    protected $description = 'Create Trusco image mapping';

    /**
     * Execute the console command to create Trusco image mapping
     *
     * @return void
     */
    public function handle()
    {
        $this->generateTruscoImgMapping();
    }

    /**
     * Generates Trusco image mapping by processing images and creating SKU mappings
     * Handles the entire workflow including initialization, processing and logging
     *
     * @return void
     *
     * @throws \Exception
     */
    private function generateTruscoImgMapping()
    {
        try {
            $this->initializeProcess();
            $this->processImages();
            $this->logSummary();
            $this->logNotFoundImages();
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());
            throw $e;
        } finally {
            $this->closeOutputFile();
        }

        $this->info('Done!');
    }

    /**
     * Sets up initial requirements for image processing
     * Creates necessary directories, initializes output file with BOM and headers
     * Counts total images to be processed
     */
    private function initializeProcess(): void
    {
        if (! Storage::exists(self::LOG_DIR)) {
            Storage::makeDirectory(self::LOG_DIR);
        }

        Storage::put(self::LOG_DIR.'/'.self::LOG_FILE_MULTIPLE_SKU, '');
        Storage::put(self::LOG_DIR.'/'.self::LOG_FILE_NOT_FOUND, '');

        $this->outputFile = fopen(storage_path(self::OUTPUT_MAPPING_FILE), 'a');

        if (filesize(storage_path(self::OUTPUT_MAPPING_FILE)) === 0) {
            fwrite($this->outputFile, "\xEF\xBB\xBF");
            fputcsv($this->outputFile, self::CSV_HEADERS);
        }

        $images = Storage::allFiles(self::SOURCE_IMAGES_DIR);
        $this->total = count($images);
    }

    /**
     * Processes all images by dividing them into manageable batches
     * Iterates through image batches and processes each batch separately
     */
    private function processImages(): void
    {
        $images = Storage::allFiles(self::SOURCE_IMAGES_DIR);

        foreach (array_chunk($images, self::BATCH_SIZE) as $batchNumber => $imageBatch) {
            $this->processBatch($imageBatch, $batchNumber);
        }
    }

    /**
     * Processes a batch of images and displays progress information
     * Handles image processing, SKU matching, and progress tracking for each batch
     *
     * @param  array  $imageBatch  Array of image paths in current batch
     * @param  int  $batchNumber  Current batch sequence number
     */
    private function processBatch(array $imageBatch, int $batchNumber): void
    {
        $batchProgress = ($batchNumber + 1).'/'.ceil($this->total / self::BATCH_SIZE);
        $this->info("\nProcessing batch {$batchProgress}");
        $this->output->progressStart(count($imageBatch));

        $fileNameMap = $this->prepareFileNameMap($imageBatch);
        $products = $this->fetchProducts($fileNameMap);

        foreach ($fileNameMap as $formattedFileName => $fileInfo) {
            $this->processImage($formattedFileName, $fileInfo, $products);
            $this->displayProgress();
        }

        $this->output->progressFinish();
        $this->info("Completed batch {$batchProgress}");
    }

    /**
     * Creates a mapping of formatted filenames to their original names and paths
     * Standardizes filenames by removing extensions and special characters
     *
     * @param  array  $imageBatch  Array of image paths to process
     * @return array Associative array with formatted filenames as keys and original file info as values
     */
    private function prepareFileNameMap(array $imageBatch): array
    {
        $fileNameMap = [];
        foreach ($imageBatch as $image) {
            $originalFileName = basename($image);
            $fileName = strtoupper($originalFileName);
            $fileName = str_replace(self::IMAGE_EXTENSIONS, '', $fileName);
            $formattedFileName = str_replace([' ', '_'], '', $fileName);

            $fileNameMap[$formattedFileName] = [
                'original' => $originalFileName,
                'path' => $image,
            ];
        }

        return $fileNameMap;
    }

    /**
     * Retrieves products from database that match the formatted filenames
     * Groups products by their formatted SKU for easier matching
     *
     * @param  array  $fileNameMap  Map of formatted filenames to process
     * @return \Illuminate\Support\Collection Collection of products grouped by formatted SKU
     */
    private function fetchProducts(array $fileNameMap): \Illuminate\Support\Collection
    {
        return DB::connection('catalog')
            ->table('oc_product')
            ->select('sku')
            ->whereIn(DB::raw('REPLACE(sku, " ", "")'), array_keys($fileNameMap))
            ->get()
            ->groupBy(function ($item) {
                return str_replace([' ', '_'], '', $item->sku);
            });
    }

    /**
     * Processes individual image and determines appropriate handling based on SKU matches
     * Routes to appropriate handler based on number of matching SKUs found
     *
     * @param  string  $formattedFileName  Standardized filename for SKU matching
     * @param  array  $fileInfo  Original file information including path
     * @param  \Illuminate\Support\Collection  $products  Collection of potential matching products
     */
    private function processImage(string $formattedFileName, array $fileInfo, \Illuminate\Support\Collection $products): void
    {
        $matchingProducts = $products->get($formattedFileName, collect([]));
        $this->processed++;

        if ($matchingProducts->count() === 1) {
            $this->handleSingleMatch($matchingProducts->first()->sku, $fileInfo);
        } elseif ($matchingProducts->count() > 1) {
            $this->handleMultipleMatches($fileInfo, $matchingProducts);
        } else {
            $this->handleNoMatch($fileInfo);
        }
    }

    /**
     * Handles images that match exactly one SKU
     * Creates CSV mapping entry and moves image to processed directory
     *
     * @param  string  $sku  Matched product SKU
     * @param  array  $fileInfo  Original file information
     */
    private function handleSingleMatch(string $sku, array $fileInfo): void
    {
        fputcsv($this->outputFile, [$sku, $fileInfo['original']]);

        if (! Storage::exists(self::PROCESSED_IMAGES_DIR)) {
            Storage::makeDirectory(self::PROCESSED_IMAGES_DIR);
        }

        Storage::move(
            $fileInfo['path'],
            self::PROCESSED_IMAGES_DIR.'/'.$fileInfo['original']
        );

        $this->successCount++;
    }

    /**
     * Handles images that match multiple SKUs
     * Logs ambiguous matches for manual review
     *
     * @param  array  $fileInfo  Original file information
     * @param  \Illuminate\Support\Collection  $matchingProducts  Collection of matching products
     */
    private function handleMultipleMatches(array $fileInfo, \Illuminate\Support\Collection $matchingProducts): void
    {
        $this->multipleSkuCount++;

        $logMessage = sprintf(
            "[%s] Image: %s, SKUs: %s\n",
            now()->format('Y-m-d H:i:s'),
            $fileInfo['original'],
            implode(', ', $matchingProducts->pluck('sku')->toArray())
        );

        Storage::append(
            self::LOG_DIR.'/'.self::LOG_FILE_MULTIPLE_SKU,
            $logMessage
        );

        info('Multiple products found', [
            'fileName' => $fileInfo['original'],
            'products' => $matchingProducts->pluck('sku')->toArray(),
        ]);
    }

    /**
     * Handles images that don't match any SKUs
     * Logs unmatched images for review
     *
     * @param  array  $fileInfo  Original file information
     */
    private function handleNoMatch(array $fileInfo): void
    {
        $this->notFoundCount++;
        $this->notFoundImages[] = $fileInfo['original'];
        $logMessage = $fileInfo['original'];

        Storage::append(
            self::LOG_DIR.'/'.self::LOG_FILE_NOT_FOUND,
            $logMessage
        );
    }

    /**
     * Updates and displays current processing progress
     * Shows statistics including total progress, success rate, and various error counts
     */
    private function displayProgress(): void
    {
        $this->output->progressAdvance();
        $this->output->write("\033[1A");

        $totalPercentage = $this->calculatePercentage($this->processed);
        $successPercentage = $this->calculatePercentage($this->successCount);
        $notFoundPercentage = $this->calculatePercentage($this->notFoundCount);
        $multipleSkuPercentage = $this->calculatePercentage($this->multipleSkuCount);

        $this->info("\n=== Processing Progress ===");
        $this->info("Total Progress: {$this->processed}/{$this->total} images ({$totalPercentage}%)");
        $this->info("Success: {$this->successCount} images ({$successPercentage}%)");
        $this->info("Not Found: {$this->notFoundCount} images ({$notFoundPercentage}%)");
        $this->info("Multiple SKUs: {$this->multipleSkuCount} images ({$multipleSkuPercentage}%)");
        $this->info('========================');
    }

    /**
     * Calculates percentage of a value against total processed images
     *
     * @param  int  $value  Number to calculate percentage for
     * @return float Calculated percentage with 2 decimal precision
     */
    private function calculatePercentage(int $value): float
    {
        return round(($value / $this->total) * 100, 2);
    }

    /**
     * Logs information about images that couldn't be matched to any SKU
     * Records unmatched images for future analysis
     */
    private function logNotFoundImages(): void
    {
        if (! empty($this->notFoundImages)) {
            info('Not found images', ['notFound' => $this->notFoundImages]);
        }
    }

    /**
     * Generates and logs processing summary
     * Records statistics about successful matches, multiple matches, and unmatched images
     */
    private function logSummary(): void
    {
        $summary = sprintf(
            "\n=== Processing Summary ===\n".
                "Date: %s\n".
                "Total Images: %d\n".
                "Successful Matches: %d (%.2f%%)\n".
                "Multiple SKU Matches: %d (%.2f%%)\n".
                "No SKU Matches: %d (%.2f%%)\n".
                "========================\n",
            now()->format('Y-m-d H:i:s'),
            $this->total,
            $this->successCount,
            $this->calculatePercentage($this->successCount),
            $this->multipleSkuCount,
            $this->calculatePercentage($this->multipleSkuCount),
            $this->notFoundCount,
            $this->calculatePercentage($this->notFoundCount)
        );

        Storage::append(self::LOG_DIR.'/'.self::LOG_FILE_NOT_FOUND, $summary);
        Storage::append(self::LOG_DIR.'/'.self::LOG_FILE_MULTIPLE_SKU, $summary);
    }

    /**
     * Safely closes the output CSV file handle
     * Ensures proper resource cleanup
     */
    private function closeOutputFile(): void
    {
        if ($this->outputFile) {
            fclose($this->outputFile);
        }
    }
}
