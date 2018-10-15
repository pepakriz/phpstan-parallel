<?php declare(strict_types = 1);

namespace Pepakriz\PHPParallel\ErrorData;

class Errors
{

	/**
	 * @param mixed[] $data
	 */
	public function __construct(array $data)
	{
		$this->data = $data;
	}

}
