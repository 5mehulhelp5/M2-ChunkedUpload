<?php
namespace Merlin\ChunkedUpload\Controller\Adminhtml\Product\Gallery;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Controller\Adminhtml\Product\Gallery\Upload as CoreUpload;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Image\AdapterFactory;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ObjectManager;

class Upload extends CoreUpload
{
    /** @var RawFactory */
    protected $resultRawFactory;
    /** @var AdapterFactory */
    protected $imageFactory;
    /** @var Filesystem */
    protected $filesystem;
    /** @var MediaConfig */
    protected $mediaConfig;
    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        Context $context,
        RawFactory $resultRawFactory,
        AdapterFactory $adapterFactory = null,
        Filesystem $filesystem = null,
        MediaConfig $mediaConfig = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct(
            $context,
            $resultRawFactory,
            $adapterFactory,
            $filesystem,
            $mediaConfig
        );
        $om                     = ObjectManager::getInstance();
        $this->resultRawFactory = $resultRawFactory;
        $this->imageFactory     = $adapterFactory ?: $om->get(AdapterFactory::class);
        $this->filesystem       = $filesystem     ?: $om->get(Filesystem::class);
        $this->mediaConfig      = $mediaConfig    ?: $om->get(MediaConfig::class);
        $this->logger           = $logger         ?: $om->get(LoggerInterface::class);
    }

    public function execute()
    {
        $responseData = [];
        $absoluteTmp  = null;

        try {
            // 1) Ensure tmp directory
            $mediaWrite = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $baseTmp    = $this->mediaConfig->getBaseTmpMediaPath();
            $tmpPath    = $mediaWrite->getAbsolutePath($baseTmp);
            if (!is_dir($tmpPath) && !@mkdir($tmpPath, 0755, true)) {
                throw new LocalizedException("Could not create tmp directory {$tmpPath}");
            }
            if (!is_writable($tmpPath)) {
                throw new LocalizedException("Tmp directory not writable: {$tmpPath}");
            }

            // 2) Chunked upload & merge
            /** @var Uploader $uploader */
            $uploader = $this->_objectManager->create(
                Uploader::class,
                ['fileId' => 'image']
            );
            $uploader->setAllowedExtensions(['jpg','jpeg','gif','png'])
                     ->setAllowRenameFiles(true)
                     ->setFilesDispersion(true);

            $mediaRead = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $result    = $uploader->save($tmpPath);

            $this->_eventManager->dispatch(
                'catalog_product_gallery_upload_image_after',
                ['result' => $result, 'action' => $this]
            );

            if (!is_array($result)) {
                throw new \RuntimeException('Uploader returned non-array result');
            }

            // 3) Build base JSON response
            unset($result['tmp_name'], $result['path']);
            $result['url']  = $this->mediaConfig->getTmpMediaUrl($result['file']);
            $result['file'] = $result['file'] . '.tmp';
            $responseData   = $result;

            // 4) Locate the merged temp file
            $relative    = ltrim(str_replace('.tmp','',$responseData['file']), '/');
            $absoluteTmp = $mediaRead->getAbsolutePath("{$baseTmp}/{$relative}");
            if (!file_exists($absoluteTmp)) {
                throw new \RuntimeException("File not found: {$absoluteTmp}");
            }

            // 5) Read original dimensions
            $size = @getimagesize($absoluteTmp);
            if (!$size) {
                throw new \RuntimeException("Invalid image file: {$absoluteTmp}");
            }
            list($origW, $origH) = $size;

            // 6) Compute target dimensions
            if ($origW > 2000) {
                $newW = 2000;
                $newH = (int) round($origH * ($newW / $origW));
            } else {
                $newW = $origW;
                $newH = $origH;
            }

            // 7) Auto-orient and resize
            if (class_exists('\Imagick')) {
                $img = new \Imagick($absoluteTmp);

                if (method_exists($img, 'autoOrientImage')) {
                    // Modern Imagick can auto-orient
                    $img->autoOrientImage();
                } else {
                    // Fallback manual orientation
                    $orientation = $img->getImageOrientation();
                    switch ($orientation) {
                        case \Imagick::ORIENTATION_BOTTOMRIGHT:
                            $img->rotateImage(new \ImagickPixel('none'), 180);
                            break;
                        case \Imagick::ORIENTATION_RIGHTTOP:
                            $img->rotateImage(new \ImagickPixel('none'), 90);
                            break;
                        case \Imagick::ORIENTATION_LEFTBOTTOM:
                            $img->rotateImage(new \ImagickPixel('none'), 270);
                            break;
                    }
                    $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                }

                // Scale by width, preserve aspect
                $img->thumbnailImage($newW, 0);
                $img->writeImage($absoluteTmp);
                $img->clear();
                $img->destroy();
            } else {
                // Fallback to Magento adapter
                $adapter = $this->imageFactory->create();
                if ($adapter->open($absoluteTmp)) {
                    // Basic EXIF-based rotation for JPEGs
                    if (function_exists('exif_read_data') && stripos($relative, '.jpg') !== false) {
                        @$exif = exif_read_data($absoluteTmp);
                        $ori = !empty($exif['Orientation']) ? (int)$exif['Orientation'] : null;
                        switch ($ori) {
                            case 3: $adapter->rotate(180); break;
                            case 6: $adapter->rotate(90);  break;
                            case 8: $adapter->rotate(270); break;
                        }
                    }
                    $adapter->constrainOnly(true);
                    $adapter->keepAspectRatio(true);
                    $adapter->keepFrame(false);
                    $adapter->keepTransparency(true);
                    $adapter->resize($newW);
                    $adapter->save($absoluteTmp);
                } else {
                    $this->logger->warning("Adapter failed to open {$absoluteTmp}");
                }
            }

            // 8) Re-read final dimensions
            $finalSize = @getimagesize($absoluteTmp);
            if ($finalSize) {
                list($newW, $newH) = $finalSize;
            }

            $responseData['width']  = $newW;
            $responseData['height'] = $newH;

        } catch (\Throwable $e) {
            $this->logger->error(
                "Merlin_ChunkedUpload error for ".($result['file'] ?? '[unknown]').": ".$e->getMessage()
            );
            $responseData = [
                'file'  => $result['file'] ?? null,
                'error' => $e->getMessage()
            ];
        }

        // 9) Return JSON
        /** @var \Magento\Framework\Controller\Result\Raw $response */
        $response = $this->resultRawFactory->create();
        $response->setHeader('Content-Type','application/json', true)
                 ->setContents(json_encode($responseData));
        return $response;
    }
}
