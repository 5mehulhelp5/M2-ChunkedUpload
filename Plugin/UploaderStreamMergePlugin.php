<?php
namespace Merlin\ChunkedUpload\Plugin;

use Magento\MediaStorage\Model\File\Uploader;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Psr\Log\LoggerInterface;

class UploaderStreamMergePlugin
{
    /** @var Filesystem */
    protected $fs;
    /** @var RequestInterface */
    protected $request;
    /** @var MediaConfig */
    protected $mediaConfig;
    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        Filesystem $fs,
        RequestInterface $request,
        MediaConfig $mediaConfig,
        LoggerInterface $logger
    ) {
        $this->fs          = $fs;
        $this->request     = $request;
        $this->mediaConfig = $mediaConfig;
        $this->logger      = $logger;
    }

    /**
     * After each chunk is saved, merge it properly into a single temp file.
     *
     * @param Uploader $subject
     * @param array    $result
     * @return array
     */
    public function afterSave(Uploader $subject, array $result)
    {
        $chunk  = (int)$this->request->getParam('chunk', 0);
        $chunks = (int)$this->request->getParam('chunks', 1);
        // only for real multi-part uploads
        if ($chunks > 1) {
            // resolve paths
            $baseTmp = $this->mediaConfig->getBaseTmpMediaPath(); // e.g. "tmp/catalog/product"
            /** @var \Magento\Framework\Filesystem\Directory\Write $mediaWrite */
            $mediaWrite = $this->fs->getDirectoryWrite(DirectoryList::MEDIA);

            $finalDir  = $mediaWrite->getAbsolutePath($baseTmp);
            $relative  = ltrim($result['file'], '/');           // e.g. "a/b/abcdef.jpg"
            $tmpFile   = $mediaWrite->getAbsolutePath($baseTmp . '/' . $relative);
            $finalFile = $finalDir . '/' . $relative;

            // make sure the target dir exists
            $parent = dirname($finalFile);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
                $this->logger->debug("Merlin: created directory {$parent}");
            }

            try {
                if ($chunk === 0) {
                    // first chunk: move into place
                    if (file_exists($finalFile)) {
                        unlink($finalFile);
                    }
                    rename($tmpFile, $finalFile);
                    $this->logger->debug("Merlin: moved chunk 0 to {$finalFile}");
                } else {
                    // subsequent chunks: append
                    $out = fopen($finalFile, 'ab');
                    $in  = fopen($tmpFile,  'rb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);
                    unlink($tmpFile);
                    $this->logger->debug("Merlin: appended chunk {$chunk} to {$finalFile}");
                }

$this->logger->debug(sprintf(
    'Merlin: chunk %d for %s merged into %s (size %d)',
    $chunk,
    $relative,
    $finalFile,
    file_exists($finalFile) ? filesize($finalFile) : 0
));
            } catch (\Exception $e) {
                $this->logger->warning(
                    "Merlin: stream-merge failed on chunk {$chunk} for {$relative}: "
                    . $e->getMessage()
                );
            }
        }

        return $result;
    }
}
