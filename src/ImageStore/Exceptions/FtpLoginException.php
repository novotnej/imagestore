<?php

namespace Rostenkowski\ImageStore\Exceptions;


use InvalidArgumentException;

/**
 * Ftp login exception
 */
class FtpLoginException extends InvalidArgumentException
{


	/**
	 * @param string $message
	 */
	public function __construct($message)
	{
		parent::__construct($message);
	}

}
