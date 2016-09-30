<?php
namespace Rostenkowski\ImageStore\Drivers;

use Nette\Utils\Image;
use \Rostenkowski\ImageStore\DriverInterface;
use Rostenkowski\ImageStore\Exceptions\FtpLoginException;
use Rostenkowski\ImageStore\Exceptions\UploaderException;
use Rostenkowski\ImageStore\Meta;
use Rostenkowski\ImageStore\Request;
use Rostenkowski\ImageStore\Requests\ImageRequest;

class FtpDriver extends CommonDriver implements DriverInterface
{
    private $write;
    private $publicDataUrl;
    private $publicCacheUrl;
    private $writeStream;
    private $serverCachePath;
    private $preCalculatePreviews;
    private $serverDataPath;
    private $originalCache = [];

    public function __construct($write, $publicDataUrl = null, $publicCacheUrl = null, $serverDataPath = '/', $serverCachePath = '/imagecache/', $preCalculatePreviews = [])
    {
        $this->write = $write;
        $this->serverCachePath = $serverCachePath;
        $this->serverDataPath = $serverDataPath;
        $this->publicCacheUrl = $publicCacheUrl;
        $this->publicDataUrl = $publicDataUrl;
        $this->preCalculatePreviews = $preCalculatePreviews;

        if (!isset($this->write['port'])) {
            $this->write['port'] = 21;
        }

        if ($this->serverDataPath !== '/') {
            ftp_chdir($this->getWriteStream(), $this->serverDataPath);
        }
    }

    private function getWriteStream()
    {
        if (!$this->writeStream) {
            $this->writeStream = @ftp_connect($this->write['host'], $this->write['port']);
            if (!@ftp_login($this->writeStream, $this->write['login'], $this->write['pass'])) {
                throw new FtpLoginException('Unable to log in with write permissions');
            }
            ftp_pasv($this->writeStream, true);
        }
        return $this->writeStream;
    }

    public function getStorageDriverName()
    {
        return 'ftp';
    }

    public function save(Image $image, Meta $meta)
    {
        if (!$this->fileExists($meta)) {
            $hash = $meta->getHash();
            $dir1 = $hash[0].$hash[1];
            $dir2 = $hash[2].$hash[3];
            ftp_chdir($this->getWriteStream(), $dir1);
            ftp_chdir($this->getWriteStream(), $dir2);

            $imageString = $image->__toString();
            $fp = fopen('php://temp/maxmemory:'.(strlen($imageString)), 'r+');
            fputs($fp, $imageString.PHP_EOL);
            rewind($fp);

            if (!@ftp_fput($this->getWriteStream(), $hash.'.'.$this->getExtension($meta->getType()), $fp, FTP_BINARY)) {
                throw new  UploaderException(4);
            }
            ftp_chdir($this->getWriteStream(), $this->serverDataPath);
        }
        $meta->setStorageDriver($this->getStorageDriverName());
        $this->originalCache[$meta->getHash()] = $image;

        $this->pregeneratePreviews($meta);

    }

    public function preGeneratePreviews(Meta $meta)
    {
        if ($this->preCalculatePreviews) {
            foreach ($this->preCalculatePreviews as $preview) {
                $request = new ImageRequest($meta, $preview);
                $link = $this->calculateLink($request);
            }
        }
    }

    public function preGenerateDirectories()
    {
        $numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f'];
        $dirs = [];
        foreach ($numbers as $number1) {
            foreach ($numbers as $number2) {
                $dirs[] = $number1.$number2;
            }
        }

        foreach ($dirs as $dir) {
            @ftp_mkdir($this->getWriteStream(), $this->serverDataPath.$dir);
            @ftp_mkdir($this->getWriteStream(), $this->serverCachePath.$dir);
            foreach ($dirs as $subDir) {
                @ftp_mkdir($this->getWriteStream(), $this->serverDataPath.$dir.'/'.$subDir);
                @ftp_mkdir($this->getWriteStream(), $this->serverCachePath.$dir.'/'.$subDir);
                echo $dir.'/'.$subDir.'<br />';
            }
        }
    }

    private function getPath(Meta $meta, Request $request = null)
    {
        $hash = $meta->getHash();
        $dir = $hash[0].$hash[1].'/'.$hash[2].$hash[3];
        $requestPart = null;
        if ($request) {
            $dimensions = $request->getDimensions();
            $flags = $request->getFlags();
            $crop = (int) $request->getCrop();
            $requestPart = $dimensions.$flags.$crop;
        }
        return $dir.'/'.$hash.$requestPart.'.'.$this->getExtension($meta->getType());
    }

    private function getServerPath(Meta $meta)
    {
        return $this->serverDataPath.$this->getPath($meta);
    }


