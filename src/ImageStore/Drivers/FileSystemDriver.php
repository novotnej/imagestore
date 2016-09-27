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
        return 'FS R';
    }
}