<?php declare(strict_types = 1);

namespace ApiGenX\Info;

use function strrpos;
use function strtolower;
use function substr;


final class NamespaceNameInfo
{
	/** @var string e.g. 'ApiGenX\Info\Traits\HasName' */
	public string $full;

	/** @var string e.g. 'apigenx\info\traits\hasname' */
	public string $fullLower;

	/** @var string e.g. 'HasName' */
	public string $short;

	/** @var string e.g. 'hasname' */
	public string $shortLower;

	/** @var string e.g. 'ApiGenX\Info\Traits' */
	public string $namespace;

	/** @var string e.g. 'apigenx\info\traits' */
	public string $namespaceLower;


	public function __construct(string $full, ?string $fullLower = null)
	{
		$pos = strrpos($full, '\\');

		$this->full = $full;
		$this->fullLower = $fullLower ?? strtolower($full);

		$this->short = $pos === false ? $full : substr($full, $pos + 1);
		$this->shortLower = strtolower($this->short);

		$this->namespace = $pos === false ? '' : substr($full, 0, $pos);
		$this->namespaceLower = strtolower($this->namespace);
	}
}
