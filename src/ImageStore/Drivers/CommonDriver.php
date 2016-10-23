<?php
namespace Rostenkowski\ImageStore\Drivers;

use Nette\Object;
use \Nette\Utils\Image;
use Rostenkowski\ImageStore\Exceptions\ImageTypeException;
use Rostenkowski\ImageStore\Meta;
use Rostenkowski\ImageStore\Request;

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

    /**
     * Parses the given dimensions string for the image width and height.
     *
     * @param  string $dimensions The dimensions string
     * @return array  The width and height of the image in pixels
     */
    protected function processDimensions($dimensions)
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
    protected function crop(Image $image, Request $request)
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
    protected function resize(Image $image, Request $request)
    {
        if ($request->getDimensions() === Request::ORIGINAL) {
            return $image;
        }
        list($width, $height) = $this->processDimensions($request->getDimensions());
        return $image->resize($width, $height, $request->getFlags());
    }
}