    private function fetchOriginal(Meta $meta)
    {
        if (!isset($this->originalCache[$meta->getHash()])) {
            $this->originalCache[$meta->getHash()] = Image::fromFile($this->publicDataUrl.$this->getPath($meta));
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

    public function fileExists(Meta $meta)
    {
        $url = $this->publicDataUrl.$this->getPath($meta);
        if ($this->curlGetResponseCode($url) == 200) {
            return true;
        }
        return false;
    }

    /**
     * Parses the given dimensions string for the image width and height.
     *
     * @param  string $dimensions The dimensions string
     * @return array  The width and height of the image in pixels
     */
    private function processDimensions($dimensions)
    {
        if (strpos($dimensions, 'x') !== FALSE) {
            list($width, $height) = explode('x', $dimensions); // different dimensions, eg. "210x150"
            $width = intval($width);
            $height = intval($height);
        } else {
            $width = intval($dimensions); // same dimensions, eg. "210" => 210x210
            $height = $width;
        }
        return array($width, $height);
    }


    /**
     * Crops the given image using the given image request options.
     *
     * @param  Image   $image   The image to resize
     * @param  Request $request The image request
     * @return Image   The image thumbnail
     */
    private function crop(Image $image, Request $request)
    {
        if ($request->getDimensions() === Request::ORIGINAL) {
            return $image;
        }
        list($width, $height) = $this->processDimensions($request->getDimensions());
        $resizeWidth = $width;
        $resizeHeight = $height;
        $originalWidth = $request->getMeta()->getWidth();
        $originalHeight = $request->getMeta()->getHeight();
        $originalLandscape = $originalWidth > $originalHeight;
        $cropLandscape = $width > $height;
        $equals = $width === $height;
        if ($originalLandscape) {
            if ($cropLandscape) {
                $coefficient = $originalHeight / $height;
                $scaledWidth = round($originalWidth / $coefficient);
                $left = round(($scaledWidth - $width) / 2);
                $top = 0;
                if ($scaledWidth < $width) {
                    $coefficient = $originalWidth / $width;
                    $scaledHeight = round($originalHeight / $coefficient);
                    $left = 0;
                    $top = round(($scaledHeight - $height) / 2);
                }
            } else {
                $coefficient = $originalHeight / $height;
                $scaledWidth = round($originalWidth / $coefficient);
                $left = round(($scaledWidth - $width) / 2);
                $top = 0;
            }
        } else {
            if ($cropLandscape || $equals) {
                $coefficient = $originalWidth / $width;
                $scaledHeight = round($originalHeight / $coefficient);
                $left = 0;
                $top = round(($scaledHeight - $height) / 2);
            } else {
                $coefficient = $originalHeight / $height;
                $scaledWidth = round($originalWidth / $coefficient);
                $left = round(($scaledWidth - $width) / 2);
                $top = 0;
            }
        }
        $image->resize($resizeWidth, $resizeHeight, Image::FILL);
        $image->crop($left, $top, $width, $height);
        return $image;
    }

    /**
     * Resizes the given image to the given dimensions using given flags.
     *
     * @param  Image   $image   The image to resize
     * @param  Request $request The image request
     * @return Image   The image thumbnail
     */
    private function resize(Image $image, Request $request)
    {
        if ($request->getDimensions() === Request::ORIGINAL) {
            return $image;
        }
        list($width, $height) = $this->processDimensions($request->getDimensions());
        return $image->resize($width, $height, $request->getFlags());
    }

    public function link(Request $request)
    {
        if (in_array($request->getDimensions(), $this->preCalculatePreviews) && !$request->getCrop() && !$request->getFlags()) {
            $url = $this->publicCacheUrl.$this->getPath($request->getMeta(), $request);
            return $url;
        }

        return $this->calculateLink($request);
    }

    public function calculateLink(Request $request)
    {
        $meta = $request->getMeta();
        $url = $this->publicCacheUrl.$this->getPath($request->getMeta(), $request);
        if ($this->curlGetResponseCode($url) !== 200) {
            $original = $this->fetchOriginal($meta);
            if ($request->getCrop()) {
                $image = $this->crop($original, $request);
            } else {
                $image = $this->resize($original, $request);
            }
            $hash = $meta->getHash();
            $dir1 = $hash[0].$hash[1];
            $dir2 = $hash[2].$hash[3];
            ftp_chdir($this->getWriteStream(), $this->serverCachePath);
            ftp_chdir($this->getWriteStream(), $dir1);
            ftp_chdir($this->getWriteStream(), $dir2);

            $imageString = $image->__toString();
            $fp = fopen('php://temp/maxmemory:'.(strlen($imageString)), 'r+');
            fputs($fp, $imageString.PHP_EOL);
            rewind($fp);
            $requestPart = null;
            if ($request) {
                $dimensions = $request->getDimensions();
                $flags = $request->getFlags();
                $crop = (int) $request->getCrop();
                $requestPart = $dimensions.$flags.$crop;
            }

            if (!@ftp_fput($this->getWriteStream(), $hash.$requestPart.'.'.$this->getExtension($meta->getType()), $fp, FTP_BINARY)) {
                throw new  UploaderException(4);
            }
            ftp_chdir($this->getWriteStream(), $this->serverDataPath);
        }

        return $url;
    }
}