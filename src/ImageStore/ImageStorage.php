<?php

namespace Rostenkowski\ImageStore;


use Nette\Application\Responses\FileResponse;
use Nette\DI\Container;
use Nette\Http\FileUpload;
use Nette\Object;
use Nette\Utils\Image;
use Nette\Utils\Strings;
use Rostenkowski\ImageStore\Directory\Directory;
use Rostenkowski\ImageStore\Entity\EmptyImage;
use Rostenkowski\ImageStore\Entity\ImageEnvelope;
use Rostenkowski\ImageStore\Exceptions\ImageTypeException;
use Rostenkowski\ImageStore\Exceptions\InvalidCacheDirectoryException;
use Rostenkowski\ImageStore\Exceptions\UnknownDriverException;
use Rostenkowski\ImageStore\Exceptions\UploaderException;
use Rostenkowski\ImageStore\Files\ImageFile;

/**
 * Image file storage
 *
 * - The images are stored in a regular files in the given `$directory`.
 * - The files are organized in a 2-level directory structure with maximum of 256² directories.
 * - The directory tree is well balanced thanks to the image hashes used for the directory path creation.
 * - The storage stores only one file even if the same image is stored multiple times, thus images should be
 *   deleted only after it is sure it is not referenced from other entities.
 * - The image thumbnails are created on demand and cached in the `$cacheDirectory`.
 */
class ImageStorage extends Object implements Storage
{

	/**
	 * The directory to store the images in
	 *
	 * @var Directory
	 */
	private $directory;



	/**
	 * The public accessible URL of the cache directory
	 *
	 * @var string
	 */
	private $baseUrl;

	/**
	 * The cache directory
	 *
	 * @var Directory
	 */
	private $cacheDirectory;

    private $driver;
    private $fallbackDriver = null;
    private $context;
    private $drivers;

    /**
     * ImageStorage constructor.
     * @param Container $context
     */
	public function __construct(Container $context)
	{
	    $this->context = $context;
        $this->driver = $context->getService('imageStore.driver');
        try {
            $this->fallbackDriver = $context->getService('imageStore.fallbackDriver');
        } catch (\Exception $e) {

        }
	}


	/**
	 * Fetches the cached copy of an image by given request.
	 *
	 * @param  Request $request The image request
	 * @return Image
	 */
	public function fetch(Request $request)
	{
		$this->checkImage($request);
		$filename = $this->createCacheFilename($request);

		return Image::fromFile($filename);
	}


	/**
	 * Fetches the original image by the given image meta information.
	 *
	 * @param  Meta $meta The stored image meta information
	 * @return Image The stored image
	 */
	public function original(Meta $meta)
	{
		return Image::fromFile($this->createFilename($meta));
	}


	/**
	 * Removes the image from the storage by the given image meta information.
	 *
	 * @param  Meta $meta The image information
	 * @return ImageStorage Fluent interface
	 */
	public function remove(Meta $meta)
	{
		unlink($this->createFilename($meta));

		return $this;
	}


	/**
	 * Checks if an image of the given meta information is stored in the storage.
	 *
	 * @param  Meta $meta The image meta information
	 * @return boolean TRUE if image is present in the storage or FALSE otherwise
	 */
	public function contains(Meta $meta)
	{
	    //TODO!!
		return false;
	}


	/**
	 * Stores the given uploaded file.
	 *
	 * @param  FileUpload $upload
	 * @param  meta       $meta
	 * @return ImageStorage Fluent interface
	 * @throws UploaderException
	 */
	public function upload(FileUpload $upload, Meta $meta)
	{
		if ($upload->getError()) {
			throw new UploaderException($upload->getError());
		}

		$source = $upload->getTemporaryFile();
        $image = Image::fromFile($source);
        $this->addFromNetteImage($image, $meta);

		if (file_exists($source)) {
			unlink($source);
		}
		return $this;
	}

	public function addFromNetteImage(\Nette\Utils\Image $image, Meta $meta)
    {
        $this->readMetaFromNetteImage($image, $meta);
        if ($image->getWidth() > 1920 || $image->getHeight() > 1080) {
            $image->resize(1920, 1080);
        }
        try {
            $this->driver->save($image, $meta);
        } catch (\Exception $e) {
            if (!$this->fallbackDriver) {
                throw $e;
            }
            $this->fallbackDriver->save($image, $meta);
        }

        return $this;
    }


	/**
	 * Returns the URL of the cached version of the image.
	 *
	 * @param  Request $request The image request
	 * @return string  The URL of the image
	 */
	public function link(Request $request)
	{
	    if (!$this->drivers) {
            $driverServices = $this->context->findByType('Rostenkowski\ImageStore\DriverInterface');
            foreach ($driverServices as $driverService) {
                $driver = $this->context->getService($driverService);
                $this->drivers[$driver->getStorageDriverName()] = $driver;
            }
        }
        $metaDriver = $request->getMeta()->getStorageDriver();
        if (!isset($this->drivers[$metaDriver])) {
            throw new UnknownDriverException($metaDriver);
        }

		return $this->drivers[$metaDriver]->link($request);
	}


	/**
	 * Creates the file download HTTP response which can be easily sent using the `send()` method.
	 *
	 * @param  Request $request The image request
	 * @return FileResponse
	 */
	public function download(Request $request)
	{
		$this->checkImage($request);
		$filename = $this->createCacheFilename($request);

		return new FileResponse($filename, basename($filename), $request->getMeta()->getType());
	}


