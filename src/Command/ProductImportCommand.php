<?php

namespace App\Command;

use Carbon\Carbon;
use Pimcore\File;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image\Thumbnail\Config;
use Pimcore\Model\DataObject;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ProductImportCommand extends Command
{
    protected function configure()
    {
        $this
        ->setName('app:product-import')
        ->setDescription('Import product with image.')
        ->addArgument('url', InputArgument::REQUIRED, 'The URL to print.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getArgument('url');

        $client = HttpClient::create();
        $response = $client->request('GET', $url);

        if ($response->getStatusCode() === 200) {
            $jsonContent = $response->getContent();

            $jsonObjects = json_decode($jsonContent, false);

            foreach ($jsonObjects->products as $jsonProduct) {
                $this->createOrUpdateProduct($jsonProduct);
            }

        } else {
            $output->writeln([
                'Wrong URL',
                '============',
                'The URL is: ' . $url
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * @param string $imageUrl
     * @return Asset\Image|null
     */
    protected function createOrGetAsset(string $imageUrl):?Asset\Image
    {
        $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
        $asset = Asset\Image::getByPath("/images/" . $filename);

        if (!$asset) {
            $client = HttpClient::create();
            $response = $client->request('GET', $imageUrl);

            if ($response->getStatusCode() === 200) {
                $imageContent = $response->getContent();
                $tempFile = tempnam(sys_get_temp_dir(), 'pimcore');

                if (@is_array(getimagesize($tempFile))) {
                    return false;
                }

                File::putPhpFile($tempFile, $imageContent);

                $imageData = file_get_contents($tempFile);

                if ($imageData == false || @imagecreatefromstring($imageData) == false) {
                    return false;
                }

                $asset = new Asset\Image();
                $asset->setParentId(2);
                $asset->setFilename($filename);
                $asset->setData($imageData);
                $asset->save();

                $smallThumbnailConfig = Config::getByName('product_small_img');
                $asset->getThumbnail($smallThumbnailConfig)->getPath();
                $largeThumbnailConfig = Config::getByName('product_big_img');
                $asset->getThumbnail($largeThumbnailConfig)->getPath();

                unlink($tempFile);
            }
        }

        if ($asset instanceof Asset\Image) {
            return $asset;
        }

        return false;
    }

    /**
     * @param object $jsonProduct
     */
    protected function createOrUpdateProduct(object $jsonProduct):void
    {
        $product = DataObject\Product::getByGtin($jsonProduct->gtin, 1);

        if (!$product) {
            $product = new DataObject\Product();
            $product->setGtin($jsonProduct->gtin);
            $product->setParentId(1);
            $product->setKey($jsonProduct->name . ' (' . $jsonProduct->gtin . ')');
            $product->setPublished(true);
        }

        $product->setName($jsonProduct->name);
        $product->setDate(Carbon::createFromFormat('Y-m-d', $jsonProduct->date));

        $image = !empty ($jsonProduct->image) ? $this->createOrGetAsset($jsonProduct->image) : false;

        if ($image) {
            $product->setImage($image);
        }

        $product->save();
    }
}
