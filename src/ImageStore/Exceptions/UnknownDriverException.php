<?php

namespace Rostenkowski\ImageStore\Exceptions;


use InvalidArgumentException;

/**
 * Unknown driver exception
 *
 * It's thrown when user attempts to use a driver that ImageStore does not support
 */
class UnknownDriverException extends InvalidArgumentException
{

    /**
     * UnknownDriverException constructor.
     * @param string $driver
     */
	public function __construct($driver)
	{
		parent::__construct(sprintf('Unknown driver: (%s)', var_export($driver, TRUE)));
	}

}
