<?php declare(strict_types = 1);

namespace ApiGenX\Renderer\Latte\Template;

use ApiGenX\Info\ClassLikeInfo;


final class ClassLikeTemplate
{
	public function __construct(
		public GlobalParameters $global,
		public ClassLikeInfo $classLike,
	) {
	}
}