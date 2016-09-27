<?php
namespace Rostenkowski\ImageStore\Drivers;

use Nette\Object;
use \Nette\Utils\Image;
use Rostenkowski\ImageStore\Exceptions\ImageTypeException;
use Rostenkowski\ImageStore\Meta;

class CommonDriver extends Object
{
    /**
     * The image type -> file extension map
     *
     * @var array
     */
    private $extensions = array(Image::JPEG => 'jpg', Image::PNG => 'png', Image::GIF => 'gif');

    /**
     * The image type -> MIME type map
     *
     * @var array
     */
    private $mimeTypes = array(Image::JPEG => 'image/jpeg', Image::PNG => 'image/png', Image::GIF => 'image/gif');

    /**
     * Creates the absolute file name from the given meta information.
     *
     * @param  Meta $meta The image meta information
     * @return string The absolute file name
     */
    protected function createFilename(Meta $meta)
    {
        $ext = $this->getExtension($meta->getType());
        $hash = $meta->getHash();

        $filename = $hash.'.'.$ext;

        return $filename;
    }

    /**
     * Returns the file extension for the given image type.
     *
     * @param  integer $type The image type
     * @return string The file extension
     * @throws ImageTypeException
     */
    protected function getExtension($type)
    {
        if (!isset($this->extensions[$type])) {
            throw new ImageTypeException($type);
        }
        return $this->extensions[$type];
    }

    /**
     * @param integer $type The image type
     * @return string The file mime type
     * @throws ImageTypeException
     */
    protected function getMimeType($type)
    {
        if (!isset($this->mimeTypes[$type])) {
            throw new ImageTypeException($type);
        }
        return $this->mimeTypes[$type];
    }
}