<?php
namespace Rostenkowski\ImageStore\Drivers;

use Google\Cloud\Storage\StorageClient;
use \Nette\Utils\Image;
use \Rostenkowski\ImageStore\DriverInterface;
use Rostenkowski\ImageStore\Meta;
use Rostenkowski\ImageStore\Request;
use Rostenkowski\ImageStore\Requests\ImageRequest;

class GoogleCloudDriver extends CommonDriver implements DriverInterface
{
    private $bucketName;
    private $projectId;
    private $configFile;
    private $preCalculatePreviews = [];
    private $storage;
    private $publicUrl;
    private $originalCache = [];

    public function __construct($bucketName, $projectId, $configFile, $publicUrl = null, $preCalculatePreviews = [])
    {
        $this->bucketName = $bucketName;
        $this->projectId = $projectId;
        $this->publicUrl = $publicUrl;
        $this->configFile = $configFile;
        $this->preCalculatePreviews = $preCalculatePreviews;
    }

    private function getWriteStream()
    {
        if (!$this->storage) {
            $this->storage = new StorageClient([
                'projectId' => $this->projectId,
                'keyFilePath' => $this->configFile
            ]);
            $this->storage = $this->storage->bucket($this->bucketName);
        }
        return $this->storage;
    }

    public function getStorageDriverName()
    {
        return 'googleCloud';
    }

    public function save(Image $image, Meta $meta)
    {
        if (!$this->fileExists($meta)) {
            $imageString = $image->__toString();
            $fp = fopen('php://temp/maxmemory:'.(strlen($imageString)), 'r+');
            fputs($fp, $imageString.PHP_EOL);
            rewind($fp);
            $this->getWriteStream()->upload($fp, [
                'name' => $this->getPath($meta),
                'predefinedAcl' => 'publicRead'
            ]);
        }
        $meta->setStorageDriver($this->getStorageDriverName());
        $this->originalCache[$meta->getHash()] = $image;

        $this->preGeneratePreviews($meta);
    }

    public function preGeneratePreviews(Meta $meta)
    {
        if ($this->preCalculatePreviews) {
            foreach ($this->preCalculatePreviews as $preview) {
                $request = new ImageRequest($meta, $preview);
                $this->calculateLink($request);
            }
        }
    }

    private function getPath(Meta $meta, Request $request = null)
    {
        $hash = $meta->getHash();
        $requestPart = null;
        if ($request) {
            $dimensions = $request->getDimensions();
            $flags = $request->getFlags();
            $crop = (int) $request->getCrop();
            $requestPart = $dimensions.$flags.$crop;
        }
        return $hash.$requestPart.'.'.$this->getExtension($meta->getType());
    }

    private function getServerPath(Meta $meta)
    {
        return $this->getPath($meta);
    }


    private function fetchOriginal(Meta $meta)
    {
        if (!isset($this->originalCache[$meta->getHash()])) {
            $this->originalCache[$meta->getHash()] = Image::fromFile($this->getPublicUrl().$this->getPath($meta));
        }

        return clone $this->originalCache[$meta->getHash()];
    }

    private function curlGetResponseCode($url)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    public function fileExists(Meta $meta, Request $request = null)
    {
        $objects = $this->getWriteStream()->objects(['prefix' => $this->getPath($meta, $request)]);
        foreach ($objects as $object) {
            return true;
        }
        return false;
    }

    public function link(Request $request)
    {
        if (in_array($request->getDimensions(), $this->preCalculatePreviews) && !$request->getCrop() && !$request->getFlags()) {
            $url = $this->getPublicUrl().$this->getPath($request->getMeta(), $request);
            return $url;
        }

        return $this->calculateLink($request);
    }

    private function getPublicUrl()
    {
        if ($this->publicUrl) {
            return $this->publicUrl.'/';
        }
        return 'https://storage.googleapis.com/'.$this->bucketName.'/';
    }

    public function calculateLink(Request $request)
    {
        $meta = $request->getMeta();
        $url = $this->getPublicUrl().$this->getPath($request->getMeta(), $request);
        if (!$this->fileExists($meta, $request)) {
            $original = $this->fetchOriginal($meta);
            if ($request->getCrop()) {
                $image = $this->crop($original, $request);
            } else {
                $image = $this->resize($original, $request);
            }

            $imageString = $image->__toString();
            $fp = fopen('php://temp/maxmemory:'.(strlen($imageString)), 'r+');
            fputs($fp, $imageString.PHP_EOL);
            rewind($fp);

            $this->getWriteStream()->upload($fp, [
                'name' => $this->getPath($meta, $request),
                'predefinedAcl' => 'publicRead'
            ]);
        }

        return $url;
    }
}