	/**
	 * Renders the image directly to the standard output.
	 *
	 * @param  Request $request The image request
	 * @return ImageStorage Fluent interface
	 */
	public function send(Request $request)
	{
		$this->checkImage($request);
		$filename = $this->createCacheFilename($request);
		$fp = fopen($filename, 'r');
		header("Content-type: " . $this->getMimeType($request->getMeta()->getType()));
		fpassthru($fp);
		fclose($fp);

		return $this;
	}


	/**
	 * Checks that the cached version of the image exists and creates it if not.
	 *
	 * @param  Request $request The image request
	 * @return string  The file name of the existing cached version of an image
	 */
	private function checkImage(Request $request)
	{
		$filename = $this->createCacheFilename($request);

		if (!file_exists($filename)) {

			$meta = $request->getMeta();

			if (!$this->contains($meta)) {

				// Use another meta
				$meta = new EmptyImage();
				$request->setMeta($meta);
				$filename = $this->createCacheFilename($request);

			}

			$original = $this->original($meta);

			if ($request->getCrop()) {
				$image = $this->crop($original, $request);
			} else {
				$image = $this->resize($original, $request);
			}
			new Directory(dirname($filename));
			$image->save($filename, 75, $meta->getType());
		}
	}


	/**
	 * Returns the file name of the cached version of the image.
	 *
	 * @param Request $request
	 * @return string The file name of the cached version of the image
	 */
	private function createCacheFilename(Request $request)
	{
		$filePath = $this->createFilePath($request);

		return "$this->cacheDirectory/$filePath";
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


	/**
	 * Returns the part of the image filename relative to the cache directory.
	 *
	 * @param  Request $request The image request
	 * @return string
	 */
	private function createFilePath(Request $request)
	{
		$dimensions = $request->getDimensions();
		$flags = $request->getFlags();
		$crop = (int) $request->getCrop();

		$meta = $request->getMeta();
		$ext = $this->getExtension($meta->getType());
		$baseFilePath = $this->createCacheDirectoryPath($meta->getHash());

		return "$baseFilePath.$dimensions.$flags.$crop.$ext";
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
	 * Creates the internal directory path from the given hash.
	 *
	 * Some special images like the "Image Not Available" image are stored
	 * in directories prefixed with an underscore. Those directories are not
	 * fragmented to hash based structure.
	 *
	 * @param  string $hash Tha SHA1 hash
	 * @return string
	 */
	private function createCacheDirectoryPath($hash)
	{
		if ($hash{0} === '_') {
			return "$hash/$hash";
		}

		return "$hash[0]$hash[1]/$hash[2]$hash[3]" . '/' . $hash;
	}


	/**
	 * Creates the internal directory path from the given hash.
	 *
	 * Some special images like the "Image Not Available" image are stored
	 * in directories prefixed with an underscore. Those directories are not
	 * fragmented to hash based structure.
	 *
	 * @param  string $hash Tha SHA1 hash
	 * @return string
	 */
	private function createDirectoryPath($hash)
	{
		if ($hash{0} === '_') {
			return $hash;
		}

		return "$hash[0]$hash[1]/$hash[2]$hash[3]";
	}


	private function readMetaFromNetteImage(Image $image, Meta $meta)
    {
        $string = $image->toString();
        $info = getimagesizefromstring($string);
        $meta->setWidth($image->getWidth());
        $meta->setHeight($image->getHeight());
        $meta->setType($info[2]);
        $meta->setHash(sha1($string));
    }


	/**
	 * Computes the SHA1 hash for the given file.
	 *
	 * @param string $filename The file to compute the hash from
	 * @return string The SHA1 hash of a file
	 */
	private function hash($filename)
	{
		return sha1_file($filename);
	}


	/**
	 * Returns the image MIME type according to the given image type.
	 *
	 * @param integer $imageType Image type
	 * @return string
	 */
	protected function getMimeType($imageType)
	{
		return $this->mimeTypes[$imageType];
	}


	/**
	 * Returns the original stored image filename.
	 *
	 * @param Meta $meta Image meta information
	 * @return string
	 */
	public function file(Meta $meta)
	{
		return $this->createFilename($meta);
	}


	/**
	 * Returns the public accessible cache directory URL.
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->baseUrl;
	}


	/**
	 * Sets the public accessible cache directory URL.
	 *
	 * @param string $baseUrl
	 * @return $this
	 */
	protected function setBaseUrl($baseUrl)
	{
		if (!Strings::endsWith($baseUrl, '/')) {
			$baseUrl .= '/';
		}
		$this->baseUrl = $baseUrl;

		return $this;
	}


	public function rotate(Meta $meta, $deg = 90)
	{
		$original = $this->createFilename($meta);
		$backupName = $this->cacheDirectory . '/__rotate-backup';

		// Create rotated image
		copy($original, $backupName);

		exec(sprintf("convert -rotate $deg -strip %s %s", $original, $backupName));

		// Add new rotated image to storage
		$this->add(new ImageFile($backupName), $meta);

		unlink($backupName);

		return $this;
	}


	/**
	 * Adds an image from the given file to the storage.
	 *
	 * @param ImageFile $file The image file to add
	 * @param Meta      $meta
	 * @return boolean TRUE on success or FALSE on failure
	 */
	public function add(ImageFile $file, Meta $meta)
	{
		$this->readMeta($file->getName(), $meta);

		return copy($file->getName(), $this->createFilename($meta));
	}


}
