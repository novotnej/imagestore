<?php
namespace Rostenkowski\ImageStore\Drivers;

use Nette\Utils\Image;
use \Rostenkowski\ImageStore\DriverInterface;
use Rostenkowski\ImageStore\Meta;
use Rostenkowski\ImageStore\Request;

class FileSystemDriver extends CommonDriver implements DriverInterface
{
    private $wwwCachePath;
    private $cachePath;
    private $dataPath;

    public function __construct($dataPath, $cachePath, $wwwCachePath)
    {
        $this->cachePath = $cachePath;
        $this->dataPath = $dataPath;
        $this->wwwCachePath = $wwwCachePath;
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0750, TRUE);
        }
        if (!is_dir($this->dataPath)) {
            mkdir($this->dataPath, 0750, TRUE);
        }
    }

    public function save(Image $image, Meta $meta)
    {
        $meta->setStorageDriver($this->getStorageDriverName());
        if (!$this->fileExists($meta)) {
            $hash = $meta->getHash();
            $dir = $this->dataPath.$hash[0].$hash[1].'/'.$hash[2].$hash[3];
            if (!is_dir($dir)) {
                mkdir($dir, 0750, TRUE);
            }
            $image->save($this->getServerPath($meta));
        }
    }

    private function getServerPath(Meta $meta)
    {
        $hash = $meta->getHash();
        $dir = $hash[0].$hash[1].'/'.$hash[2].$hash[3];
        return $this->dataPath.'/'.$dir.'/'.$hash.'.'.$this->getExtension($meta->getType());
    }

    private function fetchOriginal(Meta $meta)
    {
        $image = Image::fromFile($this->getServerPath($meta));
        return $image;
    }

    public function fileExists(Meta $meta)
    {
        if (is_file($this->getServerPath($meta)));
    }

    public function getStorageDriverName()
    {
        return 'filesystem';
    }

    public function link(Request $request)
    {
        return $this->calculateLink($request);
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
        return $this->getDirPath($meta).'/'.$hash.$requestPart.'.'.$this->getExtension($meta->getType());
    }

    private function getDirPath(Meta $meta)
    {
        $hash = $meta->getHash();
        $dir = $hash[0].$hash[1].'/'.$hash[2].$hash[3];
        return $dir;
    }

    public function calculateLink(Request $request)
    {
        $meta = $request->getMeta();
        $path = $this->getPath($request->getMeta(), $request);
        if (!is_file($this->cachePath.$path)) {
            $original = $this->fetchOriginal($meta);
            if ($request->getCrop()) {
                $image = $this->crop($original, $request);
            } else {
                $image = $this->resize($original, $request);
            }

            @mkdir($this->cachePath.'/'.$this->getDirPath($meta), 0750, true);
            $image->save($this->cachePath.'/'.$path);
        }

        return $this->wwwCachePath.$path;
    }
}