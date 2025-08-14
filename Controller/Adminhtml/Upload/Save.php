<?php
namespace Merlin\ChunkedUpload\Controller\Adminhtml\Upload;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Image\AdapterFactory;
use Psr\Log\LoggerInterface;

class Save extends Action
{
    /** @var AdapterFactory */
    private $imageFactory;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        AdapterFactory $imageFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->imageFactory = $imageFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        $request = $this->getRequest();
        $result = ['error' => false];
        $mediaDir = BP . '/pub/media/catalog/product/tmp';
        try {
            $fileName = $request->getParam('name');
            $chunk    = (int)$request->getParam('chunk', 0);
            $chunks   = (int)$request->getParam('chunks', 1);
            $tmpPath  = "{$mediaDir}/{$fileName}.part";

            if (!is_dir($mediaDir)) {
                mkdir($mediaDir, 0755, true);
            }

            $in  = fopen($_FILES['file']['tmp_name'], 'rb');
            $out = fopen($tmpPath, $chunk === 0 ? 'wb' : 'ab');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            if ($chunk + 1 === $chunks) {
                $finalPath = BP . '/pub/media/catalog/product/' . $fileName;
                rename($tmpPath, $finalPath);

                $adapter = $this->imageFactory->create();
                $adapter->open($finalPath);
                $width  = $adapter->getOriginalWidth();
                $height = $adapter->getOriginalHeight();

                $result = [
                    'name'   => $fileName,
                    'size'   => filesize($finalPath),
                    'width'  => $width,
                    'height' => $height,
                    'url'    => $this->_url->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA])
                                . "catalog/product/{$fileName}"
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Chunk upload error: ' . $e->getMessage());
            $result = ['error' => true, 'message' => $e->getMessage()];
        }

        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $json->setData($result);
    }
}
