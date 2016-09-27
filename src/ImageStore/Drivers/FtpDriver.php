<?php
namespace Rostenkowski\ImageStore\Drivers;

use Nette\Utils\Image;
use \Rostenkowski\ImageStore\DriverInterface;
use Rostenkowski\ImageStore\Exceptions\FtpLoginException;
use Rostenkowski\ImageStore\Exceptions\UploaderException;
use Rostenkowski\ImageStore\Meta;
use Rostenkowski\ImageStore\Request;

class FtpDriver extends CommonDriver implements DriverInterface
{
    private $write;
    private $read;
    private $writeStream;
    private $readStream;
    private $serverPath;

    public function __construct($write, $read, $serverPath = '/')
    {
        $this->write = $write;

        $this->serverPath = $serverPath;

        if (!isset($this->write['port'])) {
            $this->write['port'] = 21;
        }
        if (!$read) {
            $this->read = $write;
        } else {
            if (!isset($read['port'])) {
                $read['port'] = 21;
            }
            $this->read = $read;
        }
        if ($serverPath !== '/') {
            @ftp_mkdir($this->getWriteStream(), $serverPath);
            ftp_chdir($this->getWriteStream(), $serverPath);
            ftp_chdir($this->getReadStream(), $serverPath);
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

    private function getReadStream()
    {
        if (!$this->readStream) {
            $this->readStream = @ftp_connect($this->read['host'], $this->read['port']);
            if (!@ftp_login($this->readStream, $this->read['login'], $this->read['pass'])) {
                throw new FtpLoginException('Unable to log in with read permissions');
            };
            ftp_pasv($this->readStream, true);
        }
        return $this->readStream;
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
            @ftp_mkdir($this->getWriteStream(), $dir1);
            ftp_chdir($this->getWriteStream(), $dir1);
            @ftp_mkdir($this->getWriteStream(), $dir2);
            ftp_chdir($this->getWriteStream(), $dir2);

            $imageString = $image->__toString();
            $fp = fopen('php://temp/maxmemory:'.(strlen($imageString)), 'r+');
            fputs($fp, $imageString.PHP_EOL);
            rewind($fp);

            if (!@ftp_fput($this->getWriteStream(), $hash.'.'.$this->getExtension($meta->getType()), $fp, FTP_BINARY)) {
                ftp_chdir($this->getWriteStream(), $this->serverPath);
                throw new  UploaderException(4);
            }
        }
        $meta->setStorageDriver($this->getStorageDriverName());
    }

    private function getServerPath(Meta $meta)
    {
        $hash = $meta->getHash();
        $dir = $hash[0].$hash[1].'/'.$hash[2].$hash[3];
        return $this->serverPath.$dir.'/'.$hash.'.'.$this->getExtension($meta->getType());
    }

    public function fileExists(Meta $meta)
    {
        $fileSize = ftp_size($this->getReadStream(), $this->getServerPath($meta));
        return ($fileSize > 0);
    }

    public function link(Request $request)
    {
        $server = 'ftp://'.$this->read['login'].':'.$this->read['pass'].'@'.$this->read['host'].':'.$this->read['port'];
        return $server.$this->getServerPath($request->getMeta());
    }
}