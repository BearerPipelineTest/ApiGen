<?php declare(strict_types = 1);

namespace ApiGenX\Info;

use ApiGenX\Index\Index;


final class InterfaceInfo extends ClassLikeInfo
{
	/** @var NameInfo[] indexed by [classLikeName] */
	public array $extends = [];


	public function __construct(NameInfo $name, bool $primary)
	{
		parent::__construct(
			$name,
			class: false,
			interface: true,
			trait: false,
			primary: $primary,
		);
	}


	/**
	 * @return iterable<InterfaceInfo>
	 */
	public function ancestors(Index $index): iterable
	{
		foreach ($this->extends as $extend) {
			if (isset($index->interface[$extend->fullLower])) { // TODO: missing guard
				$parent = $index->interface[$extend->fullLower];
				yield $parent;
				yield from $parent->ancestors($index);
			}
		}
	}
}
