<?php
namespace Rostenkowski\ImageStore\Drivers;

use Google\Cloud\Storage\StorageClient;
use Nette\Caching\Cache;
use Nette\Caching\Storages\FileStorage;
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
    private $cache;

    public function __construct($bucketName, $projectId, $configFile, $cachePath, $publicUrl = null, $preCalculatePreviews = [])
    {
        $this->bucketName = $bucketName;
        $this->projectId = $projectId;
        $this->publicUrl = $publicUrl;
        $this->configFile = $configFile;
        $this->preCalculatePreviews = $preCalculatePreviews;
        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0777, true);
        }
        $storage = new FileStorage($cachePath);
        $this->cache = new Cache($storage);
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
            $this->cache->save($this->getPath($meta), 'known');
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

    private function fetchOriginal(Meta $meta)
    {
        if (!isset($this->originalCache[$meta->getHash()])) {
            $this->originalCache[$meta->getHash()] = Image::fromFile($this->getPublicUrl().$this->getPath($meta));
        }

        return clone $this->originalCache[$meta->getHash()];
    }

    public function fileExists(Meta $meta, Request $request = null)
    {
        $path = $this->getPath($meta, $request);
        $known = $this->cache->load($path);
        if ($known === 'known') {
            return true;
        }
        $objects = $this->getWriteStream()->objects(['prefix' => $path]);
        foreach ($objects as $object) {
            $this->cache->save($path, 'known');
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
        $path = $this->getPath($meta, $request);
        $url = $this->getPublicUrl().$path;
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
                'name' => $path,
                'predefinedAcl' => 'publicRead'
            ]);
            $this->cache->save($path, 'known');
        }

        return $url;
    }
}