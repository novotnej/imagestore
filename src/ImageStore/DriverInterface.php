<?php

namespace Rostenkowski\ImageStore;

use Nette\Utils\Image;


/**
 * Image Storage Interface
 */
interface DriverInterface
{
    /**
     * @param Image $image
     * @param Meta $meta
     */
    public function save(Image $image, Meta $meta);

    public function getStorageDriverName();

    public function fileExists(Meta $meta);

    public function link(Request $request);
